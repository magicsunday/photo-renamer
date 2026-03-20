<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use SplFileInfo;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;

use const JSON_PRETTY_PRINT;
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
     * @var array<string, array{mtime: int, size: int, captureDateTime: string|null, contentId: string|null, isFallback: bool, isAmbiguousTimezone: bool}>
     */
    private array $entries = [];

    /**
     * Whether the cache has been modified since last load/flush.
     */
    private bool $dirty = false;

    /**
     * @param string $cacheFile Absolute path to the cache file on disk
     */
    public function __construct(
        private readonly string $cacheFile,
    ) {
        $this->load();
    }

    /**
     * Returns cached temporal metadata for the file, or null on cache miss.
     * A cache miss occurs when:
     * - The file is not in the cache
     * - The file's mtime or size has changed.
     *
     * @return array{mtime: int, size: int, captureDateTime: string|null, contentId: string|null, isFallback: bool, isAmbiguousTimezone: bool}|null
     */
    public function get(SplFileInfo $file): ?array
    {
        $key = $file->getPathname();

        if (!isset($this->entries[$key])) {
            return null;
        }

        $entry = $this->entries[$key];

        if (($entry['mtime'] !== $file->getMTime()) || ($entry['size'] !== $file->getSize())) {
            unset($this->entries[$key]);
            $this->dirty = true;

            return null;
        }

        return $entry;
    }

    /**
     * Stores metadata for the file in the cache.
     */
    public function set(
        SplFileInfo $file,
        ?string $captureDateTime,
        ?string $contentId,
        bool $isFallback,
        bool $isAmbiguousTimezone = false,
    ): void {
        $this->entries[$file->getPathname()] = [
            'mtime'               => $file->getMTime(),
            'size'                => $file->getSize(),
            'captureDateTime'     => $captureDateTime,
            'contentId'           => $contentId,
            'isFallback'          => $isFallback,
            'isAmbiguousTimezone' => $isAmbiguousTimezone,
        ];

        $this->dirty = true;
    }

    /**
     * Persists the cache to disk if any entries were added or invalidated.
     */
    public function flush(): void
    {
        if (!$this->dirty) {
            return;
        }

        $dir = dirname($this->cacheFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents(
            $this->cacheFile,
            json_encode($this->entries, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        $this->dirty = false;
    }

    /**
     * Loads the cache from disk. Silently ignores missing or corrupt cache files.
     */
    private function load(): void
    {
        if (!is_file($this->cacheFile)) {
            return;
        }

        $contents = @file_get_contents($this->cacheFile);

        if ($contents === false) {
            return;
        }

        $data = json_decode($contents, true);

        if (is_array($data)) {
            /** @var array<string, array{mtime: int, size: int, captureDateTime: string|null, contentId: string|null, isFallback: bool, isAmbiguousTimezone: bool}> $data */
            $this->entries = $data;
        }
    }
}
