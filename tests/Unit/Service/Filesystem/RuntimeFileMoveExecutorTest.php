<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Filesystem;

use MagicSunday\Renamer\Service\Filesystem\RuntimeCollisionPathAllocator;
use MagicSunday\Renamer\Service\Filesystem\RuntimeFileMoveExecutor;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Verifies concrete runtime file move behavior.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(RuntimeFileMoveExecutor::class)]
#[CoversClass(RuntimeCollisionPathAllocator::class)]
final class RuntimeFileMoveExecutorTest extends TestCase
{
    /**
     * Verifies that a no-op move updates occupancy without touching the filesystem.
     */
    #[Test]
    public function moveFileByPathSkipsFilesystemForNoOpMove(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::never())->method('mkdir');
        $filesystem->expects(self::never())->method('rename');

        $executor = new RuntimeFileMoveExecutor(
            new NullProgressReporter(),
            $filesystem,
            new RuntimeCollisionPathAllocator(),
        );

        $sourcePath    = '/photos/current.jpg';
        $occupiedPaths = [$sourcePath => true];

        $actualTarget = $executor->moveFileByPath($sourcePath, $sourcePath, $occupiedPaths, dryRun: false);

        self::assertSame($sourcePath, $actualTarget);
        self::assertSame([$sourcePath => true], $occupiedPaths);
    }
}
