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
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\ProgressBar;

use function basename;
use function chmod;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function preg_quote;
use function rmdir;
use function rtrim;
use function scandir;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function sys_get_temp_dir;
use function substr;
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

        $service->renameFiles(
            $fileDuplicateCollection,
            dryRun: true,
            sourceBaseDirectory: $sourceDirectory,
            targetBaseDirectory: $targetDirectory,
        );

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
        mkdir($targetDirectory);

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        $canonicalTarget = $targetDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR
            . sprintf('image%s001.jpg', FileSystemService::DUPLICATE_IDENTIFIER);

        file_put_contents($sourceFile, 'duplicate');

        $fileDuplicateCollection = $this->createFileDuplicateCollection(
            $sourceFile,
            $targetFile,
            $canonicalTarget,
        );

        $service->renameFiles(
            $fileDuplicateCollection,
            skipDuplicates: true,
            sourceBaseDirectory: $sourceDirectory,
            targetBaseDirectory: $targetDirectory,
        );

        self::assertFileExists($sourceFile);
        self::assertFileDoesNotExist($targetFile);

        $buffer = $output->fetch();
        $relativeSource = $this->relativizePath($sourceFile, $sourceDirectory);

        self::assertStringContainsString('Duplicate! Skip "' . $relativeSource . '"', $buffer);
        self::assertStringContainsString('0 files renamed', $buffer);
        self::assertStringContainsString('1 possible duplicates found', $buffer);
    }

    #[Test]
    public function renameFilesProcessesCanonicalTargetsWithDuplicateSuffixesWhenSkippingDuplicates(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'source-canonical-duplicate';
        $targetDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'target-canonical-duplicate';

        mkdir($sourceDirectory);
        mkdir($targetDirectory);

        $existingCanonical = $targetDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        file_put_contents($existingCanonical, 'existing');

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'incoming.jpg';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR
            . 'image' . FileSystemService::DUPLICATE_IDENTIFIER . '001.jpg';

        file_put_contents($sourceFile, 'incoming');

        $fileDuplicateCollection = $this->createFileDuplicateCollection(
            $sourceFile,
            $targetFile,
        );

        $service->renameFiles(
            $fileDuplicateCollection,
            skipDuplicates: true,
            sourceBaseDirectory: $sourceDirectory,
            targetBaseDirectory: $targetDirectory,
        );

        self::assertFileDoesNotExist($sourceFile);
        self::assertFileExists($targetFile);
        self::assertSame('incoming', file_get_contents($targetFile));
        self::assertFileExists($existingCanonical);
        self::assertSame('existing', file_get_contents($existingCanonical));

        $buffer = $output->fetch();

        self::assertStringNotContainsString('Duplicate! Skip', $buffer);
        self::assertStringContainsString('[R]', $buffer);

        $relativeSource = $this->relativizePath($sourceFile, $sourceDirectory);
        $relativeTarget = $this->relativizePath($targetFile, $targetDirectory);

        self::assertStringContainsString($relativeSource, $buffer);
        self::assertStringContainsString($relativeTarget, $buffer);
        self::assertStringContainsString('1 files renamed', $buffer);
        self::assertStringContainsString('0 possible duplicates found', $buffer);
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
    public function renameFilesSeparatesProgressBarFromPerFileLogs(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'source-progress-format';
        $targetDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'target-progress-format';

        mkdir($sourceDirectory);

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'image.jpg';

        file_put_contents($sourceFile, 'format');

        $fileDuplicateCollection = $this->createFileDuplicateCollection(
            $sourceFile,
            $targetFile,
        );

        $service->renameFiles(
            $fileDuplicateCollection,
            dryRun: true,
            sourceBaseDirectory: $sourceDirectory,
            targetBaseDirectory: $targetDirectory,
        );

        $buffer     = $output->fetch();
        $normalized = str_replace("\r", "\n", $buffer);

        $progressPosition = strpos($normalized, '0/1');
        $logPosition      = strpos($normalized, '[R]');

        self::assertNotFalse($progressPosition);
        self::assertNotFalse($logPosition);
        self::assertLessThan($logPosition, $progressPosition);
        $relativeSource = $this->relativizePath($sourceFile, $sourceDirectory);
        $relativeTarget = $this->relativizePath($targetFile, $targetDirectory);

        self::assertStringContainsString($relativeSource, $normalized);
        self::assertStringContainsString($relativeTarget, $normalized);
    }

    #[Test]
    public function renameFilesDisplaysRelativePathsWhenUsingSameBaseDirectory(): void
    {
        [$service, $output] = $this->createService();

        $directory = $this->workspace . DIRECTORY_SEPARATOR . 'in-place';
        mkdir($directory);

        $sourceFile = $directory . DIRECTORY_SEPARATOR . 'original.jpg';
        $targetFile = $directory . DIRECTORY_SEPARATOR . 'renamed.jpg';

        file_put_contents($sourceFile, 'content');

        $fileDuplicateCollection = $this->createFileDuplicateCollection(
            $sourceFile,
            $targetFile,
        );

        $service->renameFiles(
            $fileDuplicateCollection,
            dryRun: true,
            sourceBaseDirectory: $directory,
            targetBaseDirectory: $directory,
        );

        $buffer = $output->fetch();

        $relativeSource = $this->relativizePath($sourceFile, $directory);
        $relativeTarget = $this->relativizePath($targetFile, $directory);

        self::assertStringContainsString($relativeSource, $buffer);
        self::assertStringContainsString($relativeTarget, $buffer);
    }

    #[Test]
    public function renameFilesDisplaysStatusPrefixesWhenListingAll(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'source-status';
        $targetDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'target-status';

        mkdir($sourceDirectory);
        mkdir($targetDirectory);

        $canonicalPath = $targetDirectory . DIRECTORY_SEPARATOR . 'photo.jpg';
        $renameSource  = $sourceDirectory . DIRECTORY_SEPARATOR . 'rename.jpg';
        $renameTarget  = $canonicalPath;
        $duplicateSource = $sourceDirectory . DIRECTORY_SEPARATOR . 'duplicate.jpg';
        $duplicateTarget = $targetDirectory . DIRECTORY_SEPARATOR . 'photo'
            . FileSystemService::DUPLICATE_IDENTIFIER . '001.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($canonicalPath));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($canonicalPath), new SplFileInfo($canonicalPath)));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($renameSource), new SplFileInfo($renameTarget)));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($duplicateSource), new SplFileInfo($duplicateTarget)));

        $collection = new FileDuplicateCollection();
        $collection->set('status', $fileDuplicate);

        $service->renameFiles(
            $collection,
            dryRun: true,
            listAll: true,
            sourceBaseDirectory: $sourceDirectory,
            targetBaseDirectory: $targetDirectory,
        );

        $buffer = $output->fetch();

        $relativeCanonicalSource = $this->relativizePath($canonicalPath, $sourceDirectory);
        $relativeCanonicalTarget = $this->relativizePath($canonicalPath, $targetDirectory);
        $relativeRenameSource = $this->relativizePath($renameSource, $sourceDirectory);
        $relativeDuplicateSource = $this->relativizePath($duplicateSource, $sourceDirectory);
        $relativeRenameTarget = $this->relativizePath($renameTarget, $targetDirectory);
        $relativeDuplicateTarget = $this->relativizePath($duplicateTarget, $targetDirectory);

        self::assertStringContainsString('[O] ' . $relativeCanonicalSource, $buffer);
        self::assertStringContainsString('[R] ' . $relativeRenameSource, $buffer);
        self::assertStringContainsString('[D] ' . $relativeDuplicateSource, $buffer);
        self::assertStringContainsString('→ ' . $relativeCanonicalTarget, $buffer);
        self::assertStringContainsString('→ ' . $relativeRenameTarget, $buffer);
        self::assertStringContainsString('→ ' . $relativeDuplicateTarget, $buffer);
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

    #[Test]
    public function renameFilesInitializesProgressBarWithPlannedOperations(): void
    {
        [$service,, $style] = $this->createService();

        $sourceDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'source-progress';
        $targetDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'target-progress';

        mkdir($sourceDirectory);
        mkdir($targetDirectory);

        $firstSource = $sourceDirectory . DIRECTORY_SEPARATOR . 'first.jpg';
        $firstTarget = $targetDirectory . DIRECTORY_SEPARATOR . 'first.jpg';
        $secondSource = $sourceDirectory . DIRECTORY_SEPARATOR . 'second.jpg';
        $secondTarget = $targetDirectory . DIRECTORY_SEPARATOR . 'second.jpg';

        file_put_contents($firstSource, 'first');
        file_put_contents($secondSource, 'second');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($firstTarget));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($firstSource), new SplFileInfo($firstTarget)));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($secondSource), new SplFileInfo($secondTarget)));

        $collection = new FileDuplicateCollection();
        $collection->set('progress', $fileDuplicate);

        $service->renameFiles($collection, dryRun: true);

        self::assertNotNull($style->capturedProgressBar);

        $progressBar = $style->capturedProgressBar;
        \assert($progressBar instanceof ProgressBar);

        self::assertSame(2, $progressBar->getMaxSteps());
        self::assertSame(2, $progressBar->getProgress());

        $formatProperty = new ReflectionProperty($progressBar, 'format');
        $formatProperty->setAccessible(true);

        self::assertSame(
            ' %current%/%max% [%bar%] %percent:3s%% | ETA: %estimated:-6s% | Remaining: %remaining:-6s%',
            $formatProperty->getValue($progressBar),
        );
    }

    private function relativizePath(string $path, ?string $baseDirectory): string
    {
        if ($baseDirectory === null || $baseDirectory === '') {
            return $path;
        }

        $normalizedBase = rtrim($baseDirectory, DIRECTORY_SEPARATOR);

        if ($normalizedBase === '') {
            return $path;
        }

        $prefix = $normalizedBase . DIRECTORY_SEPARATOR;

        if (str_starts_with($path, $prefix)) {
            $relativePath = substr($path, strlen($prefix));
            $baseName     = basename($normalizedBase);

            if ($baseName === '' || $baseName === DIRECTORY_SEPARATOR) {
                return $relativePath;
            }

            return $baseName . DIRECTORY_SEPARATOR . $relativePath;
        }

        return $path;
    }

    /**
     * @return array{FileSystemService, BufferedOutput, SymfonyStyle&object{capturedProgressBar: ?ProgressBar}}
     */
    private function createService(): array
    {
        $output = new BufferedOutput();
        $io     = new class(new ArrayInput([]), $output) extends SymfonyStyle {
            public ?ProgressBar $capturedProgressBar = null;

            public function createProgressBar(int $max = 0): ProgressBar
            {
                $progressBar = parent::createProgressBar($max);
                $this->capturedProgressBar = $progressBar;

                return $progressBar;
            }
        };

        return [new FileSystemService($io), $output, $io];
    }

    private function createFileDuplicateCollection(
        string $sourceFile,
        string $targetFile,
        ?string $canonicalTargetFile = null,
    ): FileDuplicateCollection {
        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->addFile(new SplFileInfo($sourceFile));
        $fileDuplicate->setTarget(new SplFileInfo($canonicalTargetFile ?? $targetFile));
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
