<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Metadata;

use SplFileInfo;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

use function is_array;
use function json_decode;
use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

/**
 * Persistent disk cache for metadata extraction results. Keyed by file pathname,
 * entries are invalidated when a file's mtime or size changes. Uses PHP's native
 * JSON-based serialization for portable and safe disk storage.
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
     * @var array<string, MetadataCacheEntry> A map of file pathnames to their respective typed cache entries.
     */
    private array $entries = [];

    /**
     * Whether the cache has been modified since last load/flush.
     */
    private bool $dirty = false;

    /**
     * Symfony Filesystem component for disk operations.
     */
    private readonly Filesystem $filesystem;

    /**
     * @param string     $cacheFile  Absolute path to the JSON cache file on disk. The file
     *                               does not need to exist yet; it will be created on flush().
     * @param Filesystem $filesystem Symfony Filesystem component for disk operations.
     */
    public function __construct(
        private readonly string $cacheFile,
        ?Filesystem $filesystem = null,
    ) {
        $this->filesystem = $filesystem ?? new Filesystem();
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
     * operations via Symfony's dumpFile() to prevent corruption during concurrent
     * writes or process interruptions.
     *
     * Entries are stored as a single JSON object, where keys are absolute file
     * pathnames and values are flat metadata arrays.
     */
    public function flush(): void
    {
        if (!$this->dirty) {
            return;
        }

        $serializedEntries = [];

        foreach ($this->entries as $pathname => $entry) {
            $serializedEntries[$pathname] = $entry->toArray();
        }

        $this->filesystem->dumpFile(
            $this->cacheFile,
            json_encode($serializedEntries, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        );

        $this->dirty = false;
    }

    /**
     * Loads the cache from disk into memory.
     *
     * If the cache file does not exist, an empty index is initialized. If the
     * file is not readable due to permission issues, the cache remains empty,
     * essentially falling back to a "cold cache" state without interrupting
     * the application flow.
     *
     * Decodes the JSON content and reconstructs typed cache entries. Legacy JSON
     * payloads remain supported through MetadataCacheEntry::fromArray().
     */
    private function load(): void
    {
        if (!$this->filesystem->exists($this->cacheFile)) {
            return;
        }

        try {
            $contents = $this->filesystem->readFile($this->cacheFile);
        } catch (IOException) {
            return;
        }

        $data = json_decode($contents, true);

        if (is_array($data)) {
            /** @var array<string, mixed> $data */
            foreach ($data as $pathname => $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                /** @var array<string, mixed> $entry */
                $this->entries[$pathname] = MetadataCacheEntry::fromArray($entry);
            }
        }
    }
}
