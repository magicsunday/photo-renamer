<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\LivePhoto;

use MagicSunday\Renamer\Helper\FileHelper;
use SplFileInfo;

use function array_key_exists;
use function strtolower;
use function trim;

/**
 * Maps normalized source basenames to their canonical Live Photo targets.
 * When multiple groups share the same basename (ambiguity), the entry is
 * invalidated to prevent incorrect companion matching. Serves as the fallback
 * lookup when content-identifier-based matching is not available.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class LivePhotoBasenameTargetMap
{
    /**
     * Unambiguous basename-to-target mappings.
     *
     * @var array<string, LivePhotoContentIdentifierTarget>
     */
    private array $targets = [];

    /**
     * Basenames that mapped to multiple different duplicate identifiers and are
     * therefore excluded from matching.
     *
     * @var array<string, true>
     */
    private array $ambiguous = [];

    /**
     * Remembers the canonical target for the provided source basename.
     *
     * @param SplFileInfo $source              asset belonging to the Live Photo group
     * @param SplFileInfo $target              canonical target generated for the group
     * @param string      $duplicateIdentifier identifier that represents the Live Photo group
     */
    public function remember(SplFileInfo $source, SplFileInfo $target, string $duplicateIdentifier): void
    {
        $basenameKey = $this->normalizeBasename($source);

        if ($basenameKey === null) {
            return;
        }

        if (array_key_exists($basenameKey, $this->ambiguous)) {
            return;
        }

        if (array_key_exists($basenameKey, $this->targets)) {
            $existing = $this->targets[$basenameKey];

            if ($existing->getDuplicateIdentifier() === $duplicateIdentifier) {
                return;
            }

            unset($this->targets[$basenameKey]);
            $this->ambiguous[$basenameKey] = true;

            return;
        }

        $this->targets[$basenameKey] = new LivePhotoContentIdentifierTarget($target, $duplicateIdentifier);
    }

    /**
     * Resolves the stored target for the provided file using its basename.
     */
    public function match(SplFileInfo $file): ?LivePhotoContentIdentifierTarget
    {
        $basenameKey = $this->normalizeBasename($file);

        if ($basenameKey === null) {
            return null;
        }

        if (array_key_exists($basenameKey, $this->ambiguous)) {
            return null;
        }

        return $this->targets[$basenameKey] ?? null;
    }

    /**
     * Returns the normalized basename key that would be used to look up
     * the given file. Useful for building secondary indexes keyed by basename.
     *
     * @param SplFileInfo $file File to normalize
     *
     * @return string|null Lowercased, trimmed basename without extension, or null when empty
     */
    public function getBasenameKey(SplFileInfo $file): ?string
    {
        return $this->normalizeBasename($file);
    }

    /**
     * Lowercases and trims the file's basename (without extension) to produce
     * a case-insensitive lookup key. Returns null for empty basenames.
     */
    private function normalizeBasename(SplFileInfo $file): ?string
    {
        $basename   = FileHelper::basenameWithoutExtension($file);
        $normalized = strtolower(trim($basename));

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }
}
