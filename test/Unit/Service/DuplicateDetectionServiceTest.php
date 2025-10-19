<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use FilesystemIterator;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Service\DuplicateDetectionService;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class DuplicateDetectionServiceTest extends TestCase
{
    public function testGroupFilesByDuplicateIdentifierDisplaysEtaInformation(): void
    {
        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        try {
            $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'source.jpg';
            touch($sourceFile);

            $input  = new ArrayInput([]);
            $output = new BufferedOutput();
            $io     = new SymfonyStyle($input, $output);

            $fileSystemService = $this->createMock(FileSystemService::class);
            $fileSystemService
                ->expects(self::once())
                ->method('countFiles')
                ->with(self::isInstanceOf(RecursiveIteratorIterator::class))
                ->willReturn(1);

            $service = new DuplicateDetectionService($fileSystemService, $io);
            $service
                ->setSourceDirectory($sourceDirectory)
                ->setTargetDirectory($targetDirectory);

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

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
        } finally {
            $this->removeDirectory($sourceDirectory);
            $this->removeDirectory($targetDirectory);
        }
    }

    public function testCreateDuplicateFilenamesDisplaysEtaInformation(): void
    {
        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        try {
            $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'source.jpg';
            $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

            touch($sourceFile);
            touch($targetFile);

            $input  = new ArrayInput([]);
            $output = new BufferedOutput();
            $io     = new SymfonyStyle($input, $output);

            $fileSystemService = $this->createMock(FileSystemService::class);
            $fileSystemService
                ->expects(self::never())
                ->method('countFiles');

            $service = new DuplicateDetectionService($fileSystemService, $io);
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
        } finally {
            $this->removeDirectory($sourceDirectory);
            $this->removeDirectory($targetDirectory);
        }
    }

    private function createTempDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('photo-renamer-', true);

        if (!mkdir($directory) && !is_dir($directory)) {
            self::fail('Failed to create temporary directory: ' . $directory);
        }

        return $directory;
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
