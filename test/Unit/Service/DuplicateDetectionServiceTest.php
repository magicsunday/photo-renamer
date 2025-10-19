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
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

use function file_put_contents;
use function is_dir;
use function iterator_to_array;
use function mkdir;
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

        self::assertStringContainsString('ETA', $progressOutput);
        self::assertStringContainsString('Remaining', $progressOutput);
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

        self::assertStringContainsString('ETA', $progressOutput);
        self::assertStringContainsString('Remaining', $progressOutput);
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
