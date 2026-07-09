<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\Collection\AbstractCollection;
use MagicSunday\Renamer\Model\Collection\FileList;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Service\LegacyLivePhotoCompanionDetector;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies the legacy Live Photo companion detector extracted from the duplicate pipeline.
 *
 * The detector must preserve the old selection semantics: prefer exact
 * content-ID matches, favor idempotently correct companion names, fall back to
 * a single opposite-media candidate when the MOV lacks a content identifier, and
 * reject that fallback when the candidate exposes a conflicting non-null ID.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(LegacyLivePhotoCompanionDetector::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(AbstractCollection::class)]
#[UsesClass(FileList::class)]
#[UsesClass(RenameList::class)]
#[UsesClass(FileDuplicate::class)]
#[UsesClass(Rename::class)]
#[UsesClass(MediaTypeClassifier::class)]
final class LegacyLivePhotoCompanionDetectorTest extends TestCase
{
    /**
     * Verifies that among content-ID matches the detector prefers the companion
     * whose source basename already matches the canonical target basename.
     *
     * This preserves idempotency for re-runs where the correct MOV companion is
     * already named like the canonical target and should therefore win over other
     * same-ID candidates.
     */
    #[Test]
    public function detectPrefersIdempotentContentIdCompanion(): void
    {
        $detector = new LegacyLivePhotoCompanionDetector(new MediaTypeClassifier());

        $canonical = new Rename(
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'IMG_0001.HEIC'),
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.heic'),
        );
        $preferredCompanion = new Rename(
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.mov'),
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.mov'),
        );
        $otherCompanion = new Rename(
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'IMG_0001-alt.MOV'),
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'IMG_0001-alt.mov'),
        );

        $duplicate = new FileDuplicate()
            ->addRename($canonical)
            ->addRename($otherCompanion)
            ->addRename($preferredCompanion);

        $conflicts = [];

        $result = $detector->detect(
            $canonical,
            $duplicate,
            [
                $canonical->getSource()->getPathname()          => 'cid-1',
                $preferredCompanion->getSource()->getPathname() => 'cid-1',
                $otherCompanion->getSource()->getPathname()     => 'cid-1',
            ],
            $conflicts,
        );

        self::assertSame($preferredCompanion, $result);
        self::assertSame([], $conflicts);
    }

    /**
     * Verifies that the detector returns a single opposite-media fallback
     * candidate when that file has no content identifier at all.
     *
     * This preserves the legacy behavior for Live Photo videos that are missing a
     * content ID in metadata but still need to stay paired with the canonical still.
     */
    #[Test]
    public function detectReturnsSingleFallbackCompanionWithoutContentIdentifier(): void
    {
        $detector = new LegacyLivePhotoCompanionDetector(new MediaTypeClassifier());

        $canonical = new Rename(
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'IMG_0001.HEIC'),
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.heic'),
        );
        $fallbackCompanion = new Rename(
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'IMG_0001.MOV'),
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.mov'),
        );

        $duplicate = new FileDuplicate()
            ->addRename($canonical)
            ->addRename($fallbackCompanion);

        $conflicts = [];

        $result = $detector->detect(
            $canonical,
            $duplicate,
            [
                $canonical->getSource()->getPathname() => 'cid-1',
            ],
            $conflicts,
        );

        self::assertSame($fallbackCompanion, $result);
        self::assertSame([], $conflicts);
    }

    /**
     * Verifies that a single fallback candidate with its own conflicting content
     * identifier is rejected and both files are recorded as a conflict.
     *
     * This protects the legacy fallback path from pairing a still image with an
     * opposite-media file that explicitly advertises a different Live Photo identity.
     */
    #[Test]
    public function detectRejectsFallbackCompanionWithConflictingContentIdentifier(): void
    {
        $detector = new LegacyLivePhotoCompanionDetector(new MediaTypeClassifier());

        $canonical = new Rename(
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'IMG_0001.HEIC'),
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.heic'),
        );
        $conflictingCompanion = new Rename(
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'IMG_9999.MOV'),
            new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.mov'),
        );

        $duplicate = new FileDuplicate()
            ->addRename($canonical)
            ->addRename($conflictingCompanion);

        $conflicts = [];

        $result = $detector->detect(
            $canonical,
            $duplicate,
            [
                $canonical->getSource()->getPathname()            => 'cid-1',
                $conflictingCompanion->getSource()->getPathname() => 'cid-2',
            ],
            $conflicts,
        );

        self::assertNull($result);
        self::assertSame(
            [
                $canonical->getSource()->getPathname()            => true,
                $conflictingCompanion->getSource()->getPathname() => true,
            ],
            $conflicts,
        );
    }
}
