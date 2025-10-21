<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use FilesystemIterator;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Service\DuplicateDetectionService;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use RuntimeException;

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

        self::assertSame(0, $collection->count());
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
            ->willReturnCallback(static function (SplFileInfo $file) use ($videoPath, $photoPath): string {
                return match ($file->getPathname()) {
                    $videoPath => 'video-target.mov',
                    $photoPath => 'photo-target.heic',
                    default => throw new RuntimeException('Unexpected file: ' . $file->getPathname()),
                };
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
    public function createDuplicateFilenamesUsesSourceExtensionWhenConfigured(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $jpgFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg';
        $pngFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'photo.png';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'renamed.jpg';

        file_put_contents($jpgFile, 'jpg');
        file_put_contents($pngFile, 'png');

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
        self::assertSame(
            $targetDirectory . DIRECTORY_SEPARATOR . 'renamed-duplicate-001.png',
            $renames[1]->getTarget()->getPathname(),
        );
    }

    #[Test]
    public function createDuplicateFilenamesGeneratesIncrementalDuplicateTargets(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $sourceOne = $sourceDirectory . DIRECTORY_SEPARATOR . 'one.jpg';
        $sourceTwo = $sourceDirectory . DIRECTORY_SEPARATOR . 'two.jpg';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'renamed.jpg';

        file_put_contents($sourceOne, 'one');
        file_put_contents($sourceTwo, 'two');
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

        $rootFile             = $sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg';
        $duplicateFile        = $nestedDirectory . DIRECTORY_SEPARATOR . 'photo.jpg';
        $preRenamedDuplicate  = $nestedDirectory . DIRECTORY_SEPARATOR . 'photo'
            . FileSystemService::DUPLICATE_IDENTIFIER . '001.jpg';

        file_put_contents($rootFile, 'root');
        file_put_contents($duplicateFile, 'duplicate');
        file_put_contents($preRenamedDuplicate, 'duplicate-001');

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

        self::assertCount(2, $renames);
        self::assertSame($duplicateFile, $renames[0]->getSource()->getPathname());
        self::assertStringStartsWith(
            $nestedDirectory . DIRECTORY_SEPARATOR,
            $renames[0]->getTarget()->getPathname(),
        );
        self::assertSame($preRenamedDuplicate, $renames[1]->getSource()->getPathname());
        self::assertNotSame($preRenamedDuplicate, $renames[1]->getTarget()->getPathname());

        $pattern = '/' . preg_quote(FileSystemService::DUPLICATE_IDENTIFIER, '/') . '(\d{3})\.jpg$/';

        self::assertSame(
            1,
            preg_match($pattern, $renames[0]->getTarget()->getFilename(), $firstMatch),
            'First duplicate rename should include a numeric duplicate suffix.',
        );
        self::assertSame(
            1,
            preg_match($pattern, $renames[1]->getTarget()->getFilename(), $secondMatch),
            'Pre-renamed file should be reassigned a numeric duplicate suffix.',
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

        file_put_contents($canonicalSource, 'original');
        file_put_contents($duplicateSource, 'duplicate');

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

        $sourceRoot       = $this->createTempDirectory();
        $sourceDirectory  = $sourceRoot . DIRECTORY_SEPARATOR . 'Photos';
        $nestedDirectory  = $sourceDirectory . DIRECTORY_SEPARATOR . 'Photos';
        $targetDirectory  = $this->createTempDirectory();

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

    /**
     * @return array{DuplicateDetectionService, BufferedOutput}
     */
    private function createService(): array
    {
        $output = new BufferedOutput();
        $io     = new SymfonyStyle(new ArrayInput([]), $output);

        $fileSystemService = new FileSystemService($io);
        $service            = new DuplicateDetectionService($fileSystemService, $io);

        return [$service, $output];
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

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());
                continue;
            }

            unlink($fileInfo->getPathname());
        }

        rmdir($directory);
    }
}
