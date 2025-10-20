<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Command;

use MagicSunday\Renamer\Command\RenameByExifDateCommand;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\SafeExifReader;
use MagicSunday\Renamer\Service\SafeFileReader;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\LivePhotoContentIdentifierStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RenameByExifDateCommand::class)]
final class RenameByExifDateCommandTest extends TestCase
{
    #[Test]
    public function configureExposesExifDateCommandWithAlias(): void
    {
        $command = new RenameByExifDateCommand(
            $this->createMock(FileSystemServiceInterface::class),
            $this->createMock(DuplicateDetectionServiceInterface::class),
            new SafeExifReader(),
            new SafeFileReader(),
        );

        self::assertSame('exif:date', $command->getName());
        self::assertContains('rename:exifdate', $command->getAliases());
    }

    #[Test]
    public function executeEnablesLivePhotoStrategyAndUsesConfiguredPattern(): void
    {
        /** @var FileSystemServiceInterface&MockObject $fileSystemService */
        $fileSystemService = $this->createMock(FileSystemServiceInterface::class);

        /** @var DuplicateDetectionServiceInterface&MockObject $duplicateDetectionService */
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator([]));

        $fileSystemService
            ->expects(self::exactly(2))
            ->method('createFileIterator')
            ->with('source-dir')
            ->willReturn($iterator);

        $fileSystemService
            ->expects(self::once())
            ->method('countFiles')
            ->with($iterator)
            ->willReturn(0);

        $duplicateCollection = new FileDuplicateCollection();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setSourceDirectory')
            ->with('source-dir')
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setTargetDirectory')
            ->with('target-dir')
            ->willReturnSelf();

        $capturedRenameStrategy = null;
        $capturedDuplicateStrategy = null;

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::isInstanceOf(RecursiveIteratorIterator::class),
                self::callback(static function ($strategy) use (&$capturedRenameStrategy): bool {
                    $capturedRenameStrategy = $strategy;

                    return $strategy instanceof ExifDateFilenameStrategy;
                }),
                self::callback(static function ($strategy) use (&$capturedDuplicateStrategy): bool {
                    $capturedDuplicateStrategy = $strategy;

                    return $strategy instanceof LivePhotoContentIdentifierStrategy;
                }),
            )
            ->willReturn($duplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setUseFileExtensionFromSource')
            ->with(true)
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
                false,
                false,
            );

        $command = new RenameByExifDateCommand(
            $fileSystemService,
            $duplicateDetectionService,
            new SafeExifReader(),
            new SafeFileReader(),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'source-directory' => 'source-dir',
            'target-directory' => 'target-dir',
            '--dry-run' => true,
            '--target-filename-pattern' => 'Ymd-His',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertInstanceOf(ExifDateFilenameStrategy::class, $capturedRenameStrategy);
        self::assertInstanceOf(LivePhotoContentIdentifierStrategy::class, $capturedDuplicateStrategy);

        $renamePatternProperty = new ReflectionProperty(ExifDateFilenameStrategy::class, 'targetFilenamePattern');
        $renamePatternProperty->setAccessible(true);

        self::assertSame('Ymd-His', $renamePatternProperty->getValue($capturedRenameStrategy));

        $duplicateRenameStrategyProperty = new ReflectionProperty(LivePhotoContentIdentifierStrategy::class, 'renameStrategy');
        $duplicateRenameStrategyProperty->setAccessible(true);

        self::assertSame($capturedRenameStrategy, $duplicateRenameStrategyProperty->getValue($capturedDuplicateStrategy));
    }
}
