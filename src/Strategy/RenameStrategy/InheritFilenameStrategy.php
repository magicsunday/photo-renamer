<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy;

use MagicSunday\Renamer\Service\FileSystemService;
use Override;
use SplFileInfo;

/**
 * Base rename strategy that keeps the original filename intact, only stripping
 * any previously applied "-duplicate-NNN" suffix. Serves as the foundation for
 * derived strategies (LowerCase, Pattern, DatePattern) that transform the
 * cleaned filename further.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class InheritFilenameStrategy implements RenameStrategyInterface
{
    /**
     * Returns the original filename with any "-duplicate-NNN" suffix removed.
     * Preserves the original extension.
     *
     * @param SplFileInfo $splFileInfo Source file to derive the target name from
     *
     * @return string Cleaned filename with extension
     */
    #[Override]
    public function generateFilename(SplFileInfo $splFileInfo): string
    {
        $basename = $this->removeDuplicateFileIdentifier(
            $splFileInfo->getBasename('.' . $splFileInfo->getExtension())
        );

        if ($splFileInfo->getExtension() !== '') {
            return $basename . '.' . $splFileInfo->getExtension();
        }

        return $basename;
    }

    /**
     * Strips an existing "-duplicate-NNN" suffix (with exactly 3 digits) from
     * the basename. Used to ensure idempotent re-runs do not stack suffixes.
     *
     * @param string $filename Basename without extension
     *
     * @return string Basename with the duplicate suffix removed
     */
    protected function removeDuplicateFileIdentifier(string $filename): string
    {
        return preg_replace(
            '/' . FileSystemService::DUPLICATE_IDENTIFIER . '\d{3}/',
            '',
            $filename
        ) ?? $filename;
    }
}
