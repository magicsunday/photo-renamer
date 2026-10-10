<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Filesystem;

use MagicSunday\Renamer\Service\Filesystem\FileCollector;
use MagicSunday\Renamer\Service\Filesystem\RuntimeCollisionPathAllocator;
use MagicSunday\Renamer\Service\Filesystem\RuntimeFileMoveExecutor;
use MagicSunday\Renamer\Service\Filesystem\SourceIdentityGuard;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use MagicSunday\Renamer\Test\Fixtures\FileSystemServiceFactory;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function is_link;
use function readlink;

/**
 * Verifies concrete runtime file move behavior.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[UsesClass(SourceIdentityGuard::class)]
#[UsesClass(FileCollector::class)]
#[CoversClass(RuntimeFileMoveExecutor::class)]
#[CoversClass(RuntimeCollisionPathAllocator::class)]
final class RuntimeFileMoveExecutorTest extends TestCase
{
    use WorkspaceTrait;

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
            FileSystemServiceFactory::createSourceIdentityGuard(),
        );

        $sourcePath    = '/photos/current.jpg';
        $occupiedPaths = [$sourcePath => true];

        $actualTarget = $executor->moveFileByPath($sourcePath, $sourcePath, $occupiedPaths, dryRun: false);

        self::assertSame($sourcePath, $actualTarget);
        self::assertSame([$sourcePath => true], $occupiedPaths);
    }

    /**
     * A target created after analysis must preserve the source and existing leaf.
     * The unreadable-file and dangling-link cases exercise leaves Symfony's
     * readability-based overwrite=false check would otherwise overwrite.
     *
     * @param string $targetKind Type of externally created target leaf
     *
     * @return void
     */
    #[Test]
    #[DataProvider('externalTargetKinds')]
    public function externalTargetAppearingAfterPlanningIsPreserved(string $targetKind): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $source     = $workspace . '/source.jpg';
        $target     = $workspace . '/target.jpg';
        $filesystem->dumpFile($source, 'synthetic source');
        $occupiedPaths = [$source => true];

        if ($targetKind === 'dangling link') {
            $filesystem->symlink($workspace . '/missing', $target);
        } else {
            $filesystem->dumpFile($target, 'external content');

            if ($targetKind === 'unreadable file') {
                $filesystem->chmod($target, 0o000);
            }
        }

        try {
            $executor = new RuntimeFileMoveExecutor(new NullProgressReporter(), $filesystem, new RuntimeCollisionPathAllocator(), FileSystemServiceFactory::createSourceIdentityGuard());
            $blocked  = false;

            try {
                $executor->moveFileByPath($source, $target, $occupiedPaths, false);
            } catch (RuntimeException) {
                $blocked = true;
            }

            self::assertTrue($blocked, 'An observed external target must block the mutation.');
            self::assertSame('synthetic source', $filesystem->readFile($source));
            self::assertSame([$source => true], $occupiedPaths);

            if ($targetKind === 'dangling link') {
                self::assertTrue(is_link($target));
                self::assertSame($workspace . '/missing', readlink($target));
            } else {
                $filesystem->chmod($target, 0o600);
                self::assertSame('external content', $filesystem->readFile($target));
            }
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * Covers ordinary conflicts and the two filesystem leaves that are not
     * reliably detected by a readability-based target check.
     *
     * @return iterable<string, array{string}> External target states
     */
    public static function externalTargetKinds(): iterable
    {
        yield 'readable file' => ['readable file'];
        yield 'unreadable file' => ['unreadable file'];
        yield 'dangling link' => ['dangling link'];
    }

    /**
     * An external writer is simulated at the mkdir boundary after the plan's
     * occupancy map has been built. The real rename implementation must never
     * overwrite the unreadable leaf created at that controlled synchronization
     * point; both source bytes and foreign bytes remain unchanged.
     *
     * @return void
     */
    #[Test]
    public function targetCreatedDuringDirectoryPreparationBlocksActualRename(): void
    {
        $workspace      = $this->createTempWorkspace();
        $realFilesystem = new Filesystem();
        $source         = $workspace . '/source.jpg';
        $target         = $workspace . '/target.jpg';
        $realFilesystem->dumpFile($source, 'synthetic source');

        $filesystem = $this->createPartialMock(Filesystem::class, ['mkdir']);
        $filesystem->expects(self::once())->method('mkdir')->willReturnCallback(static function () use ($realFilesystem, $target): void {
            $realFilesystem->dumpFile($target, 'external content');
            $realFilesystem->chmod($target, 0o000);
        });

        $occupiedPaths = [$source => true];

        try {
            $executor = new RuntimeFileMoveExecutor(new NullProgressReporter(), $filesystem, new RuntimeCollisionPathAllocator(), FileSystemServiceFactory::createSourceIdentityGuard());
            $blocked  = false;

            try {
                $executor->moveFileByPath($source, $target, $occupiedPaths, false);
            } catch (RuntimeException) {
                $blocked = true;
            }

            self::assertTrue($blocked);
            self::assertSame('synthetic source', $realFilesystem->readFile($source));
            $realFilesystem->chmod($target, 0o600);
            self::assertSame('external content', $realFilesystem->readFile($target));
            self::assertSame([$source => true], $occupiedPaths);
        } finally {
            $realFilesystem->remove($workspace);
        }
    }
}
