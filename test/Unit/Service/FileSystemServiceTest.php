<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Service\FileSystemService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

use function chmod;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(FileSystemService::class)]
#[CoversClass(FileDuplicateCollection::class)]
#[CoversClass(FileDuplicate::class)]
#[CoversClass(Rename::class)]
final class FileSystemServiceTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('photo-renamer-fs-', true);
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);

        parent::tearDown();
    }

    #[Test]
    public function renameFilesMovesFilesByDefault(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'source';
        $targetDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'target';
        mkdir($sourceDirectory);

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'image.jpg';

        file_put_contents($sourceFile, 'original');

        $fileDuplicateCollection = $this->createFileDuplicateCollection(
            $sourceFile,
            $targetFile,
        );

        $service->renameFiles($fileDuplicateCollection);

        self::assertFileDoesNotExist($sourceFile);
        self::assertFileExists($targetFile);
        self::assertSame('original', file_get_contents($targetFile));

        $buffer = $output->fetch();
        self::assertStringContainsString('1 files renamed', $buffer);
        self::assertStringContainsString('0 possible duplicates found', $buffer);
    }

    #[Test]
    public function renameFilesRespectsDryRunOption(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'source-dry-run';
        $targetDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'target-dry-run';
        mkdir($sourceDirectory);

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'image.jpg';

        file_put_contents($sourceFile, 'original');

        $fileDuplicateCollection = $this->createFileDuplicateCollection(
            $sourceFile,
            $targetFile,
        );

        $service->renameFiles($fileDuplicateCollection, dryRun: true);

        self::assertFileExists($sourceFile);
        self::assertFileDoesNotExist($targetFile);

        $buffer = $output->fetch();
        self::assertStringContainsString('1 files renamed', $buffer);
    }

    #[Test]
    public function renameFilesSkipsDuplicateTargetsWhenRequested(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'source-duplicate';
        $targetDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'target-duplicate';
        mkdir($sourceDirectory);

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR
            . sprintf('image%s001.jpg', FileSystemService::DUPLICATE_IDENTIFIER);

        file_put_contents($sourceFile, 'duplicate');

        $fileDuplicateCollection = $this->createFileDuplicateCollection(
            $sourceFile,
            $targetFile,
        );

        $service->renameFiles($fileDuplicateCollection, skipDuplicates: true);

        self::assertFileExists($sourceFile);
        self::assertFileDoesNotExist($targetFile);

        $buffer = $output->fetch();
        self::assertStringContainsString('Duplicate! Skip', $buffer);
        self::assertStringContainsString('0 files renamed', $buffer);
        self::assertStringContainsString('1 possible duplicates found', $buffer);
    }

    #[Test]
    public function renameFilesCopiesFilesWhenRequested(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'source-copy';
        $targetDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'target-copy';
        mkdir($sourceDirectory);

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'image.jpg';

        file_put_contents($sourceFile, 'copy');

        $fileDuplicateCollection = $this->createFileDuplicateCollection(
            $sourceFile,
            $targetFile,
        );

        $service->renameFiles($fileDuplicateCollection, copyFiles: true);

        self::assertFileExists($sourceFile);
        self::assertFileExists($targetFile);
        self::assertSame('copy', file_get_contents($sourceFile));
        self::assertSame('copy', file_get_contents($targetFile));

        $buffer = $output->fetch();
        self::assertStringContainsString('1 files renamed', $buffer);
        self::assertStringContainsString('0 possible duplicates found', $buffer);
    }

    #[Test]
    public function renameFilesThrowsWhenTargetDirectoryCannotBeCreated(): void
    {
        [$service] = $this->createService();

        $blockingFile = $this->workspace . DIRECTORY_SEPARATOR . 'locked';
        file_put_contents($blockingFile, 'placeholder');

        $sourceDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'source-error';
        mkdir($sourceDirectory);

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        $targetFile = $blockingFile . DIRECTORY_SEPARATOR . 'subdir' . DIRECTORY_SEPARATOR . 'image.jpg';

        file_put_contents($sourceFile, 'content');

        $fileDuplicateCollection = $this->createFileDuplicateCollection(
            $sourceFile,
            $targetFile,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Directory');

        set_error_handler(static fn (): bool => true);

        try {
            $service->renameFiles($fileDuplicateCollection);
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function renameFilesThrowsWhenMoveFails(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'source-move-fails';
        $targetDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'target-move-fails';

        mkdir($sourceDirectory);
        mkdir($targetDirectory);

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'image.jpg';

        file_put_contents($sourceFile, 'content');

        $fileDuplicateCollection = $this->createFileDuplicateCollection(
            $sourceFile,
            $targetFile,
        );

        unlink($sourceFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Target file');

        $service->renameFiles($fileDuplicateCollection);
    }

    #[Test]
    public function renameFilesThrowsWhenCopyFails(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'source-copy-fails';
        $targetDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'target-copy-fails';

        mkdir($sourceDirectory);
        mkdir($targetDirectory);

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'image.jpg';

        file_put_contents($sourceFile, 'content');

        $fileDuplicateCollection = $this->createFileDuplicateCollection(
            $sourceFile,
            $targetFile,
        );

        unlink($sourceFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Target file');

        $service->renameFiles($fileDuplicateCollection, copyFiles: true);
    }

    /**
     * @return array{FileSystemService, BufferedOutput}
     */
    private function createService(): array
    {
        $output = new BufferedOutput();
        $io     = new SymfonyStyle(new ArrayInput([]), $output);

        return [new FileSystemService($io), $output];
    }

    private function createFileDuplicateCollection(string $sourceFile, string $targetFile): FileDuplicateCollection
    {
        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->addFile(new SplFileInfo($sourceFile));
        $fileDuplicate->setTarget(new SplFileInfo($targetFile));
        $fileDuplicate->addRename(
            new Rename(
                new SplFileInfo($sourceFile),
                new SplFileInfo($targetFile),
            ),
        );

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        return $collection;
    }

    private function removeDirectory(string $directory): void
    {
        if ($directory === '' || !file_exists($directory)) {
            return;
        }

        $files = scandir($directory);

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
