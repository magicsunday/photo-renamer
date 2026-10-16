<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Command;

use MagicSunday\Renamer\Command\RenameLowerCaseCommand;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\Filesystem\FileCollector;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetPathnameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\LowerCaseFilenameStrategy;
use MagicSunday\Renamer\Test\Fixtures\RuntimeExecutorFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Verifies the RenameLowerCaseCommand, which converts all filenames to lowercase
 * using LowerCaseFilenameStrategy and groups by full target pathname to detect
 * case-only collisions.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversNothing]
final class RenameLowerCaseCommandTest extends TestCase
{
    /**
     * Verifies that the command registers under the name "rename:lower".
     */
    #[Test]
    public function configureExposesLowerCaseCommandWithAlias(): void
    {
        $command = new RenameLowerCaseCommand(
            new FileCollector(new SafeRegex()),
            RuntimeExecutorFactory::createLegacy(),
            self::createStub(DuplicateDetectionServiceInterface::class),
            new SafeRegex(),
            new Filesystem(),
            new LowerCaseFilenameStrategy(),
            new TargetPathnameStrategy(),
        );

        self::assertSame('rename:lower', $command->getName());
    }

    /**
     * An empty-source dry-run succeeds with lowercase naming and full-target-path
     * grouping strategies, using the same absolute source and target directory.
     */
    #[Test]
    public function executeNormalizesTargetDirectoryAndUsesLowerCaseStrategy(): void
    {
        $sourceDir = sys_get_temp_dir() . '/renamer-test-lower-source-' . uniqid();

        mkdir($sourceDir);

        try {
            /** @var DuplicateDetectionServiceInterface&MockObject $duplicateDetectionService */
            $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

            $duplicateCollection = new FileDuplicateCollection();

            $duplicateDetectionService
                ->expects(self::once())
                ->method('groupFilesByDuplicateIdentifier')
                ->with(
                    self::isInstanceOf(RecursiveIteratorIterator::class),
                    self::callback(static fn ($strategy): bool => $strategy instanceof LowerCaseFilenameStrategy),
                    self::callback(static fn ($strategy): bool => $strategy instanceof TargetPathnameStrategy),
                    $sourceDir,
                )
                ->willReturn($duplicateCollection);

            $duplicateDetectionService
                ->expects(self::once())
                ->method('createDuplicateFilenames')
                ->with(
                    self::identicalTo($duplicateCollection),
                    $sourceDir,
                    false,
                )
                ->willReturn($duplicateCollection);

            $command = new RenameLowerCaseCommand(
                new FileCollector(new SafeRegex()),
                RuntimeExecutorFactory::createLegacy(),
                $duplicateDetectionService,
                new SafeRegex(),
                new Filesystem(),
                new LowerCaseFilenameStrategy(),
                new TargetPathnameStrategy(),
            );

            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source' => $sourceDir,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
        } finally {
            rmdir($sourceDir);
        }
    }
}
