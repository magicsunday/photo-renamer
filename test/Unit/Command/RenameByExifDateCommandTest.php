<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Command;

use MagicSunday\Renamer\Command\RenameByExifDateCommand;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Service\Dto\ExifMetadataResult;
use MagicSunday\Renamer\Service\Dto\LivePhotoPairing;
use MagicSunday\Renamer\Service\Dto\LivePhotoPairingCollection;
use MagicSunday\Renamer\Service\DuplicateDetectionService;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\LivePhotoPairingService;
use MagicSunday\Renamer\Service\SafeExifReader;
use MagicSunday\Renamer\Service\SafeFileReader;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\LivePhotoContentIdentifierStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ExifRawMetadata;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifMetadataProvider;
use MagicSunday\Renamer\Strategy\RenameStrategy\QuickTime\QuickTimeContentIdentifierExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use ReflectionProperty;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Override;

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

        $expectedSourceDirectory = $this->buildExpectedAbsolutePath('source-dir');
        $expectedTargetDirectory = $this->buildExpectedAbsolutePath('target-dir');

        $fileSystemService
            ->expects(self::once())
            ->method('createFileIterator')
            ->with($expectedSourceDirectory)
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

        $capturedRenameStrategy = null;
        $capturedDuplicateStrategy = null;

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::identicalTo($iterator),
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
                self::isTrue(),
            )
            ->willReturn(LivePhotoPairingCollection::empty());

        $fileSystemService
            ->expects(self::once())
            ->method('renameFiles')
            ->with(
                self::identicalTo($duplicateCollection),
                true,
                false,
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
    public function groupFilesByDuplicateIdentifierUsesFallbackPairsFromService(): void
    {
        $photo = new SplFileInfo('/source/IMG_0001.HEIC');
        $video = new SplFileInfo('/source/fullsizeoutput_1.MOV');

        self::assertNotSame(
            $photo->getBasename('.' . $photo->getExtension()),
            $video->getBasename('.' . $video->getExtension()),
        );
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
            ->method('countFiles')
            ->with(self::identicalTo($iterator))
            ->willReturn(2);

        /** @var DuplicateDetectionServiceInterface&MockObject $duplicateDetectionService */
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);
        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::identicalTo($iterator),
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
                self::isTrue(),
            )
            ->willReturnCallback(static function (
                $iteratorArg,
                $collection,
                $resolver,
                $progressCallback,
                bool $matchByContentIdentifier,
            ) use ($video, $pairedTarget): LivePhotoPairingCollection {
                self::assertTrue($matchByContentIdentifier);

                $progressCallback();
                $progressCallback();

                return LivePhotoPairingCollection::fromPairings(
                    new LivePhotoPairing($video, $pairedTarget, 'live-photo:content-id', 'content-id'),
                );
            });

        $command = new RenameByExifDateCommand(
            $fileSystemService,
            $duplicateDetectionService,
            $this->createExifMetadataProvider(),
            $livePhotoPairingService,
        );

        $capturedProgressBar = null;

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects(self::atLeastOnce())->method('text');
        $io->expects(self::exactly(2))->method('newLine');
        $io
            ->expects(self::once())
            ->method('createProgressBar')
            ->with(2)
            ->willReturnCallback(static function (int $max) use (&$capturedProgressBar): ProgressBar {
                $capturedProgressBar = new ProgressBar(new NullOutput(), $max);

                return $capturedProgressBar;
            });

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

        self::assertTrue($result->has('live-photo:content-id'));

        $duplicate = $result->get('live-photo:content-id');
        self::assertSame($existingDuplicate, $duplicate);

        $files = iterator_to_array($duplicate->getFiles());
        self::assertCount(2, $files);
        self::assertSame($photo->getPathname(), $files[0]->getPathname());
        self::assertSame($video->getPathname(), $files[1]->getPathname());
        self::assertSame($target->getPathname(), $duplicate->getTarget()->getPathname());
        self::assertInstanceOf(ProgressBar::class, $capturedProgressBar);
        self::assertSame(2, $capturedProgressBar?->getProgress());
    }

    #[Test]
    public function executeIncludesPairedLivePhotoVideoInDryRunRenameList(): void
    {
        $photo       = new SplFileInfo('/source-dir/IMG_0003.HEIC');
        $video       = new SplFileInfo('/source-dir/IMG_0003.MOV');
        $photoTarget = new SplFileInfo('/target-dir/20240101_120000.HEIC');
        $videoTarget = new SplFileInfo('/target-dir/20240101_120000.MOV');

        $duplicate = (new FileDuplicate())
            ->addFile($photo)
            ->addFile($video)
            ->setTarget($photoTarget);

        $duplicateCollection = new FileDuplicateCollection();
        $duplicateCollection->set('live-photo:content-id', $duplicate);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([$photo, $video], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $bufferedOutput = new BufferedOutput();
        $style          = new SymfonyStyle(new ArrayInput([]), $bufferedOutput);

        $fileSystemService = new class($style, $iterator) extends FileSystemService {
            public function __construct(
                SymfonyStyle $io,
                private readonly RecursiveIteratorIterator $iterator,
            ) {
                parent::__construct($io);
            }

            #[Override]
            public function createFileIterator(
                string $directory,
                ?RecursiveIterator $recursiveIterator = null,
            ): RecursiveIteratorIterator {
                return $this->iterator;
            }

            #[Override]
            public function countFiles(RecursiveIteratorIterator $iterator): int
            {
                return 2;
            }
        };

        /** @var DuplicateDetectionServiceInterface&MockObject $duplicateDetectionService */
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);
        $duplicateDetectionService
            ->expects(self::once())
            ->method('setSourceDirectory')
            ->with('/source-dir')
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setTargetDirectory')
            ->with('/target-dir')
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setListAll')
            ->with(false)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('setUseFileExtensionFromSource')
            ->with(true)
            ->willReturnSelf();

        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::identicalTo($iterator),
                self::isInstanceOf(ExifDateFilenameStrategy::class),
                self::isInstanceOf(LivePhotoContentIdentifierStrategy::class),
            )
            ->willReturn($duplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('createDuplicateFilenames')
            ->with(self::callback(function (FileDuplicateCollection $collection) use ($photo, $video, $photoTarget, $videoTarget): bool {
                self::assertTrue($collection->has('live-photo:content-id'));

                $duplicate = $collection->get('live-photo:content-id');
                self::assertInstanceOf(FileDuplicate::class, $duplicate);

                $files = iterator_to_array($duplicate->getFiles());
                self::assertCount(2, $files);
                self::assertSame($photo->getPathname(), $files[0]->getPathname());
                self::assertSame($video->getPathname(), $files[1]->getPathname());

                $duplicate->setRenames(new RenameList([
                    new Rename($photo, $photoTarget),
                    new Rename($video, $videoTarget),
                ]));

                return true;
            }))
            ->willReturnCallback(static function (FileDuplicateCollection $collection): FileDuplicateCollection {
                return $collection;
            });

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
                self::isTrue(),
            )
            ->willReturn(LivePhotoPairingCollection::empty());

        $command = new RenameByExifDateCommand(
            $fileSystemService,
            $duplicateDetectionService,
            $this->createExifMetadataProvider(),
            $livePhotoPairingService,
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'source-directory' => '/source-dir',
            'target-directory' => '/target-dir',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $bufferedOutput->fetch();
        self::assertStringContainsString('20240101_120000.MOV', $output);
    }

    #[Test]
    public function executeWithRealServicesListsLivePhotoVideoRenames(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('live_photo_', true);

        if (!mkdir($workspace, 0o755) && !is_dir($workspace)) {
            self::fail('Unable to create temporary workspace.');
        }

        $photoPath = $workspace . DIRECTORY_SEPARATOR . '2-image.HEIC';
        $videoPath = $workspace . DIRECTORY_SEPARATOR . 'movie.MOV';

        file_put_contents($photoPath, 'photo');
        file_put_contents($videoPath, 'video');

        try {
            $output = new BufferedOutput();
            $style  = new SymfonyStyle(new ArrayInput([]), $output);

            $fileSystemService = new FileSystemService($style);

            $exifReader = new class($photoPath) extends SafeExifReader {
                public function __construct(private readonly string $photoPath)
                {
                }

                #[Override]
                public function read(SplFileInfo $file): ExifMetadataResult
                {
                    if ($file->getPathname() !== $this->photoPath) {
                        return ExifMetadataResult::withoutMetadata();
                    }

                    return ExifMetadataResult::withMetadata(ExifRawMetadata::fromArray([
                        'DateTimeOriginal' => '2024:01:01 12:00:00',
                        'SubSecTimeOriginal' => '123',
                        'ContentIdentifier' => 'UUID-IPHONE-LIVEPHOTO',
                    ]));
                }
            };

            $fileReader = new class($videoPath) extends SafeFileReader {
                public function __construct(private readonly string $videoPath)
                {
                }

                #[Override]
                public function read(SplFileInfo $file): string
                {
                    if ($file->getPathname() !== $this->videoPath) {
                        return '';
                    }

                    return base64_decode('AAAAj21vb3YAAACHdWR0YQAAAH9tZXRhAAAAAAAAAD5rZXlzAAAAAAAAAAEAAAAuAAAAAGNvbS5hcHBsZS5xdWlja3RpbWUuY29udGVudC5pZGVudGlmaWVyAAAANWlsc3QAAAAtAAAAAQAAACVkYXRhAAAAAQAAAABVVUlELUlQSE9ORS1MSVZFUEhPVE8=', true) ?: '';
                }
            };

            $metadataProvider = new ExifMetadataProvider(
                $exifReader,
                new QuickTimeContentIdentifierExtractor($fileReader),
            );

            $duplicateDetectionService = new DuplicateDetectionService($fileSystemService, $style);
            $livePhotoPairingService   = new LivePhotoPairingService();

            $command = new RenameByExifDateCommand(
                $fileSystemService,
                $duplicateDetectionService,
                $metadataProvider,
                $livePhotoPairingService,
            );

            $tester = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
                '--dry-run' => true,
                '--list-all' => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $consoleOutput = $output->fetch();
            self::assertStringContainsString('2024-01-01_12-00-00-123.MOV', $consoleOutput);
        } finally {
            @unlink($photoPath);
            @unlink($videoPath);
            @rmdir($workspace);
        }
    }

    #[Test]
    public function groupFilesByDuplicateIdentifierRewindsIteratorBeforePairing(): void
    {
        $iterator = new class(new RecursiveArrayIterator([])) extends RecursiveIteratorIterator {
            public int $rewindCalls = 0;

            public function __construct(RecursiveArrayIterator $iterator)
            {
                parent::__construct($iterator);
            }

            public function rewind(): void
            {
                ++$this->rewindCalls;

                parent::rewind();
            }
        };

        $duplicateCollection = new FileDuplicateCollection();

        /** @var FileSystemServiceInterface&MockObject $fileSystemService */
        $fileSystemService = $this->createMock(FileSystemServiceInterface::class);
        $fileSystemService
            ->expects(self::once())
            ->method('countFiles')
            ->with(self::identicalTo($iterator))
            ->willReturn(0);

        /** @var DuplicateDetectionServiceInterface&MockObject $duplicateDetectionService */
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);
        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::identicalTo($iterator),
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
                self::isTrue(),
            )
            ->willReturnCallback(static function (
                RecursiveIteratorIterator $iteratorArg,
                FileDuplicateCollection $collection,
                callable $resolver,
                callable $progressCallback,
                bool $matchByContentIdentifier,
            ) use ($iterator): LivePhotoPairingCollection {
                self::assertSame($iterator, $iteratorArg);
                self::assertTrue($matchByContentIdentifier);
                self::assertSame(1, $iterator->rewindCalls);

                return LivePhotoPairingCollection::empty();
            });

        $command = new RenameByExifDateCommand(
            $fileSystemService,
            $duplicateDetectionService,
            $this->createExifMetadataProvider(),
            $livePhotoPairingService,
        );

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects(self::atLeastOnce())->method('text');
        $io->expects(self::exactly(2))->method('newLine');

        $ioProperty = new ReflectionProperty(RenameByExifDateCommand::class, 'io');
        $ioProperty->setAccessible(true);
        $ioProperty->setValue($command, $io);

        $sourceDirectoryProperty = new ReflectionProperty(RenameByExifDateCommand::class, 'sourceDirectory');
        $sourceDirectoryProperty->setAccessible(true);
        $sourceDirectoryProperty->setValue($command, '/source');

        $method = new ReflectionMethod(RenameByExifDateCommand::class, 'groupFilesByDuplicateIdentifier');
        $method->setAccessible(true);

        $result = $method->invoke($command, $iterator);

        self::assertSame($duplicateCollection, $result);
    }

    #[Test]
    public function groupFilesByDuplicateIdentifierPairsMismatchedBasenameVideoByContentIdentifier(): void
    {
        $photo = new SplFileInfo('/source/IMG_0002.HEIC');
        $video = new SplFileInfo('/source/PXL_20240101_000002.MOV');

        self::assertNotSame(
            $photo->getBasename('.' . $photo->getExtension()),
            $video->getBasename('.' . $video->getExtension()),
        );
        $targetWithSuffix = new SplFileInfo('/target/20240101_120000-1.HEIC');

        $existingDuplicate = (new FileDuplicate())
            ->addFile($photo)
            ->setTarget($targetWithSuffix);

        $duplicateCollection = new FileDuplicateCollection();
        $duplicateCollection->set('live-photo:content-id', $existingDuplicate);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([$photo, $video], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        /** @var FileSystemServiceInterface&MockObject $fileSystemService */
        $fileSystemService = $this->createMock(FileSystemServiceInterface::class);
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
                self::identicalTo($iterator),
                self::isInstanceOf(ExifDateFilenameStrategy::class),
                self::isInstanceOf(LivePhotoContentIdentifierStrategy::class),
            )
            ->willReturn($duplicateCollection);

        $capturedPairings = null;

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
                self::isTrue(),
            )
            ->willReturnCallback(static function (
                RecursiveIteratorIterator $iteratorArg,
                FileDuplicateCollection $collection,
                callable $resolver,
                callable $progressCallback,
                bool $matchByContentIdentifier,
            ) use (&$capturedPairings, $photo, $video): LivePhotoPairingCollection {
                self::assertTrue($matchByContentIdentifier);

                $service = new LivePhotoPairingService();

                $capturedPairings = $service->pairByContentIdentifier(
                    iterator: $iteratorArg,
                    fileDuplicateCollection: $collection,
                    contentIdentifierResolver: static function (SplFileInfo $file) use ($photo, $video): ?string {
                        return match ($file->getPathname()) {
                            $photo->getPathname(), $video->getPathname() => 'content-id',
                            default => null,
                        };
                    },
                    onFileInspected: $progressCallback,
                    matchByContentIdentifier: true,
                );

                return $capturedPairings;
            });

        $command = new RenameByExifDateCommand(
            $fileSystemService,
            $duplicateDetectionService,
            $this->createExifMetadataProvider(),
            $livePhotoPairingService,
        );

        $capturedProgressBar = null;

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects(self::atLeastOnce())->method('text');
        $io->expects(self::exactly(2))->method('newLine');
        $io
            ->expects(self::once())
            ->method('createProgressBar')
            ->with(2)
            ->willReturnCallback(static function (int $max) use (&$capturedProgressBar): ProgressBar {
                $capturedProgressBar = new ProgressBar(new NullOutput(), $max);

                return $capturedProgressBar;
            });

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

        self::assertNotNull($capturedPairings);
        $pairings = $capturedPairings->toList();
        self::assertCount(1, $pairings);

        /** @var LivePhotoPairing $pairing */
        $pairing = $pairings[0];

        $duplicate = $result->get('live-photo:content-id');
        self::assertSame($existingDuplicate, $duplicate);

        $files = iterator_to_array($duplicate->getFiles());
        self::assertCount(2, $files);
        self::assertSame($photo->getPathname(), $files[0]->getPathname());
        self::assertSame($video->getPathname(), $files[1]->getPathname());
        self::assertInstanceOf(ProgressBar::class, $capturedProgressBar);
        self::assertSame(2, $capturedProgressBar?->getProgress());

        $canonicalBasename = $duplicate->getTarget()->getBasename('.' . $duplicate->getTarget()->getExtension());
        $videoBasename = $pairing->getTargetFile()->getBasename('.' . $pairing->getTargetFile()->getExtension());

        self::assertSame($canonicalBasename, $videoBasename);
    }

    private function createExifMetadataProvider(): ExifMetadataProvider
    {
        return new ExifMetadataProvider(
            new SafeExifReader(),
            new QuickTimeContentIdentifierExtractor(new SafeFileReader()),
        );
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
