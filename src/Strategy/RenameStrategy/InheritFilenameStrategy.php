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
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class InheritFilenameStrategy implements RenameStrategyInterface
{
    /**
     * Create a new filename based on the original filename, only with the duplicate identifier removed.
     *
     * @param SplFileInfo $splFileInfo
     *
     * @return string
     */
    #[Override]
    public function generateFilename(SplFileInfo $splFileInfo): string
    {
        $basename = $this->removeDuplicateFileIdentifier(
            $splFileInfo->getBasename('.' . $splFileInfo->getExtension())
        );

        return $basename . '.' . $splFileInfo->getExtension();
    }

    /**
     * Remove any existing "-duplicate-000" identifier.
     *
     * @param string $filename
     *
     * @return string
     */
    protected function removeDuplicateFileIdentifier(string $filename): string
    {
        return preg_replace(
            '/' . FileSystemService::DUPLICATE_IDENTIFIER . '\d{3}$/',
            '',
            $filename
        ) ?? $filename;
    }
}
