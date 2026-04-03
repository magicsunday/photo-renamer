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
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\Collection\AbstractCollection;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetPathnameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\LowerCaseFilenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
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
#[CoversClass(RenameLowerCaseCommand::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(AbstractCollection::class)]
#[UsesClass(RenameOptions::class)]
#[UsesClass(OutputEntryTag::class)]
#[UsesClass(RenameResult::class)]
final class RenameLowerCaseCommandTest extends TestCase
{
    /**
     * Verifies that the command registers under the name "rename:lower".
     */
    #[Test]
    public function configureExposesLowerCaseCommandWithAlias(): void
    {
        $command = new RenameLowerCaseCommand(
            self::createStub(FileSystemServiceInterface::class),
            self::createStub(DuplicateDetectionServiceInterface::class),
            new SafeRegex(),
            new Filesystem(),
        );

        self::assertSame('rename:lower', $command->getName());
    }

    /**
     * Verifies that execute() wires LowerCaseFilenameStrategy for renaming and
     * TargetPathnameStrategy for grouping, normalises the relative source directory
     * to an absolute path, defaults the target to the source (in-place rename),
     * and propagates --dry-run to RenameOptions.
     */
    #[Test]
    public function executeNormalizesTargetDirectoryAndUsesLowerCaseStrategy(): void
    {
        $sourceDir = sys_get_temp_dir() . '/renamer-test-lower-source-' . uniqid();

        mkdir($sourceDir);

        try {
            /** @var FileSystemServiceInterface&MockObject $fileSystemService */
            $fileSystemService = $this->createMock(FileSystemServiceInterface::class);

            /** @var DuplicateDetectionServiceInterface&MockObject $duplicateDetectionService */
            $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

            $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator([]));

            $fileSystemService
                ->expects(self::once())
                ->method('createFileIterator')
                ->with($sourceDir)
                ->willReturn($iterator);

            $duplicateCollection = new FileDuplicateCollection();

            $duplicateDetectionService
                ->expects(self::once())
                ->method('groupFilesByDuplicateIdentifier')
                ->with(
                    self::identicalTo($iterator),
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

            $fileSystemService
                ->expects(self::once())
                ->method('renameFiles')
                ->with(
                    self::identicalTo($duplicateCollection),
                    self::callback(static function (RenameOptions $options): bool {
                        self::assertTrue($options->dryRun);
                        self::assertFalse($options->listAll);

                        return true;
                    }),
                );

            $command = new RenameLowerCaseCommand($fileSystemService, $duplicateDetectionService, new SafeRegex(), new Filesystem());

            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source'    => $sourceDir,
                '--dry-run' => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
        } finally {
            rmdir($sourceDir);
        }
    }
}
