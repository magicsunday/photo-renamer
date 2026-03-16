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
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetPathnameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\LowerCaseFilenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RenameLowerCaseCommand::class)]
final class RenameLowerCaseCommandTest extends TestCase
{
    #[Test]
    public function configureExposesLowerCaseCommandWithAlias(): void
    {
        $command = new RenameLowerCaseCommand(
            self::createStub(FileSystemServiceInterface::class),
            self::createStub(DuplicateDetectionServiceInterface::class),
        );

        self::assertSame('rename:lower', $command->getName());
    }

    #[Test]
    public function executeNormalizesTargetDirectoryAndUsesLowerCaseStrategy(): void
    {
        /** @var FileSystemServiceInterface&MockObject $fileSystemService */
        $fileSystemService = $this->createMock(FileSystemServiceInterface::class);

        /** @var DuplicateDetectionServiceInterface&MockObject $duplicateDetectionService */
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator([]));

        $expectedSourceDirectory = $this->buildExpectedAbsolutePath('source-dir');

        $fileSystemService
            ->expects(self::once())
            ->method('createFileIterator')
            ->with($expectedSourceDirectory)
            ->willReturn($iterator);

        $duplicateCollection = new FileDuplicateCollection();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setSourceDirectory')
            ->with($expectedSourceDirectory)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setTargetDirectory')
            ->with($expectedSourceDirectory)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::identicalTo($iterator),
                self::callback(static fn ($strategy): bool => $strategy instanceof LowerCaseFilenameStrategy),
                self::callback(static fn ($strategy): bool => $strategy instanceof TargetPathnameStrategy),
            )
            ->willReturn($duplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setUseFileExtensionFromSource')
            ->with(false)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('createDuplicateFilenames')
            ->with(self::identicalTo($duplicateCollection))
            ->willReturn($duplicateCollection);

        $fileSystemService
            ->expects(self::once())
            ->method('renameFiles')
            ->with(
                self::identicalTo($duplicateCollection),
                self::callback(static function (RenameOptions $options): bool {
                    self::assertTrue($options->dryRun);
                    self::assertFalse($options->skipDuplicates);
                    self::assertFalse($options->copyFiles);
                    self::assertFalse($options->listAll);

                    return true;
                }),
            );

        $command = new RenameLowerCaseCommand($fileSystemService, $duplicateDetectionService);

        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([
            'source-directory' => 'source-dir',
            '--dry-run'        => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    private function buildExpectedAbsolutePath(string $relativePath): string
    {
        $workingDirectory = getcwd();

        if ($workingDirectory === false) {
            return $relativePath;
        }

        $trimmedWorkingDirectory = rtrim($workingDirectory, '\\/');

        if ($trimmedWorkingDirectory === '') {
            return $relativePath;
        }

        $normalizedRelativePath = ltrim($relativePath, '\\/');

        if ($normalizedRelativePath === '') {
            return $trimmedWorkingDirectory;
        }

        return $trimmedWorkingDirectory . DIRECTORY_SEPARATOR . $normalizedRelativePath;
    }
}
