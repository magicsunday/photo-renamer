<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Metadata\TemporalMetadata;
use SplFileInfo;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

use function is_array;
use function json_decode;
use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
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
     * @var array<string, array{
     *     mtime: int,
     *     size: int,
     *     captureDateTime: string|null,
     *     contentId: string|null,
     *     isFallback: bool,
     *     isAmbiguousTimezone: bool,
     *     livePhotoVideoIndex?: int|null,
     *     cameraMake?: string|null,
     *     cameraModel?: string|null,
     *     software?: string|null,
     *     latitude?: float|null,
     *     longitude?: float|null,
     *     videoDurationSeconds?: float|null,
     *     hasQuickTimeLivePhotoMarker?: bool
     * }>
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
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        $this->load();
    }

    /**
     * Returns cached temporal metadata for the file, or null on cache miss.
     * A cache miss occurs when:
     * - The file is not in the cache
     * - The file's mtime or size has changed.
     *
     * @return array{
     *     mtime: int,
     *     size: int,
     *     captureDateTime: string|null,
     *     contentId: string|null,
     *     isFallback: bool,
     *     isAmbiguousTimezone: bool,
     *     livePhotoVideoIndex?: int|null,
     *     cameraMake?: string|null,
     *     cameraModel?: string|null,
     *     software?: string|null,
     *     latitude?: float|null,
     *     longitude?: float|null,
     *     videoDurationSeconds?: float|null,
     *     hasQuickTimeLivePhotoMarker?: bool
     * }|null
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
    public function set(SplFileInfo $file, ?TemporalMetadata $metadata): void
    {
        $this->entries[$file->getPathname()] = [
            'mtime'                       => $file->getMTime(),
            'size'                        => $file->getSize(),
            'captureDateTime'             => $metadata?->getCaptureDateTime()?->format('Y-m-d\TH:i:s.uP'),
            'contentId'                   => $metadata?->getLivePhotoId(),
            'isFallback'                  => $metadata?->isFallbackDateTime() ?? false,
            'isAmbiguousTimezone'         => $metadata?->isAmbiguousTimezone() ?? false,
            'livePhotoVideoIndex'         => $metadata?->getLivePhotoVideoIndex(),
            'cameraMake'                  => $metadata?->getCameraMake(),
            'cameraModel'                 => $metadata?->getCameraModel(),
            'software'                    => $metadata?->getSoftware(),
            'latitude'                    => $metadata?->getLatitude(),
            'longitude'                   => $metadata?->getLongitude(),
            'videoDurationSeconds'        => $metadata?->getVideoDurationSeconds(),
            'hasQuickTimeLivePhotoMarker' => $metadata?->hasQuickTimeLivePhotoMarker() ?? false,
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

        $this->filesystem->dumpFile(
            $this->cacheFile,
            json_encode($this->entries, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE),
        );

        $this->dirty = false;
    }

    /**
     * Loads the cache from disk. Silently ignores missing or corrupt cache files.
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
            /** @var array<string, array{
             *     mtime: int,
             *     size: int,
             *     captureDateTime: string|null,
             *     contentId: string|null,
             *     isFallback: bool,
             *     isAmbiguousTimezone: bool,
             *     livePhotoVideoIndex?: int|null,
             *     cameraMake?: string|null,
             *     cameraModel?: string|null,
             *     software?: string|null,
             *     latitude?: float|null,
             *     longitude?: float|null,
             *     videoDurationSeconds?: float|null,
             *     hasQuickTimeLivePhotoMarker?: bool
             * }> $data */
            $this->entries = $data;
        }
    }
}
