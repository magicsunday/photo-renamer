<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use FilesystemIterator;
use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Service\DuplicateDetectionService;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

use function assert;
use function file_put_contents;
use function is_dir;
use function iterator_to_array;
use function mkdir;
use function preg_match;
use function preg_quote;
use function rename;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(DuplicateDetectionService::class)]
#[CoversClass(FileDuplicateCollection::class)]
#[CoversClass(FileDuplicate::class)]
#[CoversClass(RenameList::class)]
#[CoversClass(Rename::class)]
final class DuplicateDetectionServiceTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        $this->tempDirectories = [];

        parent::tearDown();
    }

    #[Test]
    public function groupFilesByDuplicateIdentifierDisplaysEtaInformation(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'source.jpg';
        file_put_contents($sourceFile, 'source');

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory);

        $iterator = $this->createIterator($sourceDirectory);

        $renameStrategy = $this->createMock(RenameStrategyInterface::class);
        $renameStrategy
            ->expects(self::once())
            ->method('generateFilename')
            ->willReturn('target.jpg');

        $duplicateIdentifierStrategy = $this->createMock(DuplicateIdentifierStrategyInterface::class);
        $duplicateIdentifierStrategy
            ->expects(self::once())
            ->method('generateIdentifier')
            ->willReturn('identifier');

        $service->groupFilesByDuplicateIdentifier(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
        );

        $progressOutput = $output->fetch();

        self::assertStringContainsString('| ETA:', $progressOutput);
        self::assertStringContainsString('| Remaining:', $progressOutput);
    }

    #[Test]
    public function createDuplicateFilenamesDisplaysEtaInformation(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'source.jpg';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        file_put_contents($sourceFile, 'source');
        file_put_contents($targetFile, 'target');

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory);

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($sourceFile))
            ->setTarget(new SplFileInfo($targetFile));

        $fileDuplicateCollection = new FileDuplicateCollection();
        $fileDuplicateCollection->set('identifier', $fileDuplicate);

        $service->createDuplicateFilenames($fileDuplicateCollection);

        $progressOutput = $output->fetch();

        self::assertStringContainsString('| ETA:', $progressOutput);
        self::assertStringContainsString('| Remaining:', $progressOutput);
    }

    #[Test]
    public function groupFilesByDuplicateIdentifierHandlesTargetFilenameException(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'source.jpg';
        file_put_contents($sourceFile, 'source');

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory);

        $iterator = $this->createIterator($sourceDirectory);

        $renameStrategy = $this->createMock(RenameStrategyInterface::class);
        $renameStrategy
            ->expects(self::once())
            ->method('generateFilename')
            ->willThrowException(new TargetFilenameException('boom'));

        $duplicateIdentifierStrategy = $this->createMock(DuplicateIdentifierStrategyInterface::class);
        $duplicateIdentifierStrategy
            ->expects(self::never())
            ->method('generateIdentifier');

        $collection = $service->groupFilesByDuplicateIdentifier(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
        );

        $progressOutput = $output->fetch();

        self::assertCount(0, $collection);
        self::assertStringContainsString('boom', $progressOutput);
    }

    #[Test]
    public function groupFilesByDuplicateIdentifierPrefersStillTargetForLivePhotos(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $videoPath = $sourceDirectory . DIRECTORY_SEPARATOR . '0001.MOV';
        $photoPath = $sourceDirectory . DIRECTORY_SEPARATOR . '9999.HEIC';

        file_put_contents($videoPath, 'video');
        file_put_contents($photoPath, 'photo');

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([
                new SplFileInfo($videoPath),
                new SplFileInfo($photoPath),
            ], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $renameStrategy = $this->createMock(RenameStrategyInterface::class);
        $renameStrategy
            ->expects(self::exactly(2))
            ->method('generateFilename')
            ->willReturnCallback(static fn (SplFileInfo $file): string => match ($file->getPathname()) {
                $videoPath => 'video-target.mov',
                $photoPath => 'photo-target.heic',
                default    => throw new RuntimeException('Unexpected file: ' . $file->getPathname()),
            });

        $duplicateIdentifierStrategy = $this->createMock(DuplicateIdentifierStrategyInterface::class);
        $duplicateIdentifierStrategy
            ->expects(self::exactly(2))
            ->method('generateIdentifier')
            ->willReturn('live-photo:content-id');

        $collection = $service->groupFilesByDuplicateIdentifier(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
        );

        $duplicate = $collection->get('live-photo:content-id');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);
        self::assertSame(
            $targetDirectory . DIRECTORY_SEPARATOR . 'photo-target.heic',
            $duplicate->getTarget()->getPathname(),
        );

        $files = iterator_to_array($duplicate->getFiles());
        self::assertCount(2, $files);
        self::assertSame($videoPath, $files[0]->getPathname());
        self::assertSame($photoPath, $files[1]->getPathname());
    }

    #[Test]
    public function groupFilesByDuplicateIdentifierAddsPendingFilesWithSharedContentIdentifier(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $photoPath = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.HEIC';
        $videoPath = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.MOV';

        file_put_contents($photoPath, 'photo');
        file_put_contents($videoPath, 'video');

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([
                new SplFileInfo($photoPath),
                new SplFileInfo($videoPath),
            ], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $renameStrategy = new DummyLivePhotoRenameStrategy([
            $photoPath => '20240101_120000.HEIC',
            $videoPath => null,
        ], [
            $photoPath => 'content-id',
            $videoPath => 'content-id',
        ]);

        $duplicateIdentifierStrategy = new DummyLivePhotoDuplicateIdentifierStrategy([
            $photoPath => 'content-id',
        ]);

        $collection = $service->groupFilesByDuplicateIdentifier(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
        );

        self::assertTrue($collection->has('live-photo:content-id'));

        $duplicate = $collection->get('live-photo:content-id');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $files = iterator_to_array($duplicate->getFiles());
        self::assertCount(2, $files);

        $paths = [
            $files[0]->getPathname(),
            $files[1]->getPathname(),
        ];

        self::assertContains($photoPath, $paths);
        self::assertContains($videoPath, $paths);
    }

    #[Test]
    public function groupFilesByDuplicateIdentifierAddsVideoEncounteredBeforePhoto(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $photoPath = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.HEIC';
        $videoPath = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.MOV';

        file_put_contents($photoPath, 'photo');
        file_put_contents($videoPath, 'video');

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([
                new SplFileInfo($videoPath),
                new SplFileInfo($photoPath),
            ], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $renameStrategy = new DummyLivePhotoRenameStrategy([
            $photoPath => '20240101_130000.HEIC',
            $videoPath => null,
        ], [
            $photoPath => 'content-id-2',
            $videoPath => 'content-id-2',
        ]);

        $duplicateIdentifierStrategy = new DummyLivePhotoDuplicateIdentifierStrategy([
            $photoPath => 'content-id-2',
        ]);

        $collection = $service->groupFilesByDuplicateIdentifier(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
        );

        self::assertTrue($collection->has('live-photo:content-id-2'));

        $duplicate = $collection->get('live-photo:content-id-2');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $files = iterator_to_array($duplicate->getFiles());
        self::assertCount(2, $files);

        $paths = [
            $files[0]->getPathname(),
            $files[1]->getPathname(),
        ];

        self::assertContains($photoPath, $paths);
        self::assertContains($videoPath, $paths);
    }

    #[Test]
    public function createDuplicateFilenamesUsesSourceExtensionWhenConfigured(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $jpgFile    = $sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg';
        $pngFile    = $sourceDirectory . DIRECTORY_SEPARATOR . 'photo.png';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'renamed.jpg';

        // Same content so sub-grouping treats them as genuine duplicates.
        file_put_contents($jpgFile, 'same-content');
        file_put_contents($pngFile, 'same-content');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($jpgFile))
            ->addFile(new SplFileInfo($pngFile))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory)
            ->setUseFileExtensionFromSource(true);

        $service->createDuplicateFilenames($collection);

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(2, $renames);
        self::assertSame(
            $targetDirectory . DIRECTORY_SEPARATOR . 'renamed.jpg',
            $renames[0]->getTarget()->getPathname(),
        );
        self::assertStringContainsString(
            FileSystemService::DUPLICATE_IDENTIFIER,
            $renames[1]->getTarget()->getFilename(),
        );
        self::assertStringEndsWith('.png', $renames[1]->getTarget()->getFilename());
    }

    #[Test]
    public function createDuplicateFilenamesKeepsLivePhotoVideoWithoutDuplicateSuffix(): void
    {
        [$service, $output, $fileSystemService] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $photoSource     = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.HEIC';
        $videoSource     = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.MOV';
        $canonicalTarget = $targetDirectory . DIRECTORY_SEPARATOR . '20240101_120000.HEIC';

        file_put_contents($photoSource, 'photo');
        file_put_contents($videoSource, 'video');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($photoSource))
            ->addFile(new SplFileInfo($videoSource))
            ->setTarget(new SplFileInfo($canonicalTarget));

        $collection = new FileDuplicateCollection();
        $collection->set('live-photo:content-id', $fileDuplicate);

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory)
            ->setUseFileExtensionFromSource(true);

        $service->createDuplicateFilenames($collection);

        $duplicate = $collection->get('live-photo:content-id');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(2, $renames);

        $expectedCanonicalTarget = $targetDirectory . DIRECTORY_SEPARATOR . '20240101_120000.HEIC';
        $expectedVideoTarget     = $targetDirectory . DIRECTORY_SEPARATOR . '20240101_120000.MOV';

        $canonicalRename = null;
        $videoRename     = null;

        foreach ($renames as $rename) {
            if ($rename->getTarget()->getPathname() === $expectedCanonicalTarget) {
                $canonicalRename = $rename;
            }

            if ($rename->getSource()->getPathname() === $videoSource) {
                $videoRename = $rename;
            }
        }

        self::assertInstanceOf(Rename::class, $canonicalRename);
        self::assertSame($photoSource, $canonicalRename->getSource()->getPathname());
        self::assertSame($expectedCanonicalTarget, $canonicalRename->getTarget()->getPathname());

        self::assertInstanceOf(Rename::class, $videoRename);
        self::assertSame($expectedVideoTarget, $videoRename->getTarget()->getPathname());
        self::assertStringNotContainsString(
            FileSystemService::DUPLICATE_IDENTIFIER,
            $videoRename->getTarget()->getFilename(),
        );

        // Clear progress output from duplicate generation.
        $output->fetch();

        $fileSystemService->renameFiles($collection, true);

        $renameOutput = $output->fetch();

        self::assertStringContainsString('[R]', $renameOutput);
        self::assertStringContainsString($expectedVideoTarget, $renameOutput);
        self::assertStringNotContainsString(FileSystemService::DUPLICATE_IDENTIFIER, $renameOutput);
    }

    #[Test]
    public function createDuplicateFilenamesGeneratesIncrementalDuplicateTargetsForIdenticalContent(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $sourceOne  = $sourceDirectory . DIRECTORY_SEPARATOR . 'one.jpg';
        $sourceTwo  = $sourceDirectory . DIRECTORY_SEPARATOR . 'two.jpg';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'renamed.jpg';

        // Same content so sub-grouping treats them as genuine duplicates.
        file_put_contents($sourceOne, 'same-content');
        file_put_contents($sourceTwo, 'same-content');
        file_put_contents($targetFile, 'existing');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($sourceOne))
            ->addFile(new SplFileInfo($sourceTwo))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory);

        $service->createDuplicateFilenames($collection);

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(2, $renames);
        self::assertSame(
            $targetDirectory . DIRECTORY_SEPARATOR . 'renamed-duplicate-001.jpg',
            $renames[0]->getTarget()->getPathname(),
        );
        self::assertSame(
            $targetDirectory . DIRECTORY_SEPARATOR . 'renamed-duplicate-002.jpg',
            $renames[1]->getTarget()->getPathname(),
        );
    }

    #[Test]
    public function createDuplicateFilenamesPreservesRelativePathForNestedDuplicates(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $nestedDirectory = $sourceDirectory . DIRECTORY_SEPARATOR . 'nested';

        if (!mkdir($nestedDirectory, 0777, true) && !is_dir($nestedDirectory)) {
            self::fail('Failed to create nested directory: ' . $nestedDirectory);
        }

        $rootFile            = $sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg';
        $duplicateFile       = $nestedDirectory . DIRECTORY_SEPARATOR . 'photo.jpg';
        $preRenamedDuplicate = $nestedDirectory . DIRECTORY_SEPARATOR . 'photo'
            . FileSystemService::DUPLICATE_IDENTIFIER . '001.jpg';

        file_put_contents($rootFile, 'same-content');
        file_put_contents($duplicateFile, 'same-content');
        file_put_contents($preRenamedDuplicate, 'same-content');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($rootFile))
            ->addFile(new SplFileInfo($duplicateFile))
            ->addFile(new SplFileInfo($preRenamedDuplicate))
            ->setTarget(new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg'));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($sourceDirectory);

        $service->createDuplicateFilenames($collection);

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(3, $renames);

        $renamesBySource = [];

        foreach ($renames as $rename) {
            $renamesBySource[$rename->getSource()->getPathname()] = $rename;
        }

        self::assertArrayHasKey($rootFile, $renamesBySource);
        self::assertSame(
            $sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg',
            $renamesBySource[$rootFile]->getTarget()->getPathname(),
        );

        self::assertArrayHasKey($duplicateFile, $renamesBySource);
        self::assertStringStartsWith(
            $nestedDirectory . DIRECTORY_SEPARATOR,
            $renamesBySource[$duplicateFile]->getTarget()->getPathname(),
        );

        self::assertArrayHasKey($preRenamedDuplicate, $renamesBySource);

        // Idempotency: a file already named with a duplicate suffix keeps its name.
        self::assertSame(
            $preRenamedDuplicate,
            $renamesBySource[$preRenamedDuplicate]->getTarget()->getPathname(),
        );

        $pattern = '/' . preg_quote(FileSystemService::DUPLICATE_IDENTIFIER, '/') . '(\d{3})\.jpg$/';

        self::assertSame(
            1,
            preg_match($pattern, $renamesBySource[$duplicateFile]->getTarget()->getFilename(), $firstMatch),
            'First duplicate rename should include a numeric duplicate suffix.',
        );
        self::assertSame(
            1,
            preg_match($pattern, $renamesBySource[$preRenamedDuplicate]->getTarget()->getFilename(), $secondMatch),
            'Pre-renamed file should keep its numeric duplicate suffix.',
        );
        self::assertNotSame(
            $firstMatch[1],
            $secondMatch[1],
            'Duplicate suffixes must not collide.',
        );
    }

    #[Test]
    public function createDuplicateFilenamesKeepsCanonicalEntriesWhenListAllEnabled(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $canonicalSource = $sourceDirectory . DIRECTORY_SEPARATOR . 'original.jpg';
        $duplicateSource = $sourceDirectory . DIRECTORY_SEPARATOR . 'duplicate.jpg';
        $targetPath      = $targetDirectory . DIRECTORY_SEPARATOR . 'renamed.jpg';

        // Same content so sub-grouping treats them as genuine duplicates.
        file_put_contents($canonicalSource, 'same-content');
        file_put_contents($duplicateSource, 'same-content');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($canonicalSource))
            ->addFile(new SplFileInfo($duplicateSource))
            ->setTarget(new SplFileInfo($targetPath));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory)
            ->setListAll(true);

        $service->createDuplicateFilenames($collection);

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(2, $renames);
        self::assertSame($canonicalSource, $renames[0]->getSource()->getPathname());
        self::assertSame($targetPath, $renames[0]->getTarget()->getPathname());
        self::assertSame(
            $targetDirectory . DIRECTORY_SEPARATOR . 'renamed-duplicate-001.jpg',
            $renames[1]->getTarget()->getPathname(),
        );
    }

    #[Test]
    public function createDuplicateFilenamesKeepsExistingDuplicateSuffixOnSubsequentRun(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'source.jpg';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        file_put_contents($sourceFile, 'source');
        file_put_contents($targetFile, 'target');

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory);

        $initialDuplicate = new FileDuplicate();
        $initialDuplicate
            ->addFile(new SplFileInfo($sourceFile))
            ->setTarget(new SplFileInfo($targetFile));

        $initialCollection = new FileDuplicateCollection();
        $initialCollection->set('identifier', $initialDuplicate);

        $service->createDuplicateFilenames($initialCollection);

        $firstDuplicate = $initialCollection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $firstDuplicate);

        $firstRenames = iterator_to_array($firstDuplicate->getRenames());
        self::assertCount(1, $firstRenames);

        $expectedTargetPath = $firstRenames[0]->getTarget()->getPathname();
        self::assertStringContainsString(
            FileSystemService::DUPLICATE_IDENTIFIER . '001',
            $firstRenames[0]->getTarget()->getFilename(),
        );

        self::assertTrue(
            rename($sourceFile, $expectedTargetPath),
            'Failed to move source file to duplicate target path.',
        );

        $service->setSourceDirectory($targetDirectory);

        $subsequentDuplicate = new FileDuplicate();
        $subsequentDuplicate
            ->addFile(new SplFileInfo($expectedTargetPath))
            ->setTarget(new SplFileInfo($targetFile));

        $subsequentCollection = new FileDuplicateCollection();
        $subsequentCollection->set('identifier', $subsequentDuplicate);

        $service->createDuplicateFilenames($subsequentCollection);

        $secondDuplicate = $subsequentCollection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $secondDuplicate);

        $renames = iterator_to_array($secondDuplicate->getRenames());
        self::assertCount(1, $renames);
        self::assertSame($expectedTargetPath, $renames[0]->getTarget()->getPathname());
    }

    #[Test]
    public function getTargetPathnameRetainsNestedDirectoriesWithDuplicateNames(): void
    {
        [$service] = $this->createService();

        $sourceRoot      = $this->createTempDirectory();
        $sourceDirectory = $sourceRoot . DIRECTORY_SEPARATOR . 'Photos';
        $nestedDirectory = $sourceDirectory . DIRECTORY_SEPARATOR . 'Photos';
        $targetDirectory = $this->createTempDirectory();

        if (!mkdir($sourceDirectory, 0777, true) && !is_dir($sourceDirectory)) {
            self::fail('Failed to create source directory: ' . $sourceDirectory);
        }

        if (!mkdir($nestedDirectory, 0777, true) && !is_dir($nestedDirectory)) {
            self::fail('Failed to create nested directory: ' . $nestedDirectory);
        }

        $sourceFile = $nestedDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        file_put_contents($sourceFile, 'image');

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory);

        $targetPathname = $service->getTargetPathname(new SplFileInfo($sourceFile), 'renamed.jpg');

        self::assertSame(
            $targetDirectory . DIRECTORY_SEPARATOR . 'Photos' . DIRECTORY_SEPARATOR . 'renamed.jpg',
            $targetPathname,
        );
    }

    #[Test]
    public function getTargetPathnamePreservesRelativeDepthForAbsoluteDirectories(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $nestedSource = $sourceDirectory . DIRECTORY_SEPARATOR . 'nested';

        if (!mkdir($nestedSource) && !is_dir($nestedSource)) {
            self::fail('Failed to create nested source directory: ' . $nestedSource);
        }

        $sourceFile = $nestedSource . DIRECTORY_SEPARATOR . 'example.jpg';
        file_put_contents($sourceFile, 'example');

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory);

        $targetPathname = $service->getTargetPathname(new SplFileInfo($sourceFile), 'renamed.jpg');

        self::assertSame(
            $targetDirectory . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'renamed.jpg',
            $targetPathname,
        );
    }

    #[Test]
    public function createDuplicateFilenamesAssignsSequentialNumbersForDistinctContent(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA = $sourceDirectory . DIRECTORY_SEPARATOR . 'photo_a.jpg';
        $fileB = $sourceDirectory . DIRECTORY_SEPARATOR . 'photo_b.jpg';

        file_put_contents($fileA, 'content-of-file-A');
        file_put_contents($fileB, 'content-of-file-B-different');

        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory);

        $service->createDuplicateFilenames($collection);

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(2, $renames);

        // First file: basename-001.ext
        self::assertSame(
            $targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28-001.jpg',
            $renames[0]->getTarget()->getPathname(),
        );

        // Second file: basename-002.ext
        self::assertSame(
            $targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28-002.jpg',
            $renames[1]->getTarget()->getPathname(),
        );

        // Neither contains -duplicate-
        self::assertStringNotContainsString(
            FileSystemService::DUPLICATE_IDENTIFIER,
            $renames[0]->getTarget()->getFilename(),
        );
        self::assertStringNotContainsString(
            FileSystemService::DUPLICATE_IDENTIFIER,
            $renames[1]->getTarget()->getFilename(),
        );
    }

    #[Test]
    public function createDuplicateFilenamesCompanionInheritsSubGroupNumber(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        // Image A (hash X) + companion MOV A + image B (hash Y)
        $imageA = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.HEIC';
        $movA   = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.MOV';
        $imageB = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.HEIC';

        file_put_contents($imageA, 'image-content-A');
        file_put_contents($movA, 'video-content-A');
        file_put_contents($imageB, 'image-content-B-different');

        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.HEIC';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($imageA))
            ->addFile(new SplFileInfo($movA))
            ->addFile(new SplFileInfo($imageB))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('live-photo:content-id', $fileDuplicate);

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory)
            ->setUseFileExtensionFromSource(true);

        $service->createDuplicateFilenames($collection);

        $duplicate = $collection->get('live-photo:content-id');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames       = iterator_to_array($duplicate->getRenames());
        $renameTargets = [];

        foreach ($renames as $rename) {
            $renameTargets[$rename->getSource()->getPathname()] = $rename->getTarget()->getFilename();
        }

        self::assertCount(3, $renames);

        // Image A → basename-001.heic (sub-group 1)
        self::assertSame('2025-01-01_00-02-28-001.heic', $renameTargets[$imageA]);

        // MOV A → basename-001.mov (companion inherits sub-group 1)
        self::assertSame('2025-01-01_00-02-28-001.mov', $renameTargets[$movA]);

        // Image B → basename-002.heic (sub-group 2)
        self::assertSame('2025-01-01_00-02-28-002.heic', $renameTargets[$imageB]);
    }

    #[Test]
    public function createDuplicateFilenamesHandlesHashComputationFailure(): void
    {
        $hashCalculator = $this->createMock(SafeHashCalculator::class);

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $fileB = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';

        file_put_contents($fileA, 'content-A');
        file_put_contents($fileB, 'content-B');

        // File A hashes normally, file B throws an exception.
        $hashCalculator
            ->expects(self::exactly(2))
            ->method('hashFile')
            ->willReturnCallback(
                static function (SplFileInfo $file) use ($fileA): string {
                    if ($file->getPathname() === $fileA) {
                        return 'hash-a';
                    }

                    throw new HashComputationException('Cannot read file');
                },
            );

        $output = new BufferedOutput();
        $io     = new SymfonyStyle(new ArrayInput([]), $output);

        $fileSystemService = new FileSystemService($io);
        $service           = new DuplicateDetectionService($fileSystemService, $io, $hashCalculator);

        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory);

        $service->createDuplicateFilenames($collection);

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        // Both files get their own sub-group number (file B treated as unique).
        self::assertCount(2, $renames);
        self::assertSame('target-001.jpg', $renames[0]->getTarget()->getFilename());
        self::assertSame('target-002.jpg', $renames[1]->getTarget()->getFilename());

        // Error message was logged.
        $progressOutput = $output->fetch();
        self::assertStringContainsString('Cannot read file', $progressOutput);
    }

    #[Test]
    public function createDuplicateFilenamesSkipHashSubGroupingPreservesOldBehavior(): void
    {
        $hashCalculator = $this->createMock(SafeHashCalculator::class);
        $hashCalculator
            ->expects(self::never())
            ->method('hashFile');

        $output = new BufferedOutput();
        $io     = new SymfonyStyle(new ArrayInput([]), $output);

        $fileSystemService = new FileSystemService($io);
        $service           = new DuplicateDetectionService($fileSystemService, $io, $hashCalculator);

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $fileB = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';

        file_put_contents($fileA, 'content-A');
        file_put_contents($fileB, 'content-B-different');

        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory);

        $service->createDuplicateFilenames($collection, skipHashSubGrouping: true);

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(2, $renames);

        // Old behavior: first file = canonical, second = -duplicate-001
        self::assertSame(
            $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg',
            $renames[0]->getTarget()->getPathname(),
        );
        self::assertSame(
            $targetDirectory . DIRECTORY_SEPARATOR . 'target-duplicate-001.jpg',
            $renames[1]->getTarget()->getPathname(),
        );
    }

    #[Test]
    public function createDuplicateFilenamesSingleFileGroupSkipsHashing(): void
    {
        $hashCalculator = $this->createMock(SafeHashCalculator::class);
        $hashCalculator
            ->expects(self::never())
            ->method('hashFile');

        $output = new BufferedOutput();
        $io     = new SymfonyStyle(new ArrayInput([]), $output);

        $fileSystemService = new FileSystemService($io);
        $service           = new DuplicateDetectionService($fileSystemService, $io, $hashCalculator);

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'only.jpg';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        file_put_contents($sourceFile, 'single');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($sourceFile))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory);

        $service->createDuplicateFilenames($collection);

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(1, $renames);
        self::assertSame(
            $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg',
            $renames[0]->getTarget()->getPathname(),
        );
    }

    #[Test]
    public function createDuplicateFilenamesHandlesMixedDistinctAndDuplicateFiles(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        // 5 files: A (hash X), A' (hash X), B (hash Y), B' (hash Y), C (hash Z)
        $fileA  = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $fileA2 = $sourceDirectory . DIRECTORY_SEPARATOR . 'a_copy.jpg';
        $fileB  = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';
        $fileB2 = $sourceDirectory . DIRECTORY_SEPARATOR . 'b_copy.jpg';
        $fileC  = $sourceDirectory . DIRECTORY_SEPARATOR . 'c.jpg';

        file_put_contents($fileA, 'content-hash-X');
        file_put_contents($fileA2, 'content-hash-X');
        file_put_contents($fileB, 'content-hash-Y');
        file_put_contents($fileB2, 'content-hash-Y');
        file_put_contents($fileC, 'content-hash-Z');

        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'basename.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileA2))
            ->addFile(new SplFileInfo($fileB))
            ->addFile(new SplFileInfo($fileB2))
            ->addFile(new SplFileInfo($fileC))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory);

        $service->createDuplicateFilenames($collection);

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames       = iterator_to_array($duplicate->getRenames());
        $renameTargets = [];

        foreach ($renames as $rename) {
            $renameTargets[$rename->getSource()->getPathname()] = $rename->getTarget()->getFilename();
        }

        self::assertCount(5, $renames);

        // A → basename-001.jpg
        self::assertSame('basename-001.jpg', $renameTargets[$fileA]);
        // A' → basename-001-duplicate-001.jpg
        self::assertSame('basename-001-duplicate-001.jpg', $renameTargets[$fileA2]);
        // B → basename-002.jpg
        self::assertSame('basename-002.jpg', $renameTargets[$fileB]);
        // B' → basename-002-duplicate-001.jpg
        self::assertSame('basename-002-duplicate-001.jpg', $renameTargets[$fileB2]);
        // C → basename-003.jpg
        self::assertSame('basename-003.jpg', $renameTargets[$fileC]);
    }

    #[Test]
    public function createDuplicateFilenamesAssignsDuplicateSuffixForIdenticalContent(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA = $sourceDirectory . DIRECTORY_SEPARATOR . 'copy_a.jpg';
        $fileB = $sourceDirectory . DIRECTORY_SEPARATOR . 'copy_b.jpg';

        file_put_contents($fileA, 'identical-content');
        file_put_contents($fileB, 'identical-content');

        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        $service
            ->setSourceDirectory($sourceDirectory)
            ->setTargetDirectory($targetDirectory);

        $service->createDuplicateFilenames($collection);

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(2, $renames);

        // First: canonical — no duplicate suffix
        self::assertSame(
            $targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.jpg',
            $renames[0]->getTarget()->getPathname(),
        );

        // Second: genuine duplicate
        self::assertSame(
            $targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28-duplicate-001.jpg',
            $renames[1]->getTarget()->getPathname(),
        );
    }

    /**
     * @return array{DuplicateDetectionService, BufferedOutput, FileSystemService}
     */
    private function createService(): array
    {
        $output = new BufferedOutput();
        $io     = new SymfonyStyle(new ArrayInput([]), $output);

        $fileSystemService  = new FileSystemService($io);
        $hashCalculator     = new SafeHashCalculator();
        $service            = new DuplicateDetectionService($fileSystemService, $io, $hashCalculator);

        return [$service, $output, $fileSystemService];
    }

    private function createTempDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('photo-renamer-', true);

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            self::fail('Failed to create temporary directory: ' . $directory);
        }

        $this->tempDirectories[] = $directory;

        return $directory;
    }

    /**
     * @return RecursiveIteratorIterator<RecursiveDirectoryIterator>
     */
    private function createIterator(string $directory): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            assert($fileInfo instanceof SplFileInfo);

            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());

                continue;
            }

            unlink($fileInfo->getPathname());
        }

        rmdir($directory);
    }
}

final readonly class DummyLivePhotoRenameStrategy implements RenameStrategyInterface
{
    /**
     * @param array<string, string|null> $filenameMap
     * @param array<string, string|null> $identifierMap
     */
    public function __construct(
        private array $filenameMap,
        private array $identifierMap,
    ) {
    }

    public function generateFilename(SplFileInfo $splFileInfo): ?string
    {
        $pathname = $splFileInfo->getPathname();

        return $this->filenameMap[$pathname] ?? null;
    }

    public function getLivePhotoContentIdentifier(SplFileInfo $splFileInfo): ?string
    {
        $pathname = $splFileInfo->getPathname();

        return $this->identifierMap[$pathname] ?? null;
    }
}

final readonly class DummyLivePhotoDuplicateIdentifierStrategy implements DuplicateIdentifierStrategyInterface
{
    /**
     * @param array<string, string|null> $identifierMap
     */
    public function __construct(private array $identifierMap)
    {
    }

    public function generateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string
    {
        $pathname = $sourceFileInfo->getPathname();

        $identifier = $this->identifierMap[$pathname] ?? null;

        if ($identifier === null) {
            return $targetFileInfo->getFilename();
        }

        return 'live-photo:' . strtolower($identifier);
    }
}
