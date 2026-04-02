<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Constants;
use SplFileInfo;

use function sprintf;

/**
 * Creates concrete `-duplicate-NNN` target files for the legacy duplicate flow.
 *
 * The legacy suffix assigner decides when another candidate is needed, but the
 * pathname construction itself belongs to the target-path layer: append the
 * formatted duplicate suffix, preserve the chosen extension, and keep the
 * legacy directory structure relative to the source root. This worker isolates
 * that small construction rule from `DuplicateDetectionService`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LegacyDuplicateTargetCandidateFactory
{
    /**
     * @param LegacyTargetPathResolver $targetPathResolver Resolves the final absolute target pathname while preserving the legacy directory structure.
     */
    public function __construct(private LegacyTargetPathResolver $targetPathResolver)
    {
    }

    /**
     * Creates a new duplicate target candidate with the requested counter.
     *
     * @param string      $sourceDirectory Absolute source root used as the legacy destination base.
     * @param SplFileInfo $source          Source file currently being processed.
     * @param SplFileInfo $target          Initial target whose extension should be preserved.
     * @param string      $targetBasename  Base filename used for duplicate naming without extension.
     * @param int         $duplicateCount  Counter value to encode into the `-duplicate-NNN` suffix.
     */
    public function create(
        string $sourceDirectory,
        SplFileInfo $source,
        SplFileInfo $target,
        string $targetBasename,
        int $duplicateCount,
    ): SplFileInfo {
        $newTargetBasename = sprintf(
            '%s' . Constants::DUPLICATE_IDENTIFIER . '%003d',
            $targetBasename,
            $duplicateCount,
        );

        return new SplFileInfo(
            $this->targetPathResolver->resolve(
                $sourceDirectory,
                $source,
                $newTargetBasename . '.' . $target->getExtension(),
            )
        );
    }
}
