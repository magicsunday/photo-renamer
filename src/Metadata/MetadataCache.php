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
use RuntimeException;
use SplFileInfo;
use SplFileObject;

use function array_combine;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_values;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

/**
 * Persistent disk cache for metadata extraction results. Keyed by file pathname,
 * entries are invalidated when a file's mtime or size changes. Uses PHP's native
 * JSON-based serialization for portable and safe disk storage. A schema/meaning
 * version and the installed parser revision invalidate incompatible snapshots.
 *
 * The cache streams and validates rows on construction; metadata objects are hydrated on lookup. It is flushed explicitly via flush().
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
     * @var array<string, MetadataCacheEntry|string> Fresh typed entries or validated positional JSON rows from disk.
     */
    private array $entries = [];

    /**
     * Field order from the entry's canonical serializer, shared by compact rows.
     * Named keys remain in the disk format, rather than repeated in every string.
     *
     * @var list<string>
     */
    private array $rowFields = [];

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

        if (is_string($entry)) {
            $entry = MetadataCacheEntry::fromArray($this->decodeRow($entry));
        }

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

        yield '{"_version":' . self::CACHE_VERSION . ',"_parser":' . json_encode($this->parserVersion(), $flags) . ',"entries":{' . "\n";
        $chunk     = '';
        $separator = '';

        foreach ($this->entries as $pathname => $entry) {
            $row = json_encode(is_string($entry) ? $this->decodeRow($entry) : $entry->toArray(), $flags);
            $chunk .= $separator . json_encode($pathname, $flags) . ':' . $row . "\n";
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
        $input = $this->storage->openRead($this->cacheFile);

        if (!$input instanceof SplFileObject) {
            if (new SplFileInfo($this->cacheFile)->isFile()) {
                $this->diagnostic = 'Metadata cache could not be read; starting cold.';
            }

            return;
        }

        try {
            // Bound a single cache row too; oversized legacy layouts become a
            // one-time cold cache instead of requiring collection-sized decoding.
            $input->setMaxLineLen(65537);
            $header = $input->fgets();

            if (!str_ends_with(rtrim($header, "\r\n"), ',"entries":{') || !str_ends_with($header, "\n")) {
                $contents = $header;

                while (!$input->eof() && (strlen($contents) <= 65536)) {
                    $contents .= $input->fgets();
                }

                if (strlen($contents) > 65536) {
                    $this->diagnostic = 'Metadata cache uses an unbounded legacy layout; starting cold.';

                    return;
                }

                $this->loadSmallSnapshot($contents);

                return;
            }

            $envelope = json_decode(rtrim($header, "\r\n") . '}}', true, flags: JSON_THROW_ON_ERROR);

            if (!is_array($envelope) || !$this->compatible($envelope)) {
                $this->diagnostic = 'Metadata cache format or parser version changed; starting cold.';

                return;
            }

            $first = true;

            while (!$input->eof()) {
                $line = $input->fgets();

                if (rtrim($line, " \t\r\n") === '}}') {
                    while (!$input->eof()) {
                        if (rtrim($input->fgets(), " \t\r\n") !== '') {
                            throw new RuntimeException('Trailing cache bytes.');
                        }
                    }

                    return;
                }

                if (!str_ends_with($line, "\n") || (!$first && !str_starts_with($line, ','))) {
                    throw new RuntimeException('Incomplete cache row.');
                }

                $row = json_decode('{' . ($first ? $line : substr($line, 1)) . '}', true, flags: JSON_THROW_ON_ERROR);

                if (!is_array($row) || (count($row) !== 1)) {
                    throw new RuntimeException('Invalid cache row.');
                }

                $pathname = array_key_first($row);
                $entry    = $row[$pathname];

                if (!is_string($pathname) || !is_array($entry) || !$this->acceptRow($pathname, $entry)) {
                    throw new RuntimeException('Invalid cache values.');
                }

                $first = false;
            }

            throw new RuntimeException('Truncated cache envelope.');
        } catch (JsonException|RuntimeException) {
            $this->entries    = [];
            $this->diagnostic = 'Metadata cache contains invalid data; starting cold.';
        }
    }

    /**
     * Preserves compatibility for small hand-written or previous compact snapshots.
     * Larger previous layouts are re-extracted once and rewritten as bounded rows.
     */
    private function loadSmallSnapshot(string $contents): void
    {
        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($data) || !$this->compatible($data)) {
            $this->diagnostic = 'Metadata cache format or parser version changed; starting cold.';

            return;
        }

        foreach ($data['entries'] as $pathname => $entry) {
            if (!is_string($pathname) || !is_array($entry) || !$this->acceptRow($pathname, $entry)) {
                throw new RuntimeException('Invalid legacy cache values.');
            }
        }
    }

    /**
     * Checks the versioned JSON envelope before trusting any cached row.
     *
     * @param array<array-key, mixed> $data Decoded JSON boundary
     *
     * @phpstan-assert-if-true array{_version: int, _parser: string, entries: array<array-key, mixed>} $data
     */
    private function compatible(array $data): bool
    {
        return (($data['_version'] ?? null) === self::CACHE_VERSION)
            && (($data['_parser'] ?? null) === $this->parserVersion())
            && is_array($data['entries'] ?? null);
    }

    /**
     * Validates the existing row semantics, retaining bytes instead of hydrating
     * dates and metadata for every historical path. Lookup hydrates one row.
     *
     * @param array<array-key, mixed> $row Decoded untrusted cache row
     */
    private function acceptRow(string $pathname, array $row): bool
    {
        if (!is_int($row['mtime'] ?? null) || !is_int($row['size'] ?? null)) {
            return false;
        }

        /** @var array<string, mixed> $row */
        $entry  = MetadataCacheEntry::fromArray($row);
        $values = $entry->toArray();

        foreach ($values as $field => $expected) {
            $actual = $row[$field] ?? null;
            $valid  = is_float($expected)
                ? ((is_int($actual) || is_float($actual)) && ((float) $actual === $expected))
                : $actual === $expected;

            if (!array_key_exists($field, $row) || !$valid) {
                return false;
            }
        }

        if ($this->rowFields === []) {
            $this->rowFields = array_keys($values);
        }

        $this->entries[$pathname] = json_encode(array_values($values), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        return true;
    }

    /**
     * Restores keys shared by validated in-memory rows. The order comes directly
     * from MetadataCacheEntry::toArray(), with no second schema to maintain.
     *
     * @return array<string, mixed> Existing named serialization boundary
     */
    private function decodeRow(string $json): array
    {
        /** @var list<mixed> $values Validated and encoded by acceptRow() */
        $values = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return array_combine($this->rowFields, $values);
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
