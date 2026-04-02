<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Service\LegacyTargetOccupancyChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies disk-index occupancy rules for legacy duplicate target paths.
 *
 * The checker must treat the source itself as free, ignore paths that belong to
 * the same duplicate group, and flag external files recorded in the disk index
 * as occupied.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(LegacyTargetOccupancyChecker::class)]
final class LegacyTargetOccupancyCheckerTest extends TestCase
{
    /**
     * Verifies that a target path equal to the current source pathname is never
     * treated as occupied, preserving idempotent re-runs.
     */
    #[Test]
    public function isOccupiedReturnsFalseForSourcePathItself(): void
    {
        $checker = new LegacyTargetOccupancyChecker();
        $source  = new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'photo.jpg');

        self::assertFalse($checker->isOccupied($source, $source, [], []));
    }

    /**
     * Verifies that a target present in the disk index but also belonging to
     * the current group is treated as free because that path will be released by
     * the group's own rename operations.
     */
    #[Test]
    public function isOccupiedReturnsFalseForPathOwnedBySameGroup(): void
    {
        $checker    = new LegacyTargetOccupancyChecker();
        $source     = new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'copy.jpg');
        $targetPath = DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2024-01-01.jpg';
        $target     = new SplFileInfo($targetPath);

        self::assertFalse($checker->isOccupied(
            $target,
            $source,
            [$targetPath => true],
            [$targetPath => true],
        ));
    }

    /**
     * Verifies that a target recorded in the disk index and not owned by the
     * current group is reported as occupied so suffix assignment picks a new
     * duplicate pathname.
     */
    #[Test]
    public function isOccupiedReturnsTrueForExternalDiskIndexEntry(): void
    {
        $checker    = new LegacyTargetOccupancyChecker();
        $source     = new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'copy.jpg');
        $targetPath = DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2024-01-01.jpg';
        $target     = new SplFileInfo($targetPath);

        self::assertTrue($checker->isOccupied(
            $target,
            $source,
            [],
            [$targetPath => true],
        ));
    }
}
