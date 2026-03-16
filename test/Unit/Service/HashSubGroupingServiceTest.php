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
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Service\HashSubGroupingService;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

use function assert;
use function file_put_contents;
use function is_dir;
use function iterator_to_array;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(HashSubGroupingService::class)]
#[CoversClass(FileDuplicate::class)]
#[CoversClass(RenameList::class)]
#[CoversClass(Rename::class)]
final class HashSubGroupingServiceTest extends TestCase
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
    public function applyReturnsFalseForSingleFile(): void
    {
        $service = $this->createHashSubGroupingService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA  = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $target = $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        file_put_contents($fileA, 'content');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->setTarget(new SplFileInfo($target));

        $renameA = new Rename(new SplFileInfo($fileA), new SplFileInfo($target));
        $fileDuplicate->addRename($renameA);

        $result = $service->apply($fileDuplicate, $renameA, null, [], $sourceDirectory, $targetDirectory);

        self::assertFalse($result);
    }

    #[Test]
    public function applyReturnsFalseForSingleHashGroup(): void
    {
        $service = $this->createHashSubGroupingService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA  = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $fileB  = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';
        $target = $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        file_put_contents($fileA, 'same-content');
        file_put_contents($fileB, 'same-content');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($target));

        $renameA = new Rename(new SplFileInfo($fileA), new SplFileInfo($target));
        $renameB = new Rename(new SplFileInfo($fileB), new SplFileInfo($target));
        $fileDuplicate->addRename($renameA);
        $fileDuplicate->addRename($renameB);

        $result = $service->apply($fileDuplicate, $renameA, null, [], $sourceDirectory, $targetDirectory);

        self::assertFalse($result);
    }

    #[Test]
    public function applyReturnsTrueAndAssignsSubGroupsForDistinctHashes(): void
    {
        $service = $this->createHashSubGroupingService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA  = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $fileB  = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';
        $target = $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        file_put_contents($fileA, 'content-A');
        file_put_contents($fileB, 'content-B-different');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($target));

        $renameA = new Rename(new SplFileInfo($fileA), new SplFileInfo($target));
        $renameB = new Rename(new SplFileInfo($fileB), new SplFileInfo($target));
        $fileDuplicate->addRename($renameA);
        $fileDuplicate->addRename($renameB);

        $result = $service->apply($fileDuplicate, $renameA, null, [], $sourceDirectory, $targetDirectory);

        self::assertTrue($result);

        $renames = iterator_to_array($fileDuplicate->getRenames());

        self::assertCount(2, $renames);

        // Canonical sub-group: unsuffixed base name
        self::assertSame('target.jpg', $renames[0]->getTarget()->getFilename());
        // Second sub-group: -002
        self::assertSame('target-002.jpg', $renames[1]->getTarget()->getFilename());
    }

    #[Test]
    public function applyHandlesMixedDistinctAndDuplicateFiles(): void
    {
        $service = $this->createHashSubGroupingService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA  = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $fileA2 = $sourceDirectory . DIRECTORY_SEPARATOR . 'a_copy.jpg';
        $fileB  = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';
        $fileB2 = $sourceDirectory . DIRECTORY_SEPARATOR . 'b_copy.jpg';
        $fileC  = $sourceDirectory . DIRECTORY_SEPARATOR . 'c.jpg';
        $target = $targetDirectory . DIRECTORY_SEPARATOR . 'basename.jpg';

        file_put_contents($fileA, 'content-hash-X');
        file_put_contents($fileA2, 'content-hash-X');
        file_put_contents($fileB, 'content-hash-Y');
        file_put_contents($fileB2, 'content-hash-Y');
        file_put_contents($fileC, 'content-hash-Z');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileA2))
            ->addFile(new SplFileInfo($fileB))
            ->addFile(new SplFileInfo($fileB2))
            ->addFile(new SplFileInfo($fileC))
            ->setTarget(new SplFileInfo($target));

        $renameA  = new Rename(new SplFileInfo($fileA), new SplFileInfo($target));
        $renameA2 = new Rename(new SplFileInfo($fileA2), new SplFileInfo($target));
        $renameB  = new Rename(new SplFileInfo($fileB), new SplFileInfo($target));
        $renameB2 = new Rename(new SplFileInfo($fileB2), new SplFileInfo($target));
        $renameC  = new Rename(new SplFileInfo($fileC), new SplFileInfo($target));
        $fileDuplicate->addRename($renameA);
        $fileDuplicate->addRename($renameA2);
        $fileDuplicate->addRename($renameB);
        $fileDuplicate->addRename($renameB2);
        $fileDuplicate->addRename($renameC);

        $result = $service->apply($fileDuplicate, $renameA, null, [], $sourceDirectory, $targetDirectory);

        self::assertTrue($result);

        $renames       = iterator_to_array($fileDuplicate->getRenames());
        $renameTargets = [];

        foreach ($renames as $rename) {
            $renameTargets[$rename->getSource()->getPathname()] = $rename->getTarget()->getFilename();
        }

        self::assertCount(5, $renames);
        self::assertSame('basename.jpg', $renameTargets[$fileA]);
        self::assertSame('basename-duplicate-001.jpg', $renameTargets[$fileA2]);
        self::assertSame('basename-002.jpg', $renameTargets[$fileB]);
        self::assertSame('basename-002-duplicate-001.jpg', $renameTargets[$fileB2]);
        self::assertSame('basename-003.jpg', $renameTargets[$fileC]);
    }

    #[Test]
    public function applyExcludesCompanionMediaTypeFromHashGroups(): void
    {
        $service = $this->createHashSubGroupingService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        // Image A (hash X) + companion MOV A + image B (hash Y)
        $imageA = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.HEIC';
        $movA   = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.MOV';
        $imageB = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.HEIC';

        file_put_contents($imageA, 'image-content-A');
        file_put_contents($movA, 'video-content-A');
        file_put_contents($imageB, 'image-content-B-different');

        $target = $targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.HEIC';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($imageA))
            ->addFile(new SplFileInfo($movA))
            ->addFile(new SplFileInfo($imageB))
            ->setTarget(new SplFileInfo($target));

        $renameImageA = new Rename(
            new SplFileInfo($imageA),
            new SplFileInfo($targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.heic'),
        );
        $renameMovA = new Rename(
            new SplFileInfo($movA),
            new SplFileInfo($targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.mov'),
        );
        $renameImageB = new Rename(
            new SplFileInfo($imageB),
            new SplFileInfo($targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.heic'),
        );

        $fileDuplicate->addRename($renameImageA);
        $fileDuplicate->addRename($renameMovA);
        $fileDuplicate->addRename($renameImageB);

        // No content-ID map, so companion detection won't pair the video.
        // All three files have distinct hashes -> sub-grouping applies.
        $result = $service->apply(
            $fileDuplicate,
            $renameImageA,
            null,
            [],
            $sourceDirectory,
            $targetDirectory,
        );

        self::assertTrue($result);

        $renames       = iterator_to_array($fileDuplicate->getRenames());
        $renameTargets = [];

        foreach ($renames as $rename) {
            $renameTargets[$rename->getSource()->getPathname()] = $rename->getTarget()->getFilename();
        }

        self::assertCount(3, $renames);
        self::assertSame('2025-01-01_00-02-28.heic', $renameTargets[$imageA]);
        self::assertSame('2025-01-01_00-02-28-002.mov', $renameTargets[$movA]);
        self::assertSame('2025-01-01_00-02-28-003.heic', $renameTargets[$imageB]);
    }

    private function createHashSubGroupingService(): HashSubGroupingService
    {
        $output = new BufferedOutput();
        $io     = new SymfonyStyle(new ArrayInput([]), $output);

        return new HashSubGroupingService(
            new SafeHashCalculator(),
            $io,
        );
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
