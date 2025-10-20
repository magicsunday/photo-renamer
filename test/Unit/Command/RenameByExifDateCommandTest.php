<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Command;

use MagicSunday\Renamer\Command\RenameByExifDateCommand;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Service\Dto\LivePhotoPairing;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\LivePhotoPairingService;
use MagicSunday\Renamer\Service\SafeExifReader;
use MagicSunday\Renamer\Service\SafeFileReader;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\LivePhotoContentIdentifierStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifMetadataProvider;
use MagicSunday\Renamer\Strategy\RenameStrategy\QuickTime\QuickTimeContentIdentifierExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use ReflectionProperty;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Style\SymfonyStyle;

#[CoversClass(RenameByExifDateCommand::class)]
final class RenameByExifDateCommandTest extends TestCase
{
    #[Test]
    public function configureExposesExifDateCommandWithAlias(): void
    {
        $command = new RenameByExifDateCommand(
            $this->createMock(FileSystemServiceInterface::class),
            $this->createMock(DuplicateDetectionServiceInterface::class),
            $this->createExifMetadataProvider(),
            $this->createMock(LivePhotoPairingService::class),
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

        /** @var LivePhotoPairingService&MockObject $livePhotoPairingService */
        $livePhotoPairingService = $this->createMock(LivePhotoPairingService::class);

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

        $livePhotoPairingService
            ->expects(self::once())
            ->method('pairByContentIdentifier')
            ->with(
                self::isInstanceOf(RecursiveIteratorIterator::class),
                self::identicalTo($duplicateCollection),
                self::callback(static fn ($resolver): bool => is_callable($resolver)),
                self::callback(static fn ($callback): bool => $callback === null || is_callable($callback)),
            )
            ->willReturn([]);

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
            $this->createExifMetadataProvider(),
            $livePhotoPairingService,
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

    #[Test]
    public function groupFilesByDuplicateIdentifierAddsLivePhotoPairsFromService(): void
    {
        $photo = new SplFileInfo('/source/IMG_0001.HEIC');
        $video = new SplFileInfo('/source/IMG_0001.MOV');
        $target = new SplFileInfo('/target/20240101_120000.HEIC');
        $pairedTarget = new SplFileInfo('/source/20240101_120000.MOV');

        $existingDuplicate = (new FileDuplicate())
            ->addFile($photo)
            ->setTarget($target);

        $duplicateCollection = new FileDuplicateCollection();
        $duplicateCollection->set('live-photo:content-id', $existingDuplicate);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([$photo, $video], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        /** @var FileSystemServiceInterface&MockObject $fileSystemService */
        $fileSystemService = $this->createMock(FileSystemServiceInterface::class);
        $fileSystemService
            ->expects(self::once())
            ->method('createFileIterator')
            ->with('/source')
            ->willReturn(
                new RecursiveIteratorIterator(
                    new RecursiveArrayIterator([$photo], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
                ),
            );

        $fileSystemService
            ->expects(self::once())
            ->method('countFiles')
            ->with(self::identicalTo($iterator))
            ->willReturn(2);

        /** @var DuplicateDetectionServiceInterface&MockObject $duplicateDetectionService */
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);
        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::isInstanceOf(RecursiveIteratorIterator::class),
                self::isInstanceOf(ExifDateFilenameStrategy::class),
                self::isInstanceOf(LivePhotoContentIdentifierStrategy::class),
            )
            ->willReturn($duplicateCollection);

        /** @var LivePhotoPairingService&MockObject $livePhotoPairingService */
        $livePhotoPairingService = $this->createMock(LivePhotoPairingService::class);
        $livePhotoPairingService
            ->expects(self::once())
            ->method('pairByContentIdentifier')
            ->with(
                self::identicalTo($iterator),
                self::identicalTo($duplicateCollection),
                self::callback(static fn ($resolver): bool => is_callable($resolver)),
                self::callback(static fn ($callback): bool => is_callable($callback)),
            )
            ->willReturnCallback(static function ($iteratorArg, $collection, $resolver, $progressCallback) use ($video, $pairedTarget): array {
                $progressCallback();
                $progressCallback();

                return [
                    new LivePhotoPairing($video, $pairedTarget, '20240101_120000.MOV', 'content-id'),
                ];
            });

        $command = new RenameByExifDateCommand(
            $fileSystemService,
            $duplicateDetectionService,
            $this->createExifMetadataProvider(),
            $livePhotoPairingService,
        );

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects(self::atLeastOnce())->method('text');
        $io->expects(self::atLeast(2))->method('newLine');
        $io->expects(self::once())->method('progressStart')->with(2);
        $io->expects(self::exactly(2))->method('progressAdvance');
        $io->expects(self::once())->method('progressFinish');

        $ioProperty = new ReflectionProperty(RenameByExifDateCommand::class, 'io');
        $ioProperty->setAccessible(true);
        $ioProperty->setValue($command, $io);

        $sourceDirectoryProperty = new ReflectionProperty(RenameByExifDateCommand::class, 'sourceDirectory');
        $sourceDirectoryProperty->setAccessible(true);
        $sourceDirectoryProperty->setValue($command, '/source');

        $method = new ReflectionMethod(RenameByExifDateCommand::class, 'groupFilesByDuplicateIdentifier');
        $method->setAccessible(true);

        /** @var FileDuplicateCollection $result */
        $result = $method->invoke($command, $iterator);

        self::assertTrue($result->has('20240101_120000.MOV'));

        $newDuplicate = $result->get('20240101_120000.MOV');
        self::assertInstanceOf(FileDuplicate::class, $newDuplicate);

        $files = iterator_to_array($newDuplicate->getFiles());
        self::assertCount(1, $files);
        self::assertSame($video->getPathname(), $files[0]->getPathname());
        self::assertSame($pairedTarget->getPathname(), $newDuplicate->getTarget()->getPathname());
    }

    private function createExifMetadataProvider(): ExifMetadataProvider
    {
        return new ExifMetadataProvider(
            new SafeExifReader(),
            new QuickTimeContentIdentifierExtractor(new SafeFileReader()),
        );
    }
}
