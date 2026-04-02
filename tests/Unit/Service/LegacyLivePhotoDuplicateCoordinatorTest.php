<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Service\LegacyLivePhotoCompanionDetector;
use MagicSunday\Renamer\Service\LegacyLivePhotoDuplicateCoordination;
use MagicSunday\Renamer\Service\LegacyLivePhotoDuplicateCoordinator;
use MagicSunday\Renamer\Service\LegacyLivePhotoPair;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies the legacy Live Photo duplicate coordinator extracted from the main grouping loop.
 *
 * The coordinator glues two responsibilities together:
 * - delegate companion detection to the dedicated detector
 * - normalize the returned still/companion pair so later quality-flag propagation
 *   does not have to understand whether the canonical rename was a still or a video
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(LegacyLivePhotoDuplicateCoordinator::class)]
#[CoversClass(LegacyLivePhotoDuplicateCoordination::class)]
final class LegacyLivePhotoDuplicateCoordinatorTest extends TestCase
{
    /**
     * Verifies that when the canonical rename is the still image, the returned
     * pair keeps the canonical as `still` and the detected MOV as `companion`.
     */
    #[Test]
    public function coordinateReturnsStillFirstPairWhenCanonicalIsStill(): void
    {
        $detector    = new LegacyLivePhotoCompanionDetector(new MediaTypeClassifier());
        $coordinator = new LegacyLivePhotoDuplicateCoordinator($detector, new MediaTypeClassifier());

        $canonical = new Rename(
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'IMG_0001.HEIC'),
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.heic'),
        );
        $companion = new Rename(
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'IMG_0001.MOV'),
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.mov'),
        );
        $duplicate = new FileDuplicate();
        $duplicate
            ->addRename($canonical)
            ->addRename($companion);

        $conflicts = [];

        $result = $coordinator->coordinate(
            $canonical,
            $duplicate,
            [
                $canonical->getSource()->getPathname() => 'cid-1',
                $companion->getSource()->getPathname() => 'cid-1',
            ],
            $conflicts,
        );

        self::assertSame($companion, $result->companionRename);
        self::assertEquals(
            new LegacyLivePhotoPair(
                $canonical->getSource()->getPathname(),
                $companion->getSource()->getPathname(),
            ),
            $result->livePhotoPair,
        );
    }

    /**
     * Verifies that when the canonical rename is the video, the normalized pair
     * is reordered so the still image still becomes the `still` side.
     */
    #[Test]
    public function coordinateReturnsStillFirstPairWhenCanonicalIsVideo(): void
    {
        $detector    = new LegacyLivePhotoCompanionDetector(new MediaTypeClassifier());
        $coordinator = new LegacyLivePhotoDuplicateCoordinator($detector, new MediaTypeClassifier());

        $canonicalVideo = new Rename(
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'IMG_0002.MOV'),
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.mov'),
        );
        $still = new Rename(
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'IMG_0002.HEIC'),
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.heic'),
        );
        $duplicate = new FileDuplicate();
        $duplicate
            ->addRename($canonicalVideo)
            ->addRename($still);

        $conflicts = [];

        $result = $coordinator->coordinate(
            $canonicalVideo,
            $duplicate,
            [
                $canonicalVideo->getSource()->getPathname() => 'cid-2',
                $still->getSource()->getPathname()          => 'cid-2',
            ],
            $conflicts,
        );

        self::assertSame($still, $result->companionRename);
        self::assertEquals(
            new LegacyLivePhotoPair(
                $still->getSource()->getPathname(),
                $canonicalVideo->getSource()->getPathname(),
            ),
            $result->livePhotoPair,
        );
    }

    /**
     * Verifies that the coordinator reports no pair metadata when companion
     * detection fails and therefore nothing should be propagated later.
     */
    #[Test]
    public function coordinateReturnsNullPairWhenNoCompanionWasDetected(): void
    {
        $detector    = new LegacyLivePhotoCompanionDetector(new MediaTypeClassifier());
        $coordinator = new LegacyLivePhotoDuplicateCoordinator($detector, new MediaTypeClassifier());

        $canonical = new Rename(
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'IMG_0003.HEIC'),
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.heic'),
        );
        $otherStill = new Rename(
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'IMG_9999.JPG'),
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.jpg'),
        );
        $duplicate = new FileDuplicate();
        $duplicate
            ->addRename($canonical)
            ->addRename($otherStill);

        $conflicts = [];

        $result = $coordinator->coordinate(
            $canonical,
            $duplicate,
            [
                $canonical->getSource()->getPathname() => 'cid-3',
            ],
            $conflicts,
        );

        self::assertNull($result->companionRename);
        self::assertNull($result->livePhotoPair);
    }
}
