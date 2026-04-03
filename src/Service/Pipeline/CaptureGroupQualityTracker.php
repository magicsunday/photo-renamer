<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Service\MetadataQualityFlagResolver;
use MagicSunday\Renamer\Strategy\RenameStrategy\MetadataAwareRenameStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use SplFileInfo;

/**
 * Records metadata-quality findings discovered during capture-group building.
 *
 * CaptureGroupBuilder needs to propagate fallback-date and ambiguous-timezone
 * findings into PipelineContext while it scans files. This collaborator keeps
 * those quality concerns separate from the grouping and deferral mechanics.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class CaptureGroupQualityTracker
{
    /**
     * Tracks quality flags for files with unreliable date metadata.
     *
     * @param SplFileInfo             $file     Source file to check
     * @param RenameStrategyInterface $strategy Rename strategy that may expose quality info
     * @param PipelineContext         $context  Pipeline context to record quality flags
     */
    public function track(
        SplFileInfo $file,
        RenameStrategyInterface $strategy,
        PipelineContext $context,
    ): void {
        if (!$strategy instanceof MetadataAwareRenameStrategyInterface) {
            return;
        }

        $qualityFlags = MetadataQualityFlagResolver::resolve($file, $strategy);

        if ($qualityFlags['hasFallbackDate']) {
            $context->addFallbackDateFile($file->getPathname());
        }

        if ($qualityFlags['hasAmbiguousTimezone']) {
            $context->addAmbiguousTimezoneFile($file->getPathname());
        }
    }
}
