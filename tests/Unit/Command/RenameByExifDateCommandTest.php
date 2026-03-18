<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Command;

use DateTimeImmutable;
use MagicSunday\Renamer\Command\RenameByExifDateCommand;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Service\DuplicateDetectionService;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\HashSubGroupingService;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairing;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingCollection;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingService;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use Override;
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
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Verifies the RenameByExifDateCommand, the primary command for renaming photos
 * and videos by their EXIF capture date with Live Photo companion pairing.
 *
 * These tests cover:
 * - Command name registration ("rename:exif")
 * - Strategy wiring (ExifDateFilenameStrategy + TargetBasenameStrategy)
 * - Custom --target-filename-pattern propagation
 * - Live Photo pairing integration via LivePhotoPairingService
 * - Iterator rewind before the pairing pass
 * - End-to-end dry-run with real services and stub metadata
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(RenameByExifDateCommand::class)]
final class RenameByExifDateCommandTest extends TestCase
{
    /**
     * Verifies that the command registers under the name "rename:exif".
     */
    #[Test]
    public function configureExposesExifDateCommandWithAlias(): void
    {
        $command = new RenameByExifDateCommand(
            self::createStub(FileSystemServiceInterface::class),
            self::createStub(DuplicateDetectionServiceInterface::class),
            $this->createExifMetadataProvider(),
            self::createStub(LivePhotoPairingService::class),
        );

        self::assertSame('rename:exif', $command->getName());
    }

    /**
     * Verifies the full execute() flow: the command creates an ExifDateFilenameStrategy
     * with the user's --target-filename-pattern, pairs it with a TargetBasenameStrategy,
     * enables source-extension preservation, and wires the LivePhotoPairingService.
     *
     * Mock expectations validate that every service method is called with the correct
     * arguments and that the custom pattern "Ymd-His" is stored in the strategy instance.
     */
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
            ->with($expectedSourceDirectory, null)
            ->willReturn($iterator);

        $duplicateCollection = new FileDuplicateCollection();

        $capturedRenameStrategy    = null;
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

                    return $strategy instanceof TargetBasenameStrategy;
                }),
                $expectedSourceDirectory,
                $expectedTargetDirectory,
            )
            ->willReturn($duplicateCollection);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('createDuplicateFilenames')
            ->with(
                self::identicalTo($duplicateCollection),
                $expectedSourceDirectory,
                $expectedTargetDirectory,
                true,
            )
            ->willReturn($duplicateCollection);

        $duplicateDetectionService
            ->expects(self::atLeastOnce())
            ->method('getLastScannedFileCount')
            ->willReturn(0);

        $duplicateDetectionService
            ->expects(self::atLeastOnce())
            ->method('getNamingCollisions')
            ->willReturn(0);

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
                self::callback(static function (RenameOptions $options) use ($expectedSourceDirectory, $expectedTargetDirectory): bool {
                    self::assertTrue($options->dryRun);
                    self::assertFalse($options->skipDuplicates);
                    self::assertFalse($options->copyFiles);
                    self::assertFalse($options->listAll);
                    self::assertSame($expectedSourceDirectory, $options->sourceBaseDirectory);
                    self::assertSame($expectedTargetDirectory, $options->targetBaseDirectory);

                    return true;
                }),
                self::callback(static function (RenameResult $result): bool {
                    self::assertSame(0, $result->scannedFiles);

                    return true;
                }),
            );

        $command = new RenameByExifDateCommand(
            $fileSystemService,
            $duplicateDetectionService,
            $this->createExifMetadataProvider(),
            $livePhotoPairingService,
        );

        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([
            'source-directory'          => 'source-dir',
            'target-directory'          => 'target-dir',
            '--dry-run'                 => true,
            '--target-filename-pattern' => 'Ymd-His',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertInstanceOf(ExifDateFilenameStrategy::class, $capturedRenameStrategy);
        self::assertInstanceOf(TargetBasenameStrategy::class, $capturedDuplicateStrategy);

        $renamePatternProperty = new ReflectionProperty(ExifDateFilenameStrategy::class, 'targetFilenamePattern');

        self::assertSame('Ymd-His', $renamePatternProperty->getValue($capturedRenameStrategy));
    }

    /**
     * Verifies that groupFilesByDuplicateIdentifier() integrates the Live Photo
     * pairing results: a video with a mismatched source basename but matching
     * content identifier is added to the correct group, inherits the canonical's
     * base name as its target, and the progress bar advances for each inspected file.
     */
    #[Test]
    public function groupFilesByDuplicateIdentifierUsesFallbackPairsFromService(): void
    {
        $photo = new SplFileInfo('/source/IMG_0001.HEIC');
        $video = new SplFileInfo('/source/fullsizeoutput_1.MOV');

        self::assertNotSame(
            $photo->getBasename('.' . $photo->getExtension()),
            $video->getBasename('.' . $video->getExtension()),
        );
        $target       = new SplFileInfo('/target/20240101_120000.HEIC');
        $pairedTarget = new SplFileInfo('/source/20240101_120000.MOV');

        $existingDuplicate = new FileDuplicate()
            ->addFile($photo)
            ->setTarget($target);

        $duplicateCollection = new FileDuplicateCollection();
        $duplicateCollection->set('live-photo:content-id', $existingDuplicate);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([$photo, $video], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $fileSystemService = self::createStub(FileSystemServiceInterface::class);

        /** @var DuplicateDetectionServiceInterface&MockObject $duplicateDetectionService */
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);
        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::identicalTo($iterator),
                self::isInstanceOf(ExifDateFilenameStrategy::class),
                self::isInstanceOf(TargetBasenameStrategy::class),
                '/source',
                '/target',
            )
            ->willReturn($duplicateCollection);

        $duplicateDetectionService
            ->expects(self::atLeastOnce())
            ->method('getLastScannedFileCount')
            ->willReturn(2);

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
                mixed $iteratorArg,
                mixed $collection,
                mixed $resolver,
                callable $progressCallback,
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
        $ioProperty->setValue($command, $io);

        $sourceDirectoryProperty = new ReflectionProperty(RenameByExifDateCommand::class, 'sourceDirectory');
        $sourceDirectoryProperty->setValue($command, '/source');

        $targetDirectoryProperty = new ReflectionProperty(RenameByExifDateCommand::class, 'targetDirectory');
        $targetDirectoryProperty->setValue($command, '/target');

        $method = new ReflectionMethod(RenameByExifDateCommand::class, 'groupFilesByDuplicateIdentifier');

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
        self::assertSame(2, $capturedProgressBar->getProgress());
    }

    /**
     * Verifies that paired Live Photo videos appear in the dry-run output with
     * their expected target filenames, confirming that the pairing result is
     * threaded through grouping, duplicate detection, and the final rename listing.
     */
    #[Test]
    public function executeIncludesPairedLivePhotoVideoInDryRunRenameList(): void
    {
        $photo       = new SplFileInfo('/source-dir/IMG_0003.HEIC');
        $video       = new SplFileInfo('/source-dir/IMG_0003.MOV');
        $photoTarget = new SplFileInfo('/target-dir/20240101_120000.HEIC');
        $videoTarget = new SplFileInfo('/target-dir/20240101_120000.MOV');

        $duplicate = new FileDuplicate()
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

        $fileSystemService = new class($style, new RenameOutputRenderer($style), $iterator) extends FileSystemService {
            /**
             * @param RecursiveIteratorIterator<RecursiveArrayIterator<int, SplFileInfo>> $iterator
             */
            public function __construct(
                SymfonyStyle $io,
                RenameOutputRenderer $renderer,
                private readonly RecursiveIteratorIterator $iterator,
            ) {
                parent::__construct($io, $renderer);
            }

            #[Override]
            public function createFileIterator(
                string $directory,
                ?RecursiveIterator $recursiveIterator = null,
            ): RecursiveIteratorIterator {
                // @phpstan-ignore return.type
                return $this->iterator;
            }
        };

        /** @var DuplicateDetectionServiceInterface&MockObject $duplicateDetectionService */
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);
        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::identicalTo($iterator),
                self::isInstanceOf(ExifDateFilenameStrategy::class),
                self::isInstanceOf(TargetBasenameStrategy::class),
                '/source-dir',
                '/target-dir',
            )
            ->willReturn($duplicateCollection);

        $duplicateDetectionService
            ->expects(self::atLeastOnce())
            ->method('getLastScannedFileCount')
            ->willReturn(2);

        $duplicateDetectionService
            ->expects(self::atLeastOnce())
            ->method('getNamingCollisions')
            ->willReturn(0);

        $duplicateDetectionService
            ->expects(self::once())
            ->method('createDuplicateFilenames')
            ->with(
                self::callback(function (FileDuplicateCollection $collection) use ($photo, $video, $photoTarget, $videoTarget): bool {
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
                }),
                '/source-dir',
                '/target-dir',
                true,
            )
            ->willReturnCallback(static fn (FileDuplicateCollection $collection): FileDuplicateCollection => $collection);

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

        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([
            'source-directory' => '/source-dir',
            'target-directory' => '/target-dir',
            '--dry-run'        => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $bufferedOutput->fetch();
        self::assertStringContainsString('20240101_120000.MOV', $output);
    }

    /**
     * Verifies end-to-end execution with real services (no mocks) using a temporary
     * workspace with two files: a HEIC photo with an EXIF date and a MOV companion
     * paired via content identifier.
     *
     * The dry-run output must contain the MOV target filename with millisecond
     * precision inherited from the photo's capture time, confirming the complete
     * pipeline from metadata extraction through pairing to rename listing.
     */
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

            $fileSystemService = new FileSystemService($style, new RenameOutputRenderer($style));

            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $photoPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-01T12:00:00.123+00:00'),
                    'UUID-IPHONE-LIVEPHOTO',
                ),
            );
            $metadataExtractor->withResponse(
                $videoPath,
                new TemporalMetadata(null, 'UUID-IPHONE-LIVEPHOTO'),
            );

            $metadataProvider = new ExifMetadataProvider($metadataExtractor);

            $hashCalculator            = new SafeHashCalculator();
            $hashSubGroupingService    = new HashSubGroupingService($hashCalculator, $style);
            $duplicateDetectionService = new DuplicateDetectionService($style, $hashSubGroupingService, $hashSubGroupingService);
            $livePhotoPairingService   = new LivePhotoPairingService();

            $command = new RenameByExifDateCommand(
                $fileSystemService,
                $duplicateDetectionService,
                $metadataProvider,
                $livePhotoPairingService,
            );

            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source-directory' => $workspace,
                '--dry-run'        => true,
                '--list-all'       => true,
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

    /**
     * Verifies that the iterator is rewound exactly once before being passed to the
     * Live Photo pairing service. Without the rewind, the pairing pass would see an
     * exhausted iterator and find no companions.
     *
     * A custom RecursiveIteratorIterator subclass counts rewind() calls to assert
     * that exactly one rewind occurred before pairByContentIdentifier() was invoked.
     */
    #[Test]
    public function groupFilesByDuplicateIdentifierRewindsIteratorBeforePairing(): void
    {
        /** @var RecursiveArrayIterator<int, SplFileInfo> $emptyArrayIterator */
        $emptyArrayIterator = new RecursiveArrayIterator([]);

        $iterator = new class($emptyArrayIterator) extends RecursiveIteratorIterator {
            public int $rewindCalls = 0;

            /**
             * @param RecursiveArrayIterator<int, SplFileInfo> $iterator
             */
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

        $fileSystemService = self::createStub(FileSystemServiceInterface::class);

        /** @var DuplicateDetectionServiceInterface&MockObject $duplicateDetectionService */
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);
        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::identicalTo($iterator),
                self::isInstanceOf(ExifDateFilenameStrategy::class),
                self::isInstanceOf(TargetBasenameStrategy::class),
                '/source',
                '/target',
            )
            ->willReturn($duplicateCollection);

        $duplicateDetectionService
            ->expects(self::atLeastOnce())
            ->method('getLastScannedFileCount')
            ->willReturn(0);

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
        $ioProperty->setValue($command, $io);

        $sourceDirectoryProperty = new ReflectionProperty(RenameByExifDateCommand::class, 'sourceDirectory');
        $sourceDirectoryProperty->setValue($command, '/source');

        $targetDirectoryProperty = new ReflectionProperty(RenameByExifDateCommand::class, 'targetDirectory');
        $targetDirectoryProperty->setValue($command, '/target');

        $method = new ReflectionMethod(RenameByExifDateCommand::class, 'groupFilesByDuplicateIdentifier');

        $result = $method->invoke($command, $iterator);

        self::assertSame($duplicateCollection, $result);
    }

    /**
     * Verifies that a video with a completely different source basename than its
     * paired photo (e.g. "PXL_20240101_000002.MOV" vs "IMG_0002.HEIC") is still
     * paired by content identifier and receives a target whose base name matches
     * the canonical still image's target, including any sub-group suffix.
     *
     * This covers the real-world case where Google Pixel videos and Apple iPhone
     * photos share a Live Photo UUID but have different naming schemes.
     */
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

        $existingDuplicate = new FileDuplicate()
            ->addFile($photo)
            ->setTarget($targetWithSuffix);

        $duplicateCollection = new FileDuplicateCollection();
        $duplicateCollection->set('live-photo:content-id', $existingDuplicate);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([$photo, $video], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $fileSystemService = self::createStub(FileSystemServiceInterface::class);

        /** @var DuplicateDetectionServiceInterface&MockObject $duplicateDetectionService */
        $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);
        $duplicateDetectionService
            ->expects(self::once())
            ->method('groupFilesByDuplicateIdentifier')
            ->with(
                self::identicalTo($iterator),
                self::isInstanceOf(ExifDateFilenameStrategy::class),
                self::isInstanceOf(TargetBasenameStrategy::class),
                '/source',
                '/target',
            )
            ->willReturn($duplicateCollection);

        $duplicateDetectionService
            ->expects(self::atLeastOnce())
            ->method('getLastScannedFileCount')
            ->willReturn(2);

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
                    contentIdentifierResolver: static fn (SplFileInfo $file): ?string => match ($file->getPathname()) {
                        $photo->getPathname(), $video->getPathname() => 'content-id',
                        default => null,
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
        $ioProperty->setValue($command, $io);

        $sourceDirectoryProperty = new ReflectionProperty(RenameByExifDateCommand::class, 'sourceDirectory');
        $sourceDirectoryProperty->setValue($command, '/source');

        $targetDirectoryProperty = new ReflectionProperty(RenameByExifDateCommand::class, 'targetDirectory');
        $targetDirectoryProperty->setValue($command, '/target');

        $method = new ReflectionMethod(RenameByExifDateCommand::class, 'groupFilesByDuplicateIdentifier');

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
        self::assertSame(2, $capturedProgressBar->getProgress());

        $canonicalBasename = $duplicate->getTarget()->getBasename('.' . $duplicate->getTarget()->getExtension());
        $videoBasename     = $pairing->getTargetFile()->getBasename('.' . $pairing->getTargetFile()->getExtension());

        self::assertSame($canonicalBasename, $videoBasename);
    }

    private function createExifMetadataProvider(): ExifMetadataProvider
    {
        return new ExifMetadataProvider(new StubMetadataExtractor());
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
