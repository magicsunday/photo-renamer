<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Model\Collection\AbstractCollection;
use MagicSunday\Renamer\Model\Collection\FileList;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Service\LegacyLivePhotoTargetPromoter;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies canonical target promotion for legacy Live Photo duplicate groups.
 *
 * The promoter protects the legacy grouping path from keeping a MOV target as
 * the group's canonical basename source when a still image target is encountered
 * later in the same `live-photo:` group.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(LegacyLivePhotoTargetPromoter::class)]
#[UsesClass(AbstractCollection::class)]
#[UsesClass(FileList::class)]
#[UsesClass(RenameList::class)]
#[UsesClass(FileDuplicate::class)]
#[UsesClass(MediaTypeClassifier::class)]
final class LegacyLivePhotoTargetPromoterTest extends TestCase
{
    /**
     * Verifies that a still candidate replaces an existing video target inside a
     * Live Photo group so the group becomes photo-first.
     */
    #[Test]
    public function promoteReplacesVideoCanonicalWithStillTarget(): void
    {
        $promoter = new LegacyLivePhotoTargetPromoter(new MediaTypeClassifier());

        $group = new FileDuplicate()
            ->setTarget(new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'video-target.mov'));
        $stillTarget = new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'photo-target.heic');

        $promoter->promote('live-photo:cid-1', $group, $stillTarget);

        self::assertSame($stillTarget->getPathname(), $group->getTarget()->getPathname());
    }

    /**
     * Verifies that non-Live-Photo groups are left untouched even if a still
     * candidate is encountered later.
     */
    #[Test]
    public function promoteIgnoresNonLivePhotoGroups(): void
    {
        $promoter = new LegacyLivePhotoTargetPromoter(new MediaTypeClassifier());

        $group = new FileDuplicate()
            ->setTarget(new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'video-target.mov'));
        $stillTarget = new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'photo-target.heic');

        $promoter->promote('2025-01-01_12-00-00-000', $group, $stillTarget);

        self::assertStringEndsWith('.mov', $group->getTarget()->getFilename());
    }

    /**
     * Verifies that a group already pointing at a still target keeps that target
     * and is not replaced by later candidates.
     */
    #[Test]
    public function promoteLeavesExistingStillCanonicalUntouched(): void
    {
        $promoter = new LegacyLivePhotoTargetPromoter(new MediaTypeClassifier());

        $existingStill = new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'photo-target.heic');
        $group         = new FileDuplicate()
            ->setTarget($existingStill);
        $otherStillTarget = new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'other-target.jpg');

        $promoter->promote('live-photo:cid-1', $group, $otherStillTarget);

        self::assertSame($existingStill->getPathname(), $group->getTarget()->getPathname());
    }
}
