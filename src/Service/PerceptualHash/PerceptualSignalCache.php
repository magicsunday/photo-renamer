<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\PerceptualHash;

use SplFileInfo;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

use function is_array;
use function json_decode;
use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

/**
 * Persistent disk cache for perceptual hash signals. Keyed by file pathname,
 * entries are invalidated when a file's mtime or size changes.
 *
 * Caches dHash, wHash, HF-energy, and color histogram per file so that
 * subsequent runs (e.g. dry-run followed by real run) skip Imagick entirely
 * for previously seen files.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class PerceptualSignalCache
{
    /**
     * Bump this when the signal computation changes (e.g. Imagick normalization,
     * multi-frame video, scoring weights). Mismatched versions discard the cache.
     */
    private const int CACHE_VERSION = 2;

    /**
     * @var array<string, array{
     *     mtime: int,
     *     size: int,
     *     dhash: string|null,
     *     whash: string|null,
     *     hf: float|null,
     *     hist: list<float>|null
     * }>
     */
    private array $entries = [];

    private bool $dirty = false;

    public function __construct(
        private readonly string $cacheFile,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        $this->load();
    }

    /**
     * Returns cached signals for the file, or null on cache miss.
     *
     * @return array{dhash: string|null, whash: string|null, hf: float|null, hist: list<float>|null}|null
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

        return [
            'dhash' => $entry['dhash'],
            'whash' => $entry['whash'],
            'hf'    => $entry['hf'],
            'hist'  => $entry['hist'],
        ];
    }

    /**
     * Stores perceptual signals for the file in the cache.
     *
     * @param array{dhash: string|null, whash: string|null, hf: float|null, hist: list<float>|null} $signals
     */
    public function set(SplFileInfo $file, array $signals): void
    {
        $this->entries[$file->getPathname()] = [
            'mtime' => $file->getMTime(),
            'size'  => $file->getSize(),
            'dhash' => $signals['dhash'],
            'whash' => $signals['whash'],
            'hf'    => $signals['hf'],
            'hist'  => $signals['hist'],
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
            json_encode(
                ['_version' => self::CACHE_VERSION, 'entries' => $this->entries],
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
            ),
        );

        $this->dirty = false;
    }

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

        if (!is_array($data)) {
            return;
        }

        // Discard cache if version mismatch (signal computation changed)
        if (($data['_version'] ?? 0) !== self::CACHE_VERSION) {
            $this->dirty = true;

            return;
        }

        if (is_array($data['entries'] ?? null)) {
            /** @var array<string, array{mtime: int, size: int, dhash: string|null, whash: string|null, hf: float|null, hist: list<float>|null}> $entries */
            $entries       = $data['entries'];
            $this->entries = $entries;
        }
    }
}
