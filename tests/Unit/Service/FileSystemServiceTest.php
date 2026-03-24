<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Collection\FileList;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\LinkConfig;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;

use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function sprintf;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(FileSystemService::class)]
#[CoversClass(RenameOutputRenderer::class)]
#[CoversClass(FileDuplicateCollection::class)]
#[CoversClass(FileDuplicate::class)]
#[CoversClass(Rename::class)]
#[CoversClass(RenameOptions::class)]
#[CoversClass(RenameResult::class)]
/**
 * Verifies the FileSystemService, which executes the final stage of the rename
 * pipeline: creating target directories, moving or copying files, logging
 * operations with status prefixes, displaying summary statistics, and respecting
 * dry-run / skip-duplicates / copy-files options.
 *
 * All tests use real temp directories on disk to exercise actual filesystem I/O,
 * ensuring that move, copy, and directory creation work end-to-end.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[UsesClass(FileHelper::class)]
#[UsesClass(FileList::class)]
#[UsesClass(RenameList::class)]
#[UsesClass(LinkConfig::class)]
#[UsesClass(OutputEntryTag::class)]
final class FileSystemServiceTest extends TestCase
{
    use WorkspaceTrait;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('photo-renamer-fs-', true);
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeWorkspace($this->workspace);

        parent::tearDown();
    }

    /**
     * Verifies the default move behaviour: the source file is removed and the target
     * file is created with the original content. The summary shows "Planned moves"
     * and "Files processed" without copy/skip/Live Photo metrics.
     */
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
        self::assertStringContainsString('Scanned files', $buffer);
        self::assertStringContainsString('Planned moves', $buffer);
        self::assertStringContainsString('Files processed', $buffer);
        self::assertStringNotContainsString('Planned copies', $buffer);
        self::assertStringNotContainsString('Planned skips', $buffer);
        self::assertStringNotContainsString('Live Photo groups', $buffer);
        self::assertStringNotContainsString('Duplicates found', $buffer);
    }

    /**
     * Verifies that dry-run mode prevents any actual file operations: the source
     * remains, the target is not created, and the output shows "Files to process"
     * and "Planned moves" as a preview.
     */
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
            new RenameOptions(
                dryRun: true,
                sourceBaseDirectory: $sourceDirectory,
            ),
        );

        self::assertFileExists($sourceFile);
        self::assertFileDoesNotExist($targetFile);

        $buffer = $output->fetch();
        self::assertStringContainsString('Files to process', $buffer);
        self::assertStringContainsString('Planned moves', $buffer);
    }

    /**
     * Verifies that --skip-duplicates leaves source files with -duplicate-NNN
     * targets untouched, marks them as "Duplicate (--skip-duplicates)" in the output,
     * and shows "Duplicates found" and "Planned skips" in the summary.
     */
    #[Test]
    public function renameFilesSkipsDuplicateTargetsWhenRequested(): void
    {
        [$service, $output] = $this->createService();

        $directory = $this->workspace . DIRECTORY_SEPARATOR . 'source-duplicate';
        mkdir($directory);

        $sourceFile      = $directory . DIRECTORY_SEPARATOR . 'image.jpg';
        $canonicalTarget = $directory . DIRECTORY_SEPARATOR . 'image.jpg';
        $targetFile      = $directory . DIRECTORY_SEPARATOR
            . sprintf('image%s001.jpg', Constants::DUPLICATE_IDENTIFIER);

        file_put_contents($sourceFile, 'duplicate');

        $fileDuplicateCollection = $this->createFileDuplicateCollection(
            $sourceFile,
            $targetFile,
            $canonicalTarget,
        );

        $service->renameFiles(
            $fileDuplicateCollection,
            new RenameOptions(
                skipDuplicates: true,
                sourceBaseDirectory: $directory,
            ),
        );

        self::assertFileExists($sourceFile);
        self::assertFileDoesNotExist($targetFile);

        $buffer = $output->fetch();

        self::assertStringContainsString('Duplicate (--skip-duplicates)', $buffer);
        self::assertStringContainsString('Duplicates found', $buffer);
        self::assertStringContainsString('Planned skips', $buffer);
    }

    /**
     * Verifies that --skip-duplicates still processes files whose target has a
     * -duplicate-NNN suffix but whose canonical target is already occupied on disk,
     * because the file itself is not a true duplicate but a naming collision.
     *
     * This prevents false skips: a file that simply needs a suffixed name to
     * avoid overwriting an existing file must be moved, not skipped.
     */
    #[Test]
    public function renameFilesProcessesCanonicalTargetsWithDuplicateSuffixesWhenSkippingDuplicates(): void
    {
        [$service, $output] = $this->createService();

        $directory = $this->workspace . DIRECTORY_SEPARATOR . 'source-canonical-duplicate';
        mkdir($directory);

        $existingCanonical = $directory . DIRECTORY_SEPARATOR . 'image.jpg';
        file_put_contents($existingCanonical, 'existing');

        $sourceFile = $directory . DIRECTORY_SEPARATOR . 'incoming.jpg';
        $targetFile = $directory . DIRECTORY_SEPARATOR
            . 'image' . Constants::DUPLICATE_IDENTIFIER . '001.jpg';

        file_put_contents($sourceFile, 'incoming');

        $fileDuplicateCollection = $this->createFileDuplicateCollection(
            $sourceFile,
            $targetFile,
        );

        $service->renameFiles(
            $fileDuplicateCollection,
            new RenameOptions(
                skipDuplicates: true,
                sourceBaseDirectory: $directory,
            ),
        );

        self::assertFileDoesNotExist($sourceFile);
        self::assertFileExists($targetFile);
        self::assertSame('incoming', file_get_contents($targetFile));
        self::assertFileExists($existingCanonical);
        self::assertSame('existing', file_get_contents($existingCanonical));

        $buffer = $output->fetch();

        self::assertStringNotContainsString('Duplicate (--skip-duplicates)', $buffer);
        self::assertStringContainsString('[R]', $buffer);

        $relativeSource = FileHelper::relativizePath($sourceFile, $directory);
        $relativeTarget = FileHelper::relativizePath($targetFile, $directory);

        self::assertStringContainsString($relativeSource, $buffer);
        self::assertStringContainsString($relativeTarget, $buffer);
        self::assertStringContainsString('Files processed', $buffer);
        self::assertStringNotContainsString('Duplicates found', $buffer);
    }

    /**
     * Verifies that each planned rename is listed with a [R] status prefix and both
     * relative source and target paths in the output.
     *
     * The log format is consumed by users reviewing dry-run output to verify the
     * rename plan before executing.
     */
    #[Test]
    public function renameFilesListsFilesWithStatusAndPaths(): void
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
            new RenameOptions(
                dryRun: true,
                sourceBaseDirectory: $sourceDirectory,
            ),
        );

        $buffer     = $output->fetch();
        $normalized = str_replace("\r", "\n", $buffer);

        $logPosition = strpos($normalized, '[R]');
        self::assertNotFalse($logPosition);

        $relativeSource = FileHelper::relativizePath($sourceFile, $sourceDirectory);
        $relativeTarget = FileHelper::relativizePath($targetFile, $sourceDirectory);

        self::assertStringContainsString($relativeSource, $normalized);
        self::assertStringContainsString($relativeTarget, $normalized);
    }

    /**
     * Verifies that when source and target base directories are absolute paths,
     * the output shows full absolute paths instead of relative ones.
     *
     * This matches user expectations for in-place renames where source == target
     * directory and the absolute path is the most unambiguous display.
     */
    #[Test]
    public function renameFilesDisplaysRelativePathsWhenBaseDirectoryIsAbsolute(): void
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
            new RenameOptions(
                dryRun: true,
                sourceBaseDirectory: $directory,
            ),
        );

        $buffer = $output->fetch();

        $relativeSource = FileHelper::relativizePath($sourceFile, $directory);
        $relativeTarget = FileHelper::relativizePath($targetFile, $directory);

        self::assertStringContainsString($relativeSource, $buffer);
        self::assertStringContainsString($relativeTarget, $buffer);
    }

    /**
     * Verifies that --list-all shows all rename entries with differentiated status
     * prefixes: [O] for the canonical (source == target), [R] for renames, and
     * [D] for duplicates, each followed by source and target paths.
     *
     * This output format enables users to audit the full rename plan at a glance.
     */
    #[Test]
    public function renameFilesDisplaysStatusPrefixesWhenListingAll(): void
    {
        [$service, $output] = $this->createService();

        $directory = $this->workspace . DIRECTORY_SEPARATOR . 'source-status';
        mkdir($directory);

        $canonicalPath   = $directory . DIRECTORY_SEPARATOR . 'photo.jpg';
        $renameSource    = $directory . DIRECTORY_SEPARATOR . 'rename.jpg';
        $renameTarget    = $canonicalPath;
        $duplicateSource = $directory . DIRECTORY_SEPARATOR . 'duplicate.jpg';
        $duplicateTarget = $directory . DIRECTORY_SEPARATOR . 'photo'
            . Constants::DUPLICATE_IDENTIFIER . '001.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($canonicalPath));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($canonicalPath), new SplFileInfo($canonicalPath)));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($renameSource), new SplFileInfo($renameTarget)));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($duplicateSource), new SplFileInfo($duplicateTarget)));

        $collection = new FileDuplicateCollection();
        $collection->set('status', $fileDuplicate);

        $service->renameFiles(
            $collection,
            new RenameOptions(
                dryRun: true,
                listAll: true,
                sourceBaseDirectory: $directory,
            ),
        );

        $buffer = $output->fetch();

        $relativeCanonicalSource = FileHelper::relativizePath($canonicalPath, $directory);
        $relativeCanonicalTarget = FileHelper::relativizePath($canonicalPath, $directory);
        $relativeRenameSource    = FileHelper::relativizePath($renameSource, $directory);
        $relativeDuplicateSource = FileHelper::relativizePath($duplicateSource, $directory);
        $relativeRenameTarget    = FileHelper::relativizePath($renameTarget, $directory);
        $relativeDuplicateTarget = FileHelper::relativizePath($duplicateTarget, $directory);

        self::assertStringContainsString('[O] ' . $relativeCanonicalSource, $buffer);
        self::assertStringContainsString('[R] ' . $relativeRenameSource, $buffer);
        self::assertStringContainsString('[D] ' . $relativeDuplicateSource, $buffer);
        self::assertStringContainsString('→ ' . $relativeCanonicalTarget, $buffer);
        self::assertStringContainsString('→ ' . $relativeRenameTarget, $buffer);
        self::assertStringContainsString('→ ' . $relativeDuplicateTarget, $buffer);
    }

    /**
     * Verifies that the summary includes "Live Photo groups" and "Duplicates found"
     * counters when the collection contains Live Photo groups and duplicate entries,
     * along with "Planned moves" and "Planned skips" for the appropriate options.
     */
    #[Test]
    public function renameFilesSummarisesLivePhotoGroupsAndCollisions(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'source-summary';
        $targetDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'target-summary';

        mkdir($sourceDirectory);
        mkdir($targetDirectory);

        $sourceFile      = $sourceDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        $duplicateSource = $sourceDirectory . DIRECTORY_SEPARATOR . 'video.mov';
        $targetFile      = $targetDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        $duplicateTarget = $targetDirectory . DIRECTORY_SEPARATOR
            . 'image' . Constants::DUPLICATE_IDENTIFIER . '007.mov';

        file_put_contents($sourceFile, 'original');
        file_put_contents($duplicateSource, 'duplicate');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($targetFile));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($sourceFile), new SplFileInfo($targetFile)));
        $fileDuplicate->addRename(new Rename(new SplFileInfo($duplicateSource), new SplFileInfo($duplicateTarget)));

        $collection = new FileDuplicateCollection();
        $collection->set('live-photo:content-id', $fileDuplicate);

        $service->renameFiles(
            $collection,
            new RenameOptions(
                skipDuplicates: true,
            ),
            new RenameResult(
                scannedFiles: 5,
            ),
        );

        $buffer = $output->fetch();

        self::assertStringContainsString('Scanned files', $buffer);
        self::assertStringContainsString('Planned moves', $buffer);
        self::assertStringContainsString('Planned skips', $buffer);
        self::assertStringContainsString('Live Photo groups', $buffer);
        self::assertStringContainsString('Duplicates found', $buffer);
        self::assertStringContainsString('Files processed', $buffer);
    }

    /**
     * Verifies that the summary displays the "Naming collisions" metric when
     * namingCollisions > 0 in the RenameOptions.
     *
     * Naming collisions occur when multiple files from different groups resolve
     * to the same target path. The metric helps users understand how many files
     * needed suffix disambiguation.
     */
    #[Test]
    public function renameFilesDisplaysNamingCollisionsMetric(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'source-collisions';
        $targetDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'target-collisions';

        mkdir($sourceDirectory);
        mkdir($targetDirectory);

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'image-001.jpg';

        file_put_contents($sourceFile, 'content');

        $fileDuplicateCollection = $this->createFileDuplicateCollection(
            $sourceFile,
            $targetFile,
        );

        $service->renameFiles(
            $fileDuplicateCollection,
            new RenameOptions(
                dryRun: true,
            ),
            new RenameResult(
                namingCollisions: 3,
            ),
        );

        $buffer = $output->fetch();

        self::assertStringContainsString('Naming collisions', $buffer);
        self::assertStringContainsString('3', $buffer);
    }

    /**
     * Verifies that the "Naming collisions" line is suppressed from the summary
     * when the count is zero, keeping the output clean for the common case.
     */
    #[Test]
    public function renameFilesHidesNamingCollisionsWhenZero(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'source-no-collisions';
        $targetDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'target-no-collisions';

        mkdir($sourceDirectory);
        mkdir($targetDirectory);

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . 'image.jpg';

        file_put_contents($sourceFile, 'content');

        $fileDuplicateCollection = $this->createFileDuplicateCollection(
            $sourceFile,
            $targetFile,
        );

        $service->renameFiles(
            $fileDuplicateCollection,
            new RenameOptions(
                dryRun: true,
            ),
            new RenameResult(
                namingCollisions: 0,
            ),
        );

        $buffer = $output->fetch();

        self::assertStringNotContainsString('Naming collisions', $buffer);
    }

    /**
     * Verifies that a RuntimeException is thrown when the target directory cannot
     * be created (e.g. a regular file blocks the path).
     *
     * This guards against silent data loss: without the exception the rename would
     * appear to succeed while the file was never moved.
     */
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

        $this->expectException(IOException::class);

        $service->renameFiles($fileDuplicateCollection);
    }

    /**
     * Verifies that a RuntimeException is thrown when the source file has been
     * deleted between planning and execution, causing the move to fail.
     */
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
        $this->expectExceptionMessage('Source file');

        $service->renameFiles($fileDuplicateCollection);
    }

    /**
     * Verifies that all rename entries within a group are listed in the output
     * during dry-run, including both source paths, [R] prefixes, and the
     * "Renaming files" / "Files to process" / "Planned moves" labels.
     */
    #[Test]
    public function renameFilesListsAllPlannedOperations(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'source-progress';
        $targetDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'target-progress';

        mkdir($sourceDirectory);
        mkdir($targetDirectory);

        $firstSource  = $sourceDirectory . DIRECTORY_SEPARATOR . 'first.jpg';
        $firstTarget  = $targetDirectory . DIRECTORY_SEPARATOR . 'first.jpg';
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

        $service->renameFiles($collection, new RenameOptions(dryRun: true));

        $buffer = $output->fetch();

        self::assertStringContainsString('[R]', $buffer);
        self::assertStringContainsString($firstSource, $buffer);
        self::assertStringContainsString($secondSource, $buffer);
        self::assertStringContainsString('Renaming files', $buffer);
        self::assertStringContainsString('Files to process', $buffer);
        self::assertStringContainsString('Planned moves', $buffer);
    }

    /**
     * Verifies that findAvailableDuplicateTarget() throws a RuntimeException when
     * all 999 possible -duplicate-NNN suffixes are occupied in the occupiedPaths set.
     *
     * This is the safety limit preventing infinite suffix searches. The exception
     * message includes the base name to aid debugging.
     */
    #[Test]
    public function findAvailableDuplicateTargetThrowsWhenMaxSuffixExceeded(): void
    {
        [$service] = $this->createService();

        $target = new SplFileInfo('/tmp/dir/photo.jpg');

        // Build occupiedPaths that block every suffix from 001..999
        /** @var array<string, true> $occupiedPaths */
        $occupiedPaths = [];

        for ($i = 1; $i <= 999; ++$i) {
            $occupiedPaths[sprintf('/tmp/dir/photo%s%03d.jpg', Constants::DUPLICATE_IDENTIFIER, $i)] = true;
        }

        $method = new ReflectionMethod($service, 'findAvailableDuplicateTarget');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Exceeded 999 attempts finding available target for "photo"');

        $method->invoke($service, $target, $occupiedPaths);
    }

    /**
     * Verifies that when two renames in the same batch target the same path, the
     * second file is automatically redirected to a -duplicate-NNN fallback path
     * instead of overwriting the first.
     *
     * This is the runtime collision safety net in copyOrMoveFile(): even when the
     * planning phase assigns the same target to two files (e.g. from different groups),
     * the execution phase detects the collision via the occupiedPaths index and finds
     * the next available suffix to prevent data loss.
     */
    #[Test]
    public function renameFilesRedirectsSecondFileWhenTargetBecomesOccupiedDuringBatch(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'source-collision';
        $targetDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'target-collision';

        mkdir($sourceDirectory);
        mkdir($targetDirectory);

        $sourceA = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $sourceB = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';

        file_put_contents($sourceA, 'content-A');
        file_put_contents($sourceB, 'content-B');

        // Both files are planned to land at the same target path.
        $sharedTarget = $targetDirectory . DIRECTORY_SEPARATOR . 'photo.jpg';

        // Group 1: a.jpg -> photo.jpg
        $duplicateA = new FileDuplicate();
        $duplicateA->setTarget(new SplFileInfo($sharedTarget));
        $duplicateA->addRename(new Rename(
            new SplFileInfo($sourceA),
            new SplFileInfo($sharedTarget),
        ));

        // Group 2: b.jpg -> photo.jpg (same target!)
        $duplicateB = new FileDuplicate();
        $duplicateB->setTarget(new SplFileInfo($sharedTarget));
        $duplicateB->addRename(new Rename(
            new SplFileInfo($sourceB),
            new SplFileInfo($sharedTarget),
        ));

        $collection = new FileDuplicateCollection();
        $collection->set('group-a', $duplicateA);
        $collection->set('group-b', $duplicateB);

        $service->renameFiles($collection);

        // File A should have been moved to the target path.
        self::assertFileDoesNotExist($sourceA);
        self::assertFileExists($sharedTarget);
        self::assertSame('content-A', file_get_contents($sharedTarget));

        // File B should have been redirected to a -duplicate-NNN fallback.
        self::assertFileDoesNotExist($sourceB);

        $fallbackTarget = $targetDirectory . DIRECTORY_SEPARATOR
            . 'photo' . Constants::DUPLICATE_IDENTIFIER . '001.jpg';

        self::assertFileExists($fallbackTarget);
        self::assertSame('content-B', file_get_contents($fallbackTarget));
    }

    /**
     * Verifies that findAvailableDuplicateTarget() strips an existing -duplicate-NNN
     * suffix from the basename before generating a new one, preventing nested suffixes
     * like "photo-duplicate-003-duplicate-001.jpg".
     *
     * When the runtime collision fallback is triggered for a target that already carries
     * a -duplicate-NNN suffix (e.g. because the planning phase assigned it), the method
     * must produce "photo-duplicate-001.jpg" instead of stacking another suffix.
     */
    #[Test]
    public function findAvailableDuplicateTargetStripsDuplicateSuffixBeforeGeneratingNew(): void
    {
        [$service] = $this->createService();

        $target = new SplFileInfo(
            '/tmp/dir/photo' . Constants::DUPLICATE_IDENTIFIER . '003.jpg'
        );

        // The unsuffixed "photo-duplicate-001.jpg" is free, so it should be selected.
        /** @var array<string, true> $occupiedPaths */
        $occupiedPaths = [
            $target->getPathname() => true,
        ];

        $method = new ReflectionMethod($service, 'findAvailableDuplicateTarget');

        /** @var SplFileInfo $result */
        $result = $method->invoke($service, $target, $occupiedPaths);

        // The result should be "photo-duplicate-001.jpg", NOT "photo-duplicate-003-duplicate-001.jpg"
        self::assertSame(
            '/tmp/dir/photo' . Constants::DUPLICATE_IDENTIFIER . '001.jpg',
            $result->getPathname(),
        );

        // Verify no nested -duplicate- pattern exists
        self::assertDoesNotMatchRegularExpression(
            '/-duplicate-\d+-duplicate-/',
            $result->getPathname(),
            'Must not produce nested duplicate suffixes',
        );
    }

    /**
     * Verifies that findAvailableDuplicateTarget() skips occupied suffix numbers and
     * returns the first available one after stripping the existing suffix.
     *
     * When "photo-duplicate-001.jpg" is occupied, the method should try 002, 003, etc.
     * until finding a free slot, all based on the stripped basename "photo".
     */
    #[Test]
    public function findAvailableDuplicateTargetSkipsOccupiedSuffixesAfterStripping(): void
    {
        [$service] = $this->createService();

        $target = new SplFileInfo(
            '/tmp/dir/photo' . Constants::DUPLICATE_IDENTIFIER . '005.jpg'
        );

        // Block suffix 001 and 002 to force the method to find 003.
        /** @var array<string, true> $occupiedPaths */
        $occupiedPaths = [
            $target->getPathname()                                         => true,
            '/tmp/dir/photo' . Constants::DUPLICATE_IDENTIFIER . '001.jpg' => true,
            '/tmp/dir/photo' . Constants::DUPLICATE_IDENTIFIER . '002.jpg' => true,
        ];

        $method = new ReflectionMethod($service, 'findAvailableDuplicateTarget');

        /** @var SplFileInfo $result */
        $result = $method->invoke($service, $target, $occupiedPaths);

        self::assertSame(
            '/tmp/dir/photo' . Constants::DUPLICATE_IDENTIFIER . '003.jpg',
            $result->getPathname(),
        );
    }

    /**
     * Verifies that a pre-existing file in the target directory is not overwritten
     * when a rename operation targets the same path. The service must detect the
     * occupied target and redirect to a -duplicate-NNN fallback.
     */
    #[Test]
    public function renameFilesDoesNotOverwritePreExistingFileInSameDirectory(): void
    {
        [$service, $output] = $this->createService();

        $directory = $this->workspace . DIRECTORY_SEPARATOR . 'source-preexist';
        mkdir($directory);

        // Pre-existing file that is also a rename source (hence in occupiedPaths)
        $existingFile = $directory . DIRECTORY_SEPARATOR . 'photo.jpg';
        file_put_contents($existingFile, 'existing-content');

        // Source file that wants to land at the same target path as the existing file
        $sourceFile = $directory . DIRECTORY_SEPARATOR . 'incoming.jpg';
        file_put_contents($sourceFile, 'incoming-content');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate->setTarget(new SplFileInfo($existingFile));
        // The existing file is also part of the rename batch (source == target)
        $fileDuplicate->addRename(new Rename(
            new SplFileInfo($existingFile),
            new SplFileInfo($existingFile),
        ));
        $fileDuplicate->addRename(new Rename(
            new SplFileInfo($sourceFile),
            new SplFileInfo($existingFile),
        ));

        $collection = new FileDuplicateCollection();
        $collection->set('preexist', $fileDuplicate);

        $service->renameFiles(
            $collection,
            new RenameOptions(
                sourceBaseDirectory: $directory,
            ),
        );

        // The pre-existing file must keep its original content (source == target, no-op)
        self::assertFileExists($existingFile);
        self::assertSame('existing-content', file_get_contents($existingFile));

        // The incoming file must have been redirected to a fallback path
        self::assertFileDoesNotExist($sourceFile);

        $fallbackTarget = $directory . DIRECTORY_SEPARATOR
            . 'photo' . Constants::DUPLICATE_IDENTIFIER . '001.jpg';

        self::assertFileExists($fallbackTarget);
        self::assertSame('incoming-content', file_get_contents($fallbackTarget));
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
                $progressBar               = parent::createProgressBar($max);
                $this->capturedProgressBar = $progressBar;

                return $progressBar;
            }
        };

        $renderer = new RenameOutputRenderer($io);

        return [new FileSystemService($io, $renderer), $output, $io];
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
}
