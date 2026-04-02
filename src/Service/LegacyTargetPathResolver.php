<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use RuntimeException;
use SplFileInfo;

use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * Resolves absolute target pathnames for the legacy duplicate pipeline.
 *
 * The legacy commands keep the source directory structure intact while replacing
 * only the filename component. This resolver owns the corresponding path logic
 * and validates that generated filenames cannot smuggle directory separators into
 * the destination path.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LegacyTargetPathResolver
{
    /**
     * Builds the target pathname for a source file and generated filename.
     *
     * @param string      $sourceDirectory Absolute source root used as the legacy destination base.
     * @param SplFileInfo $sourceFileInfo  Source file for which the target path should be computed.
     * @param string      $targetFilename  Filename (without directory) to use in the destination.
     *
     * @return string Absolute pathname pointing to the intended target location.
     */
    public function resolve(string $sourceDirectory, SplFileInfo $sourceFileInfo, string $targetFilename): string
    {
        if (str_contains($targetFilename, DIRECTORY_SEPARATOR) || str_contains($targetFilename, '/')) {
            throw new RuntimeException(
                sprintf('Target filename "%s" must not contain directory separators', $targetFilename)
            );
        }

        $sourcePath   = $sourceFileInfo->getPath();
        $relativePath = $sourcePath;

        if (str_starts_with($sourcePath, $sourceDirectory)) {
            $relativePath = substr($sourcePath, strlen($sourceDirectory));
        }

        $relativePath = trim($relativePath, DIRECTORY_SEPARATOR);

        $targetPath = rtrim($sourceDirectory, DIRECTORY_SEPARATOR);

        if ($relativePath !== '') {
            $targetPath .= DIRECTORY_SEPARATOR . $relativePath;
        }

        return $targetPath . DIRECTORY_SEPARATOR . $targetFilename;
    }
}
