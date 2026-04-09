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
     * @param TargetPathResolver $targetPathResolver Shared target-path resolver reused by the legacy pipeline wrapper.
     */
    public function __construct(
        private TargetPathResolver $targetPathResolver,
    ) {
    }

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
        return $this->targetPathResolver->resolve(
            $sourceDirectory,
            $sourceFileInfo,
            $targetFilename,
        );
    }
}
