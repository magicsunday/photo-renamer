<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Fixtures;

use MagicSunday\Renamer\Service\DuplicateCanonicalRenameSelector;
use MagicSunday\Renamer\Service\DuplicateDetectionService;
use MagicSunday\Renamer\Service\DuplicateSuffixAssigner;
use MagicSunday\Renamer\Service\HashSubGroupingServiceInterface;
use MagicSunday\Renamer\Service\LegacyContentIdentifierCoordinator;
use MagicSunday\Renamer\Service\LegacyDuplicateTargetCandidateFactory;
use MagicSunday\Renamer\Service\LegacyLivePhotoCompanionDetector;
use MagicSunday\Renamer\Service\LegacyLivePhotoDuplicateCoordinator;
use MagicSunday\Renamer\Service\LegacyLivePhotoQualityFlagPropagator;
use MagicSunday\Renamer\Service\LegacyLivePhotoTargetPromoter;
use MagicSunday\Renamer\Service\LegacyTargetFileResolver;
use MagicSunday\Renamer\Service\LegacyTargetOccupancyChecker;
use MagicSunday\Renamer\Service\LegacyTargetPathResolver;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoConflictDetectorInterface;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use MagicSunday\Renamer\Service\Reporting\ProgressReporterInterface;
use MagicSunday\Renamer\Service\TargetFileResolver;
use MagicSunday\Renamer\Service\TargetPathResolver;

/**
 * Creates fully wired DuplicateDetectionService instances for manual tests.
 *
 * The production container autowires the legacy service directly. Manual
 * integration and unit harnesses still assemble the old execution path by hand,
 * so this fixture centralizes the now-explicit collaborator graph without
 * reintroducing productive constructor defaults.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class DuplicateDetectionServiceFactory
{
    /**
     * Creates the default legacy duplicate-detection service used in manual tests.
     *
     * @param ProgressReporterInterface               $progressReporter          Reporter used for recoverable progress and diagnostics.
     * @param HashSubGroupingServiceInterface         $hashSubGroupingService    Legacy subgrouping service shared with the old execution path.
     * @param MediaTypeClassifierInterface            $mediaTypeClassifier       Media classifier shared with legacy Live Photo helpers.
     * @param LivePhotoConflictDetectorInterface|null $livePhotoConflictDetector Optional conflict detector for tests that model mismatched LP IDs.
     */
    public static function create(
        ProgressReporterInterface $progressReporter,
        HashSubGroupingServiceInterface $hashSubGroupingService,
        MediaTypeClassifierInterface $mediaTypeClassifier,
        ?LivePhotoConflictDetectorInterface $livePhotoConflictDetector = null,
    ): DuplicateDetectionService {
        $targetPathResolver       = new TargetPathResolver();
        $legacyTargetPathResolver = new LegacyTargetPathResolver($targetPathResolver);
        $targetFileResolver       = new TargetFileResolver($targetPathResolver);
        $legacyTargetFileResolver = new LegacyTargetFileResolver($targetFileResolver);
        $companionDetector        = new LegacyLivePhotoCompanionDetector($mediaTypeClassifier);

        return new DuplicateDetectionService(
            $progressReporter,
            $hashSubGroupingService,
            $livePhotoConflictDetector,
            new DuplicateCanonicalRenameSelector(),
            new DuplicateSuffixAssigner(),
            new LegacyLivePhotoTargetPromoter($mediaTypeClassifier),
            new LegacyLivePhotoQualityFlagPropagator(),
            new LegacyLivePhotoDuplicateCoordinator($companionDetector, $mediaTypeClassifier),
            $legacyTargetPathResolver,
            $legacyTargetFileResolver,
            new LegacyTargetOccupancyChecker(),
            new LegacyDuplicateTargetCandidateFactory($legacyTargetPathResolver),
            new LegacyContentIdentifierCoordinator($mediaTypeClassifier),
        );
    }
}
