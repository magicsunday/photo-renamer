<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Fixtures;

use MagicSunday\Renamer\Service\LivePhoto\LivePhotoConflictDetectorInterface;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingServiceInterface;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use MagicSunday\Renamer\Service\Pipeline\CaptureAssetCandidateExtractor;
use MagicSunday\Renamer\Service\Pipeline\CaptureContentIdentifierCoordinator;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuilder;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupQualityTracker;
use MagicSunday\Renamer\Service\Pipeline\PendingLivePhotoVideoResolver;
use MagicSunday\Renamer\Service\Reporting\ProgressReporterInterface;
use MagicSunday\Renamer\Service\TargetFileResolver;
use MagicSunday\Renamer\Service\TargetPathResolver;

/**
 * Creates fully wired CaptureGroupBuilder instances for manual tests.
 *
 * The production container autowires the builder directly. A handful of unit
 * and integration harnesses still assemble the pipeline manually, so this
 * fixture keeps those setups explicit while avoiding repeated wiring of the
 * builder's local helper collaborators.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class CaptureGroupBuilderFactory
{
    /**
     * Creates the default grouping builder used across manual test harnesses.
     *
     * @param ProgressReporterInterface               $progressReporter          Reporter used by the builder during the scan phase.
     * @param MediaTypeClassifierInterface            $mediaTypeClassifier       Media classifier shared with pairing and content-ID coordination.
     * @param LivePhotoConflictDetectorInterface|null $livePhotoConflictDetector Optional LP conflict detector for tests that model pairing conflicts.
     * @param LivePhotoPairingServiceInterface|null   $livePhotoPairingService   Optional second-pass LP pairing service for tests that need it.
     */
    public static function create(
        ProgressReporterInterface $progressReporter,
        MediaTypeClassifierInterface $mediaTypeClassifier,
        ?LivePhotoConflictDetectorInterface $livePhotoConflictDetector = null,
        ?LivePhotoPairingServiceInterface $livePhotoPairingService = null,
    ): CaptureGroupBuilder {
        return new CaptureGroupBuilder(
            $progressReporter,
            $livePhotoConflictDetector,
            $livePhotoPairingService,
            new TargetFileResolver(new TargetPathResolver()),
            new CaptureAssetCandidateExtractor(),
            new CaptureContentIdentifierCoordinator($mediaTypeClassifier),
            new PendingLivePhotoVideoResolver(),
            new CaptureGroupQualityTracker(),
        );
    }
}
