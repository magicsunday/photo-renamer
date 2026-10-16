<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Metadata;

use Composer\InstalledVersions;
use Generator;
use JsonException;
use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use SplFileInfo;

use function array_key_exists;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function strlen;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

/**
 * Persistent disk cache for metadata extraction results. Keyed by file pathname,
 * entries are invalidated when a file's mtime or size changes. Uses PHP's native
 * JSON-based serialization for portable and safe disk storage. A schema/meaning
 * version and the installed parser revision invalidate incompatible snapshots.
 *
 * The cache is loaded eagerly on construction and flushed explicitly via flush().
 * Only writes to disk when entries have been added or invalidated (dirty tracking).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class MetadataCache
{
    /**
     * Bump for incompatible metadata interpretation or serialization changes.
     * Parser revisions are tracked separately so dependency updates also miss.
     */
    private const int CACHE_VERSION = 2;

    /**
     * Latest cold-cache diagnostic, without sensitive media paths.
     */
    private ?string $diagnostic = null;

    /**
     * @var array<string, MetadataCacheEntry> A map of file pathnames to their respective typed cache entries.
     */
    private array $entries = [];

    /**
     * Whether the cache has been modified since last load/flush.
     */
    private bool $dirty = false;

    /**
     * @param string              $cacheFile Absolute path to the JSON cache file on disk. The file
     *                                       does not need to exist yet; it will be created on flush().
     * @param PrivateCacheStorage $storage   Private atomic cache storage boundary
     */
    public function __construct(
        private readonly string $cacheFile,
        private readonly PrivateCacheStorage $storage,
    ) {
        $this->load();
    }

    /**
     * Retrieves cached metadata for the given file, validating freshness against
     * the current file system state.
     *
     * A cache miss occurs when:
     * - The file is not yet indexed in the cache.
     * - The file's modification time (mtime) or byte size has changed, indicating
     *   that previously extracted metadata might no longer be accurate.
     *
     * In case of a stale entry (mtime/size mismatch), the entry is immediately
     * evicted from the in-memory store and the cache is marked as dirty.
     *
     * @param SplFileInfo $file The file to retrieve metadata for.
     *
     * @return MetadataCacheEntry|null Returns the typed cache entry or null if no fresh entry exists.
     */
    public function get(SplFileInfo $file): ?MetadataCacheEntry
    {
        $key = $file->getPathname();

        if (!isset($this->entries[$key])) {
            return null;
        }

        $entry = $this->entries[$key];

        if (($entry->getMtime() !== $file->getMTime()) || ($entry->getSize() !== $file->getSize())) {
            unset($this->entries[$key]);
            $this->dirty = true;

            return null;
        }

        return $entry;
    }

    /**
     * Indexes the provided metadata for a file, associating it with its current
     * mtime and size for future validation.
     *
     * If $metadata is null, a minimal entry is stored (useful for marking files
     * that were processed but yielded no metadata). The metadata object is
     * flattened into a serializable array format, formatting dates as ISO-8601
     * strings with microseconds and timezone offsets.
     *
     * Marks the cache as dirty, triggering a write on the next flush() call.
     *
     * @param SplFileInfo           $file     The file for which metadata is stored.
     * @param TemporalMetadata|null $metadata The extracted metadata or null if none found.
     */
    public function set(SplFileInfo $file, ?TemporalMetadata $metadata): void
    {
        $this->entries[$file->getPathname()] = MetadataCacheEntry::fromFileAndMetadata($file, $metadata);

        $this->dirty = true;
    }

    /**
     * Persists the in-memory cache to the configured disk file.
     *
     * To optimize performance, writes are only performed if the "dirty" flag is
     * set (i.e., entries were added, updated, or evicted). Uses atomic file
     * replacement via PrivateCacheStorage to prevent partial JSON during process
     * interruptions. Atomic replacement does not merge concurrent writers.
     *
     * Entries are stored under a versioned envelope. Last snapshot wins for
     * overlapping writers; no read-modify-write merge is promised.
     */
    public function flush(): void
    {
        if (!$this->dirty) {
            return;
        }

        $this->storage->write($this->cacheFile, $this->serializedChunks());

        $this->dirty = false;
    }

    /**
     * Emits the existing JSON envelope without duplicating all typed rows as arrays.
     * The atomic storage boundary publishes only after the entire stream succeeds.
     *
     * @return Generator<int, string> JSON chunks, buffered to roughly 64 KiB
     */
    private function serializedChunks(): Generator
    {
        $flags = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

        if ($this->entries === []) {
            yield json_encode(['_version' => self::CACHE_VERSION, '_parser' => $this->parserVersion(), 'entries' => []], $flags);

            return;
        }

        yield '{"_version":' . self::CACHE_VERSION . ',"_parser":' . json_encode($this->parserVersion(), $flags) . ',"entries":{';
        $chunk     = '';
        $separator = '';

        foreach ($this->entries as $pathname => $entry) {
            $chunk .= $separator . json_encode($pathname, $flags) . ':' . json_encode($entry->toArray(), $flags);
            $separator = ',';

            if (strlen($chunk) >= 65536) {
                yield $chunk;
                $chunk = '';
            }
        }

        yield $chunk . '}}';
    }

    /**
     * Returns the latest version/format rejection for an observable cold cache.
     *
     * @return string|null Safe reason when a persisted snapshot was discarded
     */
    public function getDiagnostic(): ?string
    {
        return $this->diagnostic;
    }

    /**
     * Explicitly evicts paths no longer visible as regular files. This affects
     * only cache work, never media; inaccessible mounts can cause conservative
     * cache misses too, so callers must opt in rather than prune on every run.
     *
     * @return int Number of removed cache entries
     */
    public function pruneMissing(): int
    {
        $removed = 0;

        foreach ($this->entries as $pathname => $entry) {
            if (new SplFileInfo($pathname)->isFile()) {
                continue;
            }

            unset($this->entries[$pathname]);
            ++$removed;
            $this->dirty = true;
        }

        return $removed;
    }

    /**
     * Loads the cache from disk into memory.
     *
     * If the cache file does not exist, an empty index is initialized.
     * An ordinary read failure starts a cold cache. Unsafe ownership, links or
     * an inability to establish private permissions fail explicitly.
     *
     * Rejects corrupt, legacy and incompatible envelopes as a diagnosable cold
     * cache. Privacy boundary failures remain explicit rather than suppressed.
     */
    private function load(): void
    {
        $contents = $this->storage->read($this->cacheFile);

        if ($contents === null) {
            if (new SplFileInfo($this->cacheFile)->isFile()) {
                $this->diagnostic = 'Metadata cache could not be read; starting cold.';
            }

            return;
        }

        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->diagnostic = 'Metadata cache contains invalid JSON; starting cold.';

            return;
        }

        if (!is_array($data)
            || (($data['_version'] ?? null) !== self::CACHE_VERSION)
            || (($data['_parser'] ?? null) !== $this->parserVersion())
            || !is_array($data['entries'] ?? null)
        ) {
            $this->diagnostic = 'Metadata cache format or parser version changed; starting cold.';

            return;
        }

        foreach ($data['entries'] as $pathname => $entry) {
            if (!is_string($pathname) || !is_array($entry)
                || !is_int($entry['mtime'] ?? null) || !is_int($entry['size'] ?? null)
            ) {
                $this->entries    = [];
                $this->diagnostic = 'Metadata cache contains invalid entries; starting cold.';

                return;
            }

            /** @var array<string, mixed> $entry */
            $typedEntry = MetadataCacheEntry::fromArray($entry);

            // Current-version rows must satisfy their actual serialization
            // contract. Permit JSON's integer encoding of floats, but reject
            // missing fields, invalid dates and lossy scalar coercions.
            foreach ($typedEntry->toArray() as $field => $expected) {
                $actual = $entry[$field] ?? null;
                $valid  = is_float($expected)
                    ? ((is_int($actual) || is_float($actual)) && ((float) $actual === $expected))
                    : $actual === $expected;

                if (!array_key_exists($field, $entry) || !$valid) {
                    $this->entries    = [];
                    $this->diagnostic = 'Metadata cache contains invalid values; starting cold.';

                    return;
                }
            }

            $this->entries[$pathname] = $typedEntry;
        }
    }

    /**
     * Tracks the actually installed parser revision, including dev-main updates.
     * The interpretation version above covers application-side rule changes.
     *
     * @return string Installed imagemeta reference or its declared version fallback
     */
    private function parserVersion(): string
    {
        return InstalledVersions::getReference('magicsunday/imagemeta')
            ?? InstalledVersions::getPrettyVersion('magicsunday/imagemeta')
            ?? 'unknown';
    }
}
