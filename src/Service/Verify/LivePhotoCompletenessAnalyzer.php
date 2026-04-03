<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Verify;

use MagicSunday\Renamer\Helper\PathHelper;

/**
 * Derives missing Live Photo companion findings from grouped content identifiers.
 *
 * Verify mode first groups files by directory and Apple Content Identifier. This
 * analyzer turns that grouped observation model into actionable findings whenever
 * only one media family is present for a given content identifier. Complete
 * still+video pairs produce no output.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class LivePhotoCompletenessAnalyzer
{
    /**
     * Returns human-readable verify findings for incomplete Live Photo pairs.
     *
     * @param array<string, array<string, list<array{pathname: string, isStill: bool}>>> $contentIdMap
     *                                                                                                    Per-directory content identifier map built during the metadata scan.
     * @param string                                                                     $sourceDirectory Source directory used to relativize file paths for output
     *
     * @return list<string> Missing-companion findings ready for verify output
     */
    public function analyze(array $contentIdMap, string $sourceDirectory): array
    {
        $findings = [];

        foreach ($contentIdMap as $dirFiles) {
            foreach ($dirFiles as $contentIdFiles) {
                $hasStill = false;
                $hasVideo = false;

                foreach ($contentIdFiles as $entry) {
                    if ($entry['isStill']) {
                        $hasStill = true;
                    } else {
                        $hasVideo = true;
                    }
                }

                if ($hasStill && $hasVideo) {
                    continue;
                }

                foreach ($contentIdFiles as $entry) {
                    $relativePath = PathHelper::relativizePath($entry['pathname'], $sourceDirectory);

                    $findings[] = $entry['isStill'] ? $relativePath . ' → no paired MOV' : $relativePath . ' → no paired JPG/HEIC';
                }
            }
        }

        return $findings;
    }
}
