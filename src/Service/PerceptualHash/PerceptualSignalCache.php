<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\PerceptualHash;

use JsonException;
use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use SplFileInfo;

use function array_is_list;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
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
     * Safe reason when the previous persisted snapshot cannot be reused.
     */
    private ?string $diagnostic = null;

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
     * }> A map of file pathnames to their respective cached perceptual signals.
     */
    private array $entries = [];

    /**
     * Whether the cache has been modified since last load/flush.
     */
    private bool $dirty = false;

    /**
     * @param string              $cacheFile The full path to the JSON cache file. The file
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
     * Exposes a cold-cache reason without including sensitive media pathnames.
     *
     * @return string|null Diagnostic for a corrupt or incompatible snapshot
     */
    public function getDiagnostic(): ?string
    {
        return $this->diagnostic;
    }

    /**
     * Explicitly removes paths no longer visible as regular files. Call only
     * with the expected collection mounted; inaccessible paths cause harmless
     * cache misses, not media deletion, and pruning is never automatic.
     *
     * @return int Number of removed entries
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
     * Returns cached signals for the given file, or null on cache miss.
     *
     * Invalidation occurs automatically if the file's modification time (mtime)
     * or size has changed since the cache entry was created.
     *
     * @param SplFileInfo $file The file to retrieve signals for.
     *
     * @return array{dhash: string|null, whash: string|null, hf: float|null, hist: list<float>|null}|null The cached signals or null.
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
     * The cache entry includes the file's current mtime and size for future
     * validation. Setting a value marks the cache as "dirty", requiring a flush.
     *
     * @param SplFileInfo                                                                           $file    The file these signals belong to.
     * @param array{dhash: string|null, whash: string|null, hf: float|null, hist: list<float>|null} $signals The computed signals.
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
     *
     * Uses atomic file dump to ensure the JSON cache remains consistent
     * even if the process is interrupted. Overlapping writers replace complete
     * snapshots; the last writer wins without a read-modify-write merge.
     */
    public function flush(): void
    {
        if (!$this->dirty) {
            return;
        }

        $this->storage->write(
            $this->cacheFile,
            json_encode(
                ['_version' => self::CACHE_VERSION, 'entries' => $this->entries],
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
            ),
        );

        $this->dirty = false;
    }

    /**
     * Loads the cache from the JSON file on disk.
     *
     * If the cache version on disk does not match the current CACHE_VERSION,
     * the existing cache is discarded to ensure signal compatibility.
     */
    private function load(): void
    {
        $contents = $this->storage->read($this->cacheFile);

        if ($contents === null) {
            if (new SplFileInfo($this->cacheFile)->isFile()) {
                $this->diagnostic = 'Perceptual cache could not be read; starting cold.';
            }

            return;
        }

        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->diagnostic = 'Perceptual cache contains invalid JSON; starting cold.';

            return;
        }

        if (!is_array($data)) {
            $this->diagnostic = 'Perceptual cache format is invalid; starting cold.';

            return;
        }

        // Discard cache if version mismatch (signal computation changed)
        if (($data['_version'] ?? 0) !== self::CACHE_VERSION) {
            $this->dirty      = true;
            $this->diagnostic = 'Perceptual cache version changed; starting cold.';

            return;
        }

        if (!is_array($data['entries'] ?? null)) {
            $this->diagnostic = 'Perceptual cache entries are invalid; starting cold.';

            return;
        }

        foreach ($data['entries'] as $pathname => $entry) {
            if (!is_string($pathname) || !is_array($entry)
                || !is_int($entry['mtime'] ?? null) || !is_int($entry['size'] ?? null)
            ) {
                $this->entries    = [];
                $this->diagnostic = 'Perceptual cache entries are invalid; starting cold.';

                return;
            }

            $dhash = $entry['dhash'] ?? null;
            $whash = $entry['whash'] ?? null;
            $hf    = $entry['hf'] ?? null;
            $hist  = $entry['hist'] ?? null;

            if ((!is_string($dhash) && ($dhash !== null))
                || (!is_string($whash) && ($whash !== null))
                || (!is_float($hf) && !is_int($hf) && ($hf !== null))
                || (($hist !== null) && (!is_array($hist) || !array_is_list($hist)))
            ) {
                $this->entries    = [];
                $this->diagnostic = 'Perceptual cache signals are invalid; starting cold.';

                return;
            }

            $normalizedHistogram = null;

            if (is_array($hist)) {
                $normalizedHistogram = [];

                foreach ($hist as $value) {
                    if (!is_float($value) && !is_int($value)) {
                        $this->entries    = [];
                        $this->diagnostic = 'Perceptual cache histogram is invalid; starting cold.';

                        return;
                    }

                    $normalizedHistogram[] = (float) $value;
                }
            }

            $this->entries[$pathname] = [
                'mtime' => $entry['mtime'],
                'size'  => $entry['size'],
                'dhash' => $dhash,
                'whash' => $whash,
                'hf'    => $hf !== null ? (float) $hf : null,
                'hist'  => $normalizedHistogram,
            ];
        }
    }
}
