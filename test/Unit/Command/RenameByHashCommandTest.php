<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Command;

use MagicSunday\Renamer\Command\RenameByHashCommand;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\ContentHashStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\InheritFilenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RenameByHashCommand::class)]
final class RenameByHashCommandTest extends TestCase
{
    #[Test]
    public function configureExposesHashCommandWithAlias(): void
    {
        $command = new RenameByHashCommand(
            $this->createMock(FileSystemServiceInterface::class),
            $this->createMock(DuplicateDetectionServiceInterface::class),
            new SafeHashCalculator(),
        );

        self::assertSame('hash', $command->getName());
        self::assertContains('rename:hash', $command->getAliases());
    }

    #[Test]
    public function executeConfiguresServicesWithHashStrategies(): void
    {
        /** @var FileSystemServiceInterface&MockObject $fileSystemService */
        $fileSystemService = $this->createMock(FileSystemServiceInterface::class);

        /** @var DuplicateDetectionServiceInterface&MockObject $duplicateDetectionService */
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator([]));

        $expectedSourceDirectory = $this->buildExpectedAbsolutePath('source-dir');
        $expectedTargetDirectory = $this->buildExpectedAbsolutePath('target-dir');

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
            ->with($expectedTargetDirectory)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setListAll')
            ->with(false)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::identicalTo($iterator),
                self::callback(static fn ($strategy) => $strategy instanceof InheritFilenameStrategy),
                self::callback(static fn ($strategy) => $strategy instanceof ContentHashStrategy),
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
                true,
                true,
                true,
                false,
            );

        $command = new RenameByHashCommand($fileSystemService, $duplicateDetectionService, new SafeHashCalculator());

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'source-directory' => 'source-dir',
            'target-directory' => 'target-dir',
            '--dry-run' => true,
            '--skip-duplicates' => true,
            '--copy' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    private function buildExpectedAbsolutePath(string $relativePath): string
    {
        $workingDirectory = getcwd();

        if (!is_string($workingDirectory) || $workingDirectory === '') {
            return $relativePath;
        }

        $trimmedWorkingDirectory = rtrim($workingDirectory, "\\/");

        if ($trimmedWorkingDirectory === '') {
            return $relativePath;
        }

        $normalizedRelativePath = ltrim($relativePath, "\\/");

        if ($normalizedRelativePath === '') {
            return $trimmedWorkingDirectory;
        }

        return $trimmedWorkingDirectory . DIRECTORY_SEPARATOR . $normalizedRelativePath;
    }
}
