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
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
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
use MagicSunday\Renamer\Model\SkippedFile;
use MagicSunday\Renamer\Model\TargetFileResult;
use MagicSunday\Renamer\Service\DuplicateDetectionService;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\HashSubGroupingService;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\LivePhotoAwareRenameStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

use function file_put_contents;
use function is_dir;
use function iterator_to_array;
use function mkdir;
use function preg_match;
use function preg_quote;
use function rename;
use function sprintf;

use const DIRECTORY_SEPARATOR;

#[CoversClass(DuplicateDetectionService::class)]
#[CoversClass(HashSubGroupingService::class)]
#[CoversClass(FileDuplicateCollection::class)]
#[CoversClass(FileDuplicate::class)]
#[CoversClass(RenameList::class)]
#[CoversClass(Rename::class)]
#[CoversClass(TargetBasenameStrategy::class)]
#[UsesClass(FileHelper::class)]
/**
 * Verifies the DuplicateDetectionService, the core orchestrator that groups
 * source files by duplicate identifier, applies hash sub-grouping, and assigns
 * target filenames with appropriate duplicate/sequential suffixes.
 *
 * The service implements the central pipeline stages:
 * - groupFilesByDuplicateIdentifier: scan/group/pair files into FileDuplicateCollection
 * - createDuplicateFilenames: resolve canonical targets, assign -NNN sub-groups and
 *   -duplicate-NNN suffixes, handle idempotency for re-runs
 *
 * These tests exercise the service through its public API with real temp files on disk
 * to validate hash computation, path resolution, and suffix assignment end-to-end.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[UsesClass(FileList::class)]
#[UsesClass(LinkConfig::class)]
#[UsesClass(OutputEntryTag::class)]
#[UsesClass(RenameOptions::class)]
#[UsesClass(RenameResult::class)]
#[UsesClass(SkippedFile::class)]
#[UsesClass(TargetFileResult::class)]
#[UsesClass(FileSystemService::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(RenameOutputRenderer::class)]
#[UsesClass(SafeHashCalculator::class)]
final class DuplicateDetectionServiceTest extends TestCase
{
    use WorkspaceTrait;

    /** @var list<string> */
    private array $tempDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirectories as $directory) {
            $this->removeWorkspace($directory);
        }

        $this->tempDirectories = [];
    }

    /**
     * Verifies that the grouping progress bar includes ETA and remaining-count
     * information in its output format.
     *
     * ETA display is important for large photo libraries where grouping may take
     * several minutes. This test ensures the custom progress format string is
     * applied, not the Symfony default.
     */
    #[Test]
    public function groupFilesByDuplicateIdentifierDisplaysEtaInformation(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'source.jpg';
        file_put_contents($sourceFile, 'source');

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
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
            $sourceDirectory,
        );

        $progressOutput = $output->fetch();

        self::assertStringContainsString('| ETA:', $progressOutput);
        self::assertStringContainsString('| Remaining:', $progressOutput);
    }

    /**
     * Verifies that the duplicate filename assignment progress bar also includes
     * ETA and remaining-count information.
     *
     * This stage iterates every group and computes hashes, which can be slow for
     * large files. The ETA helps the user estimate total processing time.
     */
    #[Test]
    public function createDuplicateFilenamesDisplaysEtaInformation(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'source.jpg';
        $targetFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        file_put_contents($sourceFile, 'source');
        file_put_contents($targetFile, 'target');

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($sourceFile))
            ->setTarget(new SplFileInfo($targetFile));

        $fileDuplicateCollection = new FileDuplicateCollection();
        $fileDuplicateCollection->set('identifier', $fileDuplicate);

        $service->createDuplicateFilenames(
            $fileDuplicateCollection,
            $sourceDirectory,
        );

        $progressOutput = $output->fetch();

        self::assertStringContainsString('| ETA:', $progressOutput);
        self::assertStringContainsString('| Remaining:', $progressOutput);
    }

    /**
     * Verifies that a TargetFilenameException thrown by the rename strategy during
     * grouping is caught, logged as a warning, and the file is skipped without
     * aborting the entire batch.
     *
     * This protects against corrupt or unreadable EXIF data in individual files.
     * The resulting collection must be empty (the single file was skipped), and
     * the error message must appear in the console output.
     */
    #[Test]
    public function groupFilesByDuplicateIdentifierHandlesTargetFilenameException(): void
    {
        [$service, $output] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'source.jpg';
        file_put_contents($sourceFile, 'source');

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
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
            $sourceDirectory,
        );

        self::assertCount(0, $collection);

        $skippedFiles = $service->getSkippedFiles();

        self::assertCount(1, $skippedFiles);
        self::assertSame('boom', $skippedFiles[0]->getReason());
        self::assertTrue($skippedFiles[0]->isError());
    }

    /**
     * Verifies that when a MOV file is encountered before its paired HEIC in iteration
     * order, the FileDuplicate target is set to the still image's target, not the video's.
     *
     * Live Photo groups must always use the still image as canonical because the
     * companion MOV inherits the base name from the canonical. If the MOV were
     * canonical, the HEIC would receive a mismatched name.
     */
    #[Test]
    public function groupFilesByDuplicateIdentifierPrefersStillTargetForLivePhotos(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $videoPath = $sourceDirectory . DIRECTORY_SEPARATOR . '0001.MOV';
        $photoPath = $sourceDirectory . DIRECTORY_SEPARATOR . '999.HEIC';

        file_put_contents($videoPath, 'video');
        file_put_contents($photoPath, 'photo');

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
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
            $sourceDirectory,
        );

        $duplicate = $collection->get('live-photo:content-id');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);
        self::assertSame(
            $sourceDirectory . DIRECTORY_SEPARATOR . 'photo-target.heic',
            $duplicate->getTarget()->getPathname(),
        );

        $files = iterator_to_array($duplicate->getFiles());
        self::assertCount(2, $files);
        self::assertSame($videoPath, $files[0]->getPathname());
        self::assertSame($photoPath, $files[1]->getPathname());
    }

    /**
     * Verifies that a video file whose rename strategy returns null (no date) but
     * which shares a Live Photo content identifier with a photo is still added to
     * the same group.
     *
     * MOV companions often lack EXIF dates; the content identifier is the only way
     * to associate them with their paired still image. This test confirms that the
     * pending-file mechanism correctly defers the video and attaches it once the
     * photo establishes the group.
     */
    #[Test]
    public function groupFilesByDuplicateIdentifierAddsPendingFilesWithSharedContentIdentifier(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $photoPath = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.HEIC';
        $videoPath = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.MOV';

        file_put_contents($photoPath, 'photo');
        file_put_contents($videoPath, 'video');

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
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
            $sourceDirectory,
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

    /**
     * Verifies that when the video is iterated before the photo (reverse iteration
     * order), the pending-file mechanism still pairs them into the same group once
     * the photo is encountered.
     *
     * Iteration order depends on the filesystem and is not guaranteed. This test
     * ensures the grouping is order-independent for Live Photo content identifier
     * pairing.
     */
    #[Test]
    public function groupFilesByDuplicateIdentifierAddsVideoEncounteredBeforePhoto(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $photoPath = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.HEIC';
        $videoPath = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.MOV';

        file_put_contents($photoPath, 'photo');
        file_put_contents($videoPath, 'video');

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
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
            $sourceDirectory,
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

    /**
     * Verifies that files in the parent directory are grouped before files in
     * subdirectories, even when the underlying filesystem iterator yields
     * subdirectory files first (depth-first traversal).
     *
     * Parent-first ordering ensures that the canonical target for a group is
     * always the parent-directory file, not the nested one. Without this,
     * a photo in a subdirectory could become canonical, placing the unsuffixed
     * target in the wrong directory.
     */
    #[Test]
    public function groupFilesByDuplicateIdentifierProcessesParentDirectoryBeforeSubdirectories(): void
    {
        [$service] = $this->createService();

        $parentDirectory = $this->createTempDirectory();
        $subDirectory    = $parentDirectory . DIRECTORY_SEPARATOR . 'sub';
        mkdir($subDirectory);

        $subFile    = $subDirectory . DIRECTORY_SEPARATOR . 'sub-photo.jpg';
        $parentFile = $parentDirectory . DIRECTORY_SEPARATOR . 'parent-photo.jpg';

        file_put_contents($subFile, 'sub-content');
        file_put_contents($parentFile, 'parent-content');

        // DIR_CONTEXT: src=$parentDirectory tgt=$sourceDirectory ext=None
        // Simulate depth-first traversal: subdirectory file comes first.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([
                new SplFileInfo($subFile),
                new SplFileInfo($parentFile),
            ], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $renameStrategy = $this->createMock(RenameStrategyInterface::class);
        $renameStrategy
            ->expects(self::exactly(2))
            ->method('generateFilename')
            ->willReturnCallback(static fn (SplFileInfo $file): string => $file->getFilename());

        $duplicateIdentifierStrategy = $this->createMock(DuplicateIdentifierStrategyInterface::class);
        $duplicateIdentifierStrategy
            ->expects(self::exactly(2))
            ->method('generateIdentifier')
            ->willReturnCallback(static fn (SplFileInfo $source): string => $source->getFilename());

        $collection = $service->groupFilesByDuplicateIdentifier(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
            $parentDirectory,
        );

        $keys = array_keys(iterator_to_array($collection));

        // Parent directory file must be processed before subdirectory file.
        $parentIndex = array_search('parent-photo.jpg', $keys, true);
        $subIndex    = array_search('sub-photo.jpg', $keys, true);

        self::assertIsInt($parentIndex);
        self::assertIsInt($subIndex);
        self::assertLessThan($subIndex, $parentIndex, 'Parent directory files must be grouped before subdirectory files');
    }

    /**
     * Verifies that when useFileExtensionFromSource is enabled, the target filename
     * preserves each source file's original extension rather than inheriting the
     * canonical target's extension.
     *
     * This is essential for the EXIF date command where mixed extensions (jpg, png,
     * heic) share the same date-based target basename. Without source-extension
     * preservation, a PNG file would be incorrectly renamed to .jpg.
     */
    #[Test]
    public function createDuplicateFilenamesUsesSourceExtensionWhenConfigured(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $jpgFile    = $sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg';
        $pngFile    = $sourceDirectory . DIRECTORY_SEPARATOR . 'photo.png';
        $targetFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'renamed.jpg';

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

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=true
        $service->createDuplicateFilenames(
            $collection,
            $sourceDirectory,
            useFileExtensionFromSource: true,
        );

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(2, $renames);
        self::assertSame(
            $sourceDirectory . DIRECTORY_SEPARATOR . 'renamed.jpg',
            $renames[0]->getTarget()->getPathname(),
        );
        self::assertStringContainsString(
            Constants::DUPLICATE_IDENTIFIER,
            $renames[1]->getTarget()->getFilename(),
        );
        self::assertStringEndsWith('.png', $renames[1]->getTarget()->getFilename());
    }

    /**
     * Verifies that in a Live Photo group without a content-ID map (i.e. without
     * a prior groupFilesByDuplicateIdentifier call), the photo and video are treated
     * as separate hash sub-groups: the photo is canonical and keeps the base name,
     * the video gets sub-group -002.
     *
     * This covers the fallback path where companion detection cannot pair the video
     * because the content-ID map was not populated. The test also confirms the
     * renames are visible in dry-run output.
     */
    #[Test]
    public function createDuplicateFilenamesKeepsLivePhotoVideoWithoutDuplicateSuffix(): void
    {
        [$service, $output, $fileSystemService] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $photoSource     = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.HEIC';
        $videoSource     = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.MOV';
        $canonicalTarget = $sourceDirectory . DIRECTORY_SEPARATOR . '20240101_120000.heic';

        file_put_contents($photoSource, 'photo');
        file_put_contents($videoSource, 'video');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($photoSource))
            ->addFile(new SplFileInfo($videoSource))
            ->setTarget(new SplFileInfo($canonicalTarget));

        $collection = new FileDuplicateCollection();
        $collection->set('live-photo:content-id', $fileDuplicate);

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=true
        // Without groupFilesByDuplicateIdentifier the content-ID map is empty,
        // so companion detection cannot pair the video. Hash sub-grouping treats
        // the two distinct-content files as separate sub-groups: the canonical
        // (photo) keeps the base name, the video gets sub-group -002.
        $service->createDuplicateFilenames(
            $collection,
            $sourceDirectory,
            useFileExtensionFromSource: true,
        );

        $duplicate = $collection->get('live-photo:content-id');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(2, $renames);

        $expectedCanonicalTarget = $sourceDirectory . DIRECTORY_SEPARATOR . '20240101_120000.heic';
        $expectedVideoTarget     = $sourceDirectory . DIRECTORY_SEPARATOR . '20240101_120000-002.mov';

        $canonicalRename = null;
        $videoRename     = null;

        foreach ($renames as $rename) {
            if ($rename->getSource()->getPathname() === $photoSource) {
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

        // Clear progress output from duplicate generation.
        $output->fetch();

        $fileSystemService->renameFiles($collection, new RenameOptions(dryRun: true));

        $renameOutput = $output->fetch();

        self::assertStringContainsString('[R]', $renameOutput);
    }

    /**
     * Verifies that two files with identical content (same hash) in the same group
     * receive incremental -duplicate-NNN suffixes, and that the target file already
     * existing on disk does not cause the canonical to be displaced.
     *
     * This is the classic true-duplicate scenario: the canonical target is already
     * occupied on disk, so both source files get duplicate suffixes starting at 001.
     */
    #[Test]
    public function createDuplicateFilenamesGeneratesIncrementalDuplicateTargetsForIdenticalContent(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $sourceOne  = $sourceDirectory . DIRECTORY_SEPARATOR . 'one.jpg';
        $sourceTwo  = $sourceDirectory . DIRECTORY_SEPARATOR . 'two.jpg';
        $targetFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'renamed.jpg';

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

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
        $service->createDuplicateFilenames(
            $collection,
            $sourceDirectory,
        );

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(2, $renames);
        self::assertSame(
            $sourceDirectory . DIRECTORY_SEPARATOR . 'renamed-duplicate-001.jpg',
            $renames[0]->getTarget()->getPathname(),
        );
        self::assertSame(
            $sourceDirectory . DIRECTORY_SEPARATOR . 'renamed-duplicate-002.jpg',
            $renames[1]->getTarget()->getPathname(),
        );
    }

    /**
     * Verifies that files in nested subdirectories retain their relative directory
     * structure in the target path, and that a pre-renamed file with an existing
     * -duplicate-NNN suffix keeps a numeric suffix rather than being stripped to
     * the base name.
     *
     * This prevents nested duplicates from being flattened into the root target
     * directory, which would mix files from different source subdirectories.
     */
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
            . Constants::DUPLICATE_IDENTIFIER . '001.jpg';

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

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
        $service->createDuplicateFilenames(
            $collection,
            $sourceDirectory,
        );

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(3, $renames);

        $renamesBySource = [];

        foreach ($renames as $rename) {
            $renamesBySource[$rename->getSource()->getPathname()] = $rename;
        }

        $pattern = '/' . preg_quote(Constants::DUPLICATE_IDENTIFIER, '/') . '(\d{3})\.jpg$/';

        // Root file = canonical (no suffix)
        self::assertArrayHasKey($rootFile, $renamesBySource);
        self::assertSame(
            $sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg',
            $renamesBySource[$rootFile]->getTarget()->getPathname(),
        );

        // nested/photo.jpg is a cross-directory duplicate — must get a duplicate suffix
        // even though source == target in its own directory, because the canonical is in root.
        self::assertArrayHasKey($duplicateFile, $renamesBySource);
        self::assertSame(
            1,
            preg_match($pattern, $renamesBySource[$duplicateFile]->getTarget()->getFilename()),
            'Cross-directory duplicate nested/photo.jpg gets a duplicate suffix',
        );

        // nested/photo-duplicate-001.jpg keeps its suffix (target photo.jpg is occupied).
        self::assertArrayHasKey($preRenamedDuplicate, $renamesBySource);
        self::assertSame(
            1,
            preg_match($pattern, $renamesBySource[$preRenamedDuplicate]->getTarget()->getFilename()),
            'Pre-renamed nested duplicate keeps a numeric duplicate suffix.',
        );
    }

    /**
     * Verifies that the canonical file (first in the group, same content) receives
     * the unsuffixed target path and appears as the first rename entry, while the
     * duplicate receives -duplicate-001.
     *
     * This is the contract relied upon by the --list-all flag: the canonical rename
     * entry (source == target or source -> base name) must be present in the list
     * so the output can show it with an [O] status prefix.
     */
    #[Test]
    public function createDuplicateFilenamesKeepsCanonicalEntriesWhenListAllEnabled(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $canonicalSource = $sourceDirectory . DIRECTORY_SEPARATOR . 'original.jpg';
        $duplicateSource = $sourceDirectory . DIRECTORY_SEPARATOR . 'duplicate.jpg';
        $targetPath      = $sourceDirectory . DIRECTORY_SEPARATOR . 'renamed.jpg';

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

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
        $service->createDuplicateFilenames(
            $collection,
            $sourceDirectory,
        );

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(2, $renames);
        self::assertSame($canonicalSource, $renames[0]->getSource()->getPathname());
        self::assertSame($targetPath, $renames[0]->getTarget()->getPathname());
        self::assertSame(
            $sourceDirectory . DIRECTORY_SEPARATOR . 'renamed-duplicate-001.jpg',
            $renames[1]->getTarget()->getPathname(),
        );
    }

    /**
     * Verifies idempotency: when a file was already renamed to a -duplicate-001
     * target in a previous run and the command is run again, the file keeps its
     * existing suffixed name rather than receiving a new suffix.
     *
     * Without this, every re-run would increment the suffix, causing files to
     * accumulate ever-growing chains of -duplicate-NNN suffixes.
     */
    #[Test]
    public function createDuplicateFilenamesKeepsExistingDuplicateSuffixOnSubsequentRun(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'source.jpg';
        $targetFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        file_put_contents($sourceFile, 'source');
        file_put_contents($targetFile, 'target');

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
        $initialDuplicate = new FileDuplicate();
        $initialDuplicate
            ->addFile(new SplFileInfo($sourceFile))
            ->setTarget(new SplFileInfo($targetFile));

        $initialCollection = new FileDuplicateCollection();
        $initialCollection->set('identifier', $initialDuplicate);

        $service->createDuplicateFilenames(
            $initialCollection,
            $sourceDirectory,
        );

        $firstDuplicate = $initialCollection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $firstDuplicate);

        $firstRenames = iterator_to_array($firstDuplicate->getRenames());
        self::assertCount(1, $firstRenames);

        $expectedTargetPath = $firstRenames[0]->getTarget()->getPathname();
        self::assertStringContainsString(
            Constants::DUPLICATE_IDENTIFIER . '001',
            $firstRenames[0]->getTarget()->getFilename(),
        );

        self::assertTrue(
            rename($sourceFile, $expectedTargetPath),
            'Failed to move source file to duplicate target path.',
        );

        $subsequentDuplicate = new FileDuplicate();
        $subsequentDuplicate
            ->addFile(new SplFileInfo($expectedTargetPath))
            ->setTarget(new SplFileInfo($targetFile));

        $subsequentCollection = new FileDuplicateCollection();
        $subsequentCollection->set('identifier', $subsequentDuplicate);

        $service->createDuplicateFilenames(
            $subsequentCollection,
            $sourceDirectory,
        );

        $secondDuplicate = $subsequentCollection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $secondDuplicate);

        $renames = iterator_to_array($secondDuplicate->getRenames());
        self::assertCount(1, $renames);
        self::assertSame($expectedTargetPath, $renames[0]->getTarget()->getPathname());
    }

    /**
     * Verifies that getTargetPathname() correctly preserves nested directory names
     * even when a subdirectory has the same name as the source root (e.g.
     * Photos/Photos/image.jpg).
     *
     * The relative path calculation must strip exactly the source root prefix
     * without accidentally removing deeper path components that happen to share
     * the same name.
     */
    #[Test]
    public function getTargetPathnameRetainsNestedDirectoriesWithDuplicateNames(): void
    {
        [$service] = $this->createService();

        $sourceRoot      = $this->createTempDirectory();
        $sourceDirectory = $sourceRoot . DIRECTORY_SEPARATOR . 'Photos';
        $nestedDirectory = $sourceDirectory . DIRECTORY_SEPARATOR . 'Photos';

        if (!mkdir($sourceDirectory, 0777, true) && !is_dir($sourceDirectory)) {
            self::fail('Failed to create source directory: ' . $sourceDirectory);
        }

        if (!mkdir($nestedDirectory, 0777, true) && !is_dir($nestedDirectory)) {
            self::fail('Failed to create nested directory: ' . $nestedDirectory);
        }

        $sourceFile = $nestedDirectory . DIRECTORY_SEPARATOR . 'image.jpg';
        file_put_contents($sourceFile, 'image');

        $this->setServiceDirectories($service, $sourceDirectory);

        $method         = new ReflectionMethod($service, 'getTargetPathname');
        $targetPathname = $method->invoke($service, new SplFileInfo($sourceFile), 'renamed.jpg');

        self::assertSame(
            $sourceDirectory . DIRECTORY_SEPARATOR . 'Photos' . DIRECTORY_SEPARATOR . 'renamed.jpg',
            $targetPathname,
        );
    }

    /**
     * Verifies that getTargetPathname() maps a file from a nested source directory
     * to the corresponding nested path under the target directory.
     *
     * When source=/tmp/src and target=/tmp/dst, a file at /tmp/src/nested/photo.jpg
     * must resolve to /tmp/dst/nested/renamed.jpg. This preserves the album/folder
     * structure during cross-directory renames.
     */
    #[Test]
    public function getTargetPathnamePreservesRelativeDepthForAbsoluteDirectories(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $nestedSource = $sourceDirectory . DIRECTORY_SEPARATOR . 'nested';

        if (!mkdir($nestedSource) && !is_dir($nestedSource)) {
            self::fail('Failed to create nested source directory: ' . $nestedSource);
        }

        $sourceFile = $nestedSource . DIRECTORY_SEPARATOR . 'example.jpg';
        file_put_contents($sourceFile, 'example');

        $this->setServiceDirectories($service, $sourceDirectory);

        $method         = new ReflectionMethod($service, 'getTargetPathname');
        $targetPathname = $method->invoke($service, new SplFileInfo($sourceFile), 'renamed.jpg');

        self::assertSame(
            $sourceDirectory . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'renamed.jpg',
            $targetPathname,
        );
    }

    /**
     * Verifies that two files with different content hashes in the same date group
     * receive sequential sub-group numbers (-002, -003, ...) instead of -duplicate-
     * suffixes, and that the canonical sub-group keeps the unsuffixed base name.
     *
     * Hash sub-grouping distinguishes "different photos taken at the same second"
     * from "true duplicates of the same photo". The former get sequential numbers,
     * the latter get -duplicate-NNN within their sub-group.
     */
    #[Test]
    public function createDuplicateFilenamesAssignsSequentialNumbersForDistinctContent(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $fileA = $sourceDirectory . DIRECTORY_SEPARATOR . 'photo_a.jpg';
        $fileB = $sourceDirectory . DIRECTORY_SEPARATOR . 'photo_b.jpg';

        file_put_contents($fileA, 'content-of-file-A');
        file_put_contents($fileB, 'content-of-file-B-different');

        $targetFile = $sourceDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
        $service->createDuplicateFilenames(
            $collection,
            $sourceDirectory,
        );

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(2, $renames);

        // Canonical sub-group: unsuffixed base name
        self::assertSame(
            $sourceDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.jpg',
            $renames[0]->getTarget()->getPathname(),
        );

        // Second sub-group: starts at -002
        self::assertSame(
            $sourceDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28-002.jpg',
            $renames[1]->getTarget()->getPathname(),
        );

        // Neither contains -duplicate-
        self::assertStringNotContainsString(
            Constants::DUPLICATE_IDENTIFIER,
            $renames[0]->getTarget()->getFilename(),
        );
        self::assertStringNotContainsString(
            Constants::DUPLICATE_IDENTIFIER,
            $renames[1]->getTarget()->getFilename(),
        );
    }

    /**
     * Verifies idempotency for sequential suffixes: a single file that already
     * carries a -001 suffix from a previous run reclaims the unsuffixed base name
     * when it is the only file in its group.
     *
     * This prevents the accumulation of stale sub-group numbers on re-runs when
     * the other files from the original group have been deleted.
     */
    #[Test]
    public function createDuplicateFilenamesCanonicalReclaimsBaseNameFromSequentialSuffix(): void
    {
        [$service] = $this->createService();

        $directory = $this->createTempDirectory();

        // Single file with sequential suffix from a previous run.
        // As the only file in the group, it is canonical and must get the unsuffixed base name.
        $sourceFile = $directory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28-001.jpg';
        $targetFile = $directory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.jpg';

        file_put_contents($sourceFile, 'already-renamed');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($sourceFile))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        // DIR_CONTEXT: src=$directory tgt=$directory ext=None
        $service->createDuplicateFilenames(
            $collection,
            $directory,
        );

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(1, $renames);

        // Canonical reclaims the unsuffixed base name.
        self::assertSame($targetFile, $renames[0]->getTarget()->getPathname());
    }

    /**
     * Verifies idempotency for compound suffixes: a single file with a combined
     * -001-duplicate-001 suffix from a previous run reclaims the unsuffixed base
     * name when it is the only file in its group.
     *
     * Compound suffixes arise when hash sub-grouping assigns -NNN and then a true
     * duplicate within that sub-group gets -duplicate-NNN appended. On re-run with
     * the file as sole group member, both suffixes must be stripped.
     */
    #[Test]
    public function createDuplicateFilenamesCanonicalReclaimsBaseNameFromCompoundSuffix(): void
    {
        [$service] = $this->createService();

        $directory = $this->createTempDirectory();

        // Single file with compound suffix from a previous run.
        $sourceFile = $directory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28-001-duplicate-001.jpg';
        $targetFile = $directory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.jpg';

        file_put_contents($sourceFile, 'already-renamed-compound');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($sourceFile))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        // DIR_CONTEXT: src=$directory tgt=$directory ext=None
        $service->createDuplicateFilenames(
            $collection,
            $directory,
        );

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(1, $renames);

        // Canonical reclaims the unsuffixed base name.
        self::assertSame($targetFile, $renames[0]->getTarget()->getPathname());
    }

    /**
     * Verifies idempotency for legacy -duplicate-NNN suffixes: a single file with
     * only a -duplicate-001 suffix (no sequential number) reclaims the unsuffixed
     * base name when it is the only file in its group.
     *
     * This covers files renamed by an older version of the tool that did not use
     * hash sub-grouping. On re-run the canonical must still get the clean name.
     */
    #[Test]
    public function createDuplicateFilenamesCanonicalReclaimsBaseNameFromLegacyDuplicateSuffix(): void
    {
        [$service] = $this->createService();

        $directory = $this->createTempDirectory();

        // Single file with legacy suffix from a previous run.
        $sourceFile = $directory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28-duplicate-001.jpg';
        $targetFile = $directory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.jpg';

        file_put_contents($sourceFile, 'already-renamed-legacy');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($sourceFile))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        // DIR_CONTEXT: src=$directory tgt=$directory ext=None
        $service->createDuplicateFilenames(
            $collection,
            $directory,
        );

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(1, $renames);

        // Canonical reclaims the unsuffixed base name.
        self::assertSame($targetFile, $renames[0]->getTarget()->getPathname());
    }

    /**
     * Verifies that in a Live Photo group without content-ID map population, all
     * three files (image A, MOV A, image B) with distinct hashes are treated as
     * separate sub-groups: canonical keeps the base name, MOV gets -002, image B
     * gets -003.
     *
     * This is the fallback when companion detection cannot pair the video (no
     * groupFilesByDuplicateIdentifier call). Each distinct-hash file becomes its
     * own sub-group, with extensions preserved from source.
     */
    #[Test]
    public function createDuplicateFilenamesCompanionInheritsSubGroupNumber(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        // Image A (hash X) + companion MOV A + image B (hash Y)
        $imageA = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.HEIC';
        $movA   = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.MOV';
        $imageB = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.HEIC';

        file_put_contents($imageA, 'image-content-A');
        file_put_contents($movA, 'video-content-A');
        file_put_contents($imageB, 'image-content-B-different');

        $targetFile = $sourceDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.heic';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($imageA))
            ->addFile(new SplFileInfo($movA))
            ->addFile(new SplFileInfo($imageB))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('live-photo:content-id', $fileDuplicate);

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=true
        $service->createDuplicateFilenames(
            $collection,
            $sourceDirectory,
            useFileExtensionFromSource: true,
        );

        $duplicate = $collection->get('live-photo:content-id');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames       = iterator_to_array($duplicate->getRenames());
        $renameTargets = [];

        foreach ($renames as $rename) {
            $renameTargets[$rename->getSource()->getPathname()] = $rename->getTarget()->getFilename();
        }

        self::assertCount(3, $renames);

        // Without the content-ID map (no groupFilesByDuplicateIdentifier call),
        // companion detection returns null. All three files have distinct hashes
        // and are treated as separate sub-groups.

        // Image A → base name (canonical sub-group, no number)
        self::assertSame('2025-01-01_00-02-28.heic', $renameTargets[$imageA]);

        // MOV A → sub-group 002 (no companion pairing without content-ID map)
        self::assertSame('2025-01-01_00-02-28-002.mov', $renameTargets[$movA]);

        // Image B → sub-group 003
        self::assertSame('2025-01-01_00-02-28-003.heic', $renameTargets[$imageB]);
    }

    /**
     * Verifies graceful degradation when the hash calculator throws a
     * HashComputationException for one file in a multi-file group.
     *
     * The failing file is treated as having a unique hash (its own sub-group),
     * the error is logged, and processing continues. Both files still receive
     * valid target names: canonical keeps the base name, the failing file gets -002.
     */
    #[Test]
    public function createDuplicateFilenamesHandlesHashComputationFailure(): void
    {
        $hashCalculator = $this->createMock(SafeHashCalculator::class);

        $sourceDirectory = $this->createTempDirectory();

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

        $mediaTypeClassifier    = new MediaTypeClassifier();
        $hashSubGroupingService = new HashSubGroupingService($hashCalculator, $io, $mediaTypeClassifier);
        $service                = new DuplicateDetectionService($io, $hashSubGroupingService, $mediaTypeClassifier);

        $targetFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
        $service->createDuplicateFilenames(
            $collection,
            $sourceDirectory,
        );

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        // Both files get their own sub-group: canonical keeps base name, file B gets -002.
        self::assertCount(2, $renames);
        self::assertSame('target.jpg', $renames[0]->getTarget()->getFilename());
        self::assertSame('target-002.jpg', $renames[1]->getTarget()->getFilename());

        // Error message was logged.
        $progressOutput = $output->fetch();
        self::assertStringContainsString('Cannot read file', $progressOutput);
    }

    /**
     * Verifies that passing skipHashSubGrouping: true bypasses the hash calculator
     * entirely and falls back to the legacy behaviour where all non-canonical files
     * get -duplicate-NNN suffixes regardless of content.
     *
     * This is used by commands (like rename:hash) that already group by content hash
     * and therefore do not need a second round of hash-based sub-grouping.
     */
    #[Test]
    public function createDuplicateFilenamesSkipHashSubGroupingPreservesOldBehavior(): void
    {
        $hashCalculator = $this->createMock(SafeHashCalculator::class);
        $hashCalculator
            ->expects(self::never())
            ->method('hashFile');

        $output = new BufferedOutput();
        $io     = new SymfonyStyle(new ArrayInput([]), $output);

        $mediaTypeClassifier    = new MediaTypeClassifier();
        $hashSubGroupingService = new HashSubGroupingService($hashCalculator, $io, $mediaTypeClassifier);
        $service                = new DuplicateDetectionService($io, $hashSubGroupingService, $mediaTypeClassifier);

        $sourceDirectory = $this->createTempDirectory();

        $fileA = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $fileB = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';

        file_put_contents($fileA, 'content-A');
        file_put_contents($fileB, 'content-B-different');

        $targetFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        // DIR_CONTEXT: src=$sourceDirectory ext=None
        $service->createDuplicateFilenames(
            $collection,
            $sourceDirectory,
            skipHashSubGrouping: true,
        );

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(2, $renames);

        // Old behavior: first file = canonical, second = -duplicate-001
        self::assertSame(
            $sourceDirectory . DIRECTORY_SEPARATOR . 'target.jpg',
            $renames[0]->getTarget()->getPathname(),
        );
        self::assertSame(
            $sourceDirectory . DIRECTORY_SEPARATOR . 'target-duplicate-001.jpg',
            $renames[1]->getTarget()->getPathname(),
        );
    }

    /**
     * Verifies that groups containing a single file skip hash computation entirely,
     * as there is nothing to compare against.
     *
     * This is a performance optimisation: computing xxHash128 for every unique file
     * would add unnecessary I/O. The mock hash calculator is configured to fail if
     * called, ensuring the optimisation is active.
     */
    #[Test]
    public function createDuplicateFilenamesSingleFileGroupSkipsHashing(): void
    {
        $hashCalculator = $this->createMock(SafeHashCalculator::class);
        $hashCalculator
            ->expects(self::never())
            ->method('hashFile');

        $output = new BufferedOutput();
        $io     = new SymfonyStyle(new ArrayInput([]), $output);

        $mediaTypeClassifier    = new MediaTypeClassifier();
        $hashSubGroupingService = new HashSubGroupingService($hashCalculator, $io, $mediaTypeClassifier);
        $service                = new DuplicateDetectionService($io, $hashSubGroupingService, $mediaTypeClassifier);

        $sourceDirectory = $this->createTempDirectory();

        $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'only.jpg';
        $targetFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        file_put_contents($sourceFile, 'single');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($sourceFile))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
        $service->createDuplicateFilenames(
            $collection,
            $sourceDirectory,
        );

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(1, $renames);
        self::assertSame(
            $sourceDirectory . DIRECTORY_SEPARATOR . 'target.jpg',
            $renames[0]->getTarget()->getPathname(),
        );
    }

    /**
     * Verifies the full interplay of hash sub-grouping and duplicate detection across
     * five files with three distinct hashes: A and A' share hash X, B and B' share
     * hash Y, C has unique hash Z.
     *
     * Expected assignment:
     *   A  -> basename.jpg                     (canonical sub-group, canonical file)
     *   A' -> basename-duplicate-001.jpg       (canonical sub-group, true duplicate)
     *   B  -> basename-002.jpg                 (sub-group 002, canonical)
     *   B' -> basename-002-duplicate-001.jpg   (sub-group 002, true duplicate)
     *   C  -> basename-003.jpg                 (sub-group 003, unique)
     *
     * This is the most complex real-world scenario and validates the entire
     * sub-grouping + duplicate naming pipeline.
     */
    #[Test]
    public function createDuplicateFilenamesHandlesMixedDistinctAndDuplicateFiles(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

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

        $targetFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'basename.jpg';

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

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
        $service->createDuplicateFilenames(
            $collection,
            $sourceDirectory,
        );

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames       = iterator_to_array($duplicate->getRenames());
        $renameTargets = [];

        foreach ($renames as $rename) {
            $renameTargets[$rename->getSource()->getPathname()] = $rename->getTarget()->getFilename();
        }

        self::assertCount(5, $renames);

        // A → basename.jpg (canonical sub-group, no number)
        self::assertSame('basename.jpg', $renameTargets[$fileA]);
        // A' → basename-duplicate-001.jpg (duplicate within canonical sub-group)
        self::assertSame('basename-duplicate-001.jpg', $renameTargets[$fileA2]);
        // B → basename-002.jpg
        self::assertSame('basename-002.jpg', $renameTargets[$fileB]);
        // B' → basename-002-duplicate-001.jpg
        self::assertSame('basename-002-duplicate-001.jpg', $renameTargets[$fileB2]);
        // C → basename-003.jpg
        self::assertSame('basename-003.jpg', $renameTargets[$fileC]);
    }

    /**
     * Verifies that when all files in a group already have correct names from a
     * previous run (canonical has base name, duplicates have -duplicate-NNN),
     * re-running the command does not shuffle them: every file keeps its existing
     * target path.
     *
     * The suffixed files are added to the group BEFORE the canonical to trigger
     * the edge case where the first file in iteration already carries a suffix.
     * The canonical selection logic must still promote the unsuffixed file.
     * This is a regression test for the bug where all files kept -duplicate-NNN
     * and no file got the clean base name.
     */
    #[Test]
    public function createDuplicateFilenamesCanonicalKeepsBaseNameWhenDuplicatesPreAssigned(): void
    {
        [$service] = $this->createService();

        $directory = $this->createTempDirectory();

        // Simulate a second run: files already have correct names from a previous run.
        // The canonical file has the base name, two duplicates have suffixes.
        $canonicalFile = $directory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.jpg';
        $duplicate1    = $directory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28-duplicate-001.jpg';
        $duplicate2    = $directory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28-duplicate-002.jpg';

        file_put_contents($canonicalFile, 'same-content');
        file_put_contents($duplicate1, 'same-content');
        file_put_contents($duplicate2, 'same-content');

        $targetFile = $directory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.jpg';

        // Add suffixed files FIRST to trigger the bug: the first file in the group
        // would normally become the canonical, but it already has a duplicate suffix.
        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($duplicate1))
            ->addFile(new SplFileInfo($duplicate2))
            ->addFile(new SplFileInfo($canonicalFile))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        // DIR_CONTEXT: src=$directory tgt=$directory ext=None
        $service->createDuplicateFilenames(
            $collection,
            $directory,
        );

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames         = iterator_to_array($duplicate->getRenames());
        $renamesBySource = [];

        foreach ($renames as $rename) {
            $renamesBySource[$rename->getSource()->getPathname()] = $rename->getTarget()->getPathname();
        }

        // Canonical file must keep its unsuffixed base name.
        self::assertSame($canonicalFile, $renamesBySource[$canonicalFile]);

        // Duplicates must keep their existing suffixed names.
        self::assertSame($duplicate1, $renamesBySource[$duplicate1]);
        self::assertSame($duplicate2, $renamesBySource[$duplicate2]);
    }

    /**
     * Verifies that two files with identical content in the same group produce
     * exactly one canonical (unsuffixed) and one -duplicate-001 target.
     *
     * This is the simplest true-duplicate scenario with no hash sub-grouping
     * involved (only one hash group exists). It validates the basic duplicate
     * counter increment.
     */
    #[Test]
    public function createDuplicateFilenamesAssignsDuplicateSuffixForIdenticalContent(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $fileA = $sourceDirectory . DIRECTORY_SEPARATOR . 'copy_a.jpg';
        $fileB = $sourceDirectory . DIRECTORY_SEPARATOR . 'copy_b.jpg';

        file_put_contents($fileA, 'identical-content');
        file_put_contents($fileB, 'identical-content');

        $targetFile = $sourceDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($targetFile));

        $collection = new FileDuplicateCollection();
        $collection->set('identifier', $fileDuplicate);

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
        $service->createDuplicateFilenames(
            $collection,
            $sourceDirectory,
        );

        $duplicate = $collection->get('identifier');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $renames = iterator_to_array($duplicate->getRenames());

        self::assertCount(2, $renames);

        // First: canonical — no duplicate suffix
        self::assertSame(
            $sourceDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.jpg',
            $renames[0]->getTarget()->getPathname(),
        );

        // Second: genuine duplicate
        self::assertSame(
            $sourceDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28-duplicate-001.jpg',
            $renames[1]->getTarget()->getPathname(),
        );
    }

    /**
     * Verifies that createDuplicateTargetFileInfo() returns the target unchanged
     * and does not increment the duplicate counter when source and target paths
     * are identical (idempotent rename).
     *
     * This prevents a file that is already correctly named from receiving an
     * unnecessary -duplicate-NNN suffix on re-run.
     */
    #[Test]
    public function createDuplicateTargetFileInfoReturnsTargetWhenSourceEqualsTarget(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $this->setServiceDirectories($service, $sourceDirectory);

        $path   = $sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg';
        $source = new SplFileInfo($path);
        $target = new SplFileInfo($path);

        $duplicateCount = 1;
        $method         = new ReflectionMethod($service, 'createDuplicateTargetFileInfo');

        $args = [
            $source,
            $target,
            &$duplicateCount,
            false,
            false,
            false,
            [],
        ];

        /** @var SplFileInfo $result */
        $result = $method->invokeArgs($service, $args);

        self::assertSame($target->getPathname(), $result->getPathname());
        /** @var int $actualCount */
        $actualCount = $duplicateCount;
        self::assertSame(1, $actualCount, 'Counter must not change for idempotent rename');
    }

    /**
     * Verifies that resolveCanonicalTarget() returns the target unchanged and does
     * not increment the counter when source and target are the same path.
     *
     * This is the canonical idempotency check at the method level, ensuring the
     * file already at its correct name does not get displaced.
     */
    #[Test]
    public function resolveCanonicalTargetReturnsTargetWhenSourceEqualsTarget(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $this->setServiceDirectories($service, $sourceDirectory);

        $path   = $sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg';
        $source = new SplFileInfo($path);
        $target = new SplFileInfo($path);

        $duplicateCount = 1;
        $method         = new ReflectionMethod($service, 'resolveCanonicalTarget');

        $args = [
            $source,
            $target,
            &$duplicateCount,
            [],
        ];

        /** @var SplFileInfo $result */
        $result = $method->invokeArgs($service, $args);

        self::assertSame($target->getPathname(), $result->getPathname());

        /** @var int $actualCount */
        $actualCount = $duplicateCount;
        self::assertSame(1, $actualCount, 'Counter must not change for idempotent rename');
    }

    /**
     * Verifies that resolveCanonicalTarget() returns the target path as-is when
     * the path is not occupied in the disk index.
     *
     * This is the happy path for a new rename: the desired target is available,
     * so the canonical gets it without any suffix.
     */
    #[Test]
    public function resolveCanonicalTargetReturnsTargetWhenNotOccupied(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $this->setServiceDirectories($service, $sourceDirectory);

        $source = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_1234.jpg');
        $target = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . '2024-01-01.jpg');

        $duplicateCount = 1;
        $method         = new ReflectionMethod($service, 'resolveCanonicalTarget');

        /** @var SplFileInfo $result */
        $result = $method->invokeArgs($service, [
            $source,
            $target,
            &$duplicateCount,
            [],
        ]);

        self::assertSame($target->getPathname(), $result->getPathname());
    }

    /**
     * Verifies that resolveCanonicalTarget() assigns a -duplicate-NNN suffix when
     * the desired target path is already occupied in the disk index by another file.
     *
     * This handles the naming collision scenario where two different groups
     * resolve to the same target basename but are processed sequentially.
     */
    #[Test]
    public function resolveCanonicalTargetGetsSuffixWhenOccupied(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $this->setServiceDirectories($service, $sourceDirectory);

        $source = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_1234.jpg');
        $target = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . '2024-01-01.jpg');

        // Occupy the target path in the disk index
        $diskIndexProp = new ReflectionProperty($service, 'diskIndex');
        $diskIndexProp->setValue($service, [
            $target->getPathname() => true,
        ]);

        $duplicateCount = 1;
        $method         = new ReflectionMethod($service, 'resolveCanonicalTarget');

        /** @var SplFileInfo $result */
        $result = $method->invokeArgs($service, [
            $source,
            $target,
            &$duplicateCount,
            [],
        ]);

        self::assertNotSame($target->getPathname(), $result->getPathname());
        self::assertStringContainsString(Constants::DUPLICATE_IDENTIFIER, $result->getPathname());
    }

    /**
     * Verifies that a non-canonical file (isFirst=true but target occupied) receives
     * a -duplicate-NNN suffix via the fallback path.
     *
     * This covers the edge case where the first file in a group has its target
     * already taken by a file from a different group in the disk index.
     */
    #[Test]
    public function createDuplicateTargetFileInfoNonCanonicalGetsSuffixWhenOccupied(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $this->setServiceDirectories($service, $sourceDirectory);

        $source = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . 'copy.jpg');
        $target = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . '2024-01-01.jpg');

        // Occupy the target path in the disk index
        $diskIndexProp = new ReflectionProperty($service, 'diskIndex');
        $diskIndexProp->setValue($service, [
            $target->getPathname() => true,
        ]);

        $duplicateCount = 1;
        $method         = new ReflectionMethod($service, 'createDuplicateTargetFileInfo');

        /** @var SplFileInfo $result */
        $result = $method->invokeArgs($service, [
            $source,
            $target,
            &$duplicateCount,
            true,
            false,
            false,
            [],
        ]);

        self::assertNotSame($target->getPathname(), $result->getPathname());
        self::assertStringContainsString(Constants::DUPLICATE_IDENTIFIER, $result->getPathname());
    }

    /**
     * Verifies that a non-first file in a group with additional renames always
     * receives a -duplicate-NNN suffix, even when the target path is free.
     *
     * Non-first files are by definition duplicates and must always carry a suffix
     * to distinguish them from the canonical.
     */
    #[Test]
    public function createDuplicateTargetFileInfoNonFirstGetsSuffix(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $this->setServiceDirectories($service, $sourceDirectory);

        $source = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . 'copy.jpg');
        $target = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . '2024-01-01.jpg');

        $duplicateCount = 1;
        $method         = new ReflectionMethod($service, 'createDuplicateTargetFileInfo');

        /** @var SplFileInfo $result */
        $result = $method->invokeArgs($service, [
            $source,
            $target,
            &$duplicateCount,
            false, // NOT first
            true,  // hasAdditionalRenames
            false,
            [],
        ]);

        self::assertNotSame($target->getPathname(), $result->getPathname());
        self::assertStringContainsString(Constants::DUPLICATE_IDENTIFIER, $result->getPathname());
    }

    /**
     * Verifies that the first file in a group with additional renames still gets
     * a -duplicate-NNN suffix when the desired target is occupied in the disk index.
     *
     * Even though it is first, the occupied path forces a suffix to avoid
     * overwriting the existing file.
     */
    #[Test]
    public function createDuplicateTargetFileInfoFirstWithAdditionalRenamesCallsUniqueResolver(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $this->setServiceDirectories($service, $sourceDirectory);

        $source = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg');
        $target = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . '2024-01-01.jpg');

        // Occupy the target so the unique resolver must find a new name
        $diskIndexProp = new ReflectionProperty($service, 'diskIndex');
        $diskIndexProp->setValue($service, [
            $target->getPathname() => true,
        ]);

        $duplicateCount = 1;
        $method         = new ReflectionMethod($service, 'createDuplicateTargetFileInfo');

        /** @var SplFileInfo $result */
        $result = $method->invokeArgs($service, [
            $source,
            $target,
            &$duplicateCount,
            true,  // isFirst
            true,  // hasAdditionalRenames
            false,
            [],
        ]);

        // Target is occupied, so it falls through to the occupied branch
        // and gets a suffix regardless of isFirst/hasAdditionalRenames
        self::assertNotSame($target->getPathname(), $result->getPathname());
        self::assertStringContainsString(Constants::DUPLICATE_IDENTIFIER, $result->getPathname());
    }

    /**
     * Verifies that a first file with both additional renames and the
     * requiresCanonicalDisambiguation flag receives a -duplicate-NNN suffix
     * even when the target path is free.
     *
     * Canonical disambiguation is needed when another extension already occupies
     * the base name (e.g. a .heic file already has "photo.heic" and now a .jpg
     * tries to claim "photo.jpg" in the same group).
     */
    #[Test]
    public function createDuplicateTargetFileInfoFirstWithAdditionalRenamesAndDisambiguation(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $this->setServiceDirectories($service, $sourceDirectory);

        $source = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg');
        $target = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . '2024-01-01.jpg');

        $duplicateCount = 1;
        $method         = new ReflectionMethod($service, 'createDuplicateTargetFileInfo');

        /** @var SplFileInfo $result */
        $result = $method->invokeArgs($service, [
            $source,
            $target,
            &$duplicateCount,
            true,  // isFirst
            true,  // hasAdditionalRenames
            true,  // requiresCanonicalDisambiguation (forces suffix)
            [],
        ]);

        // requiresCanonicalDisambiguation forces a duplicate suffix
        self::assertNotSame($target->getPathname(), $result->getPathname());
        self::assertStringContainsString(Constants::DUPLICATE_IDENTIFIER, $result->getPathname());
    }

    /**
     * Verifies that the requiresCanonicalDisambiguation flag alone (without
     * hasAdditionalRenames) is sufficient to force a -duplicate-NNN suffix
     * on the first file in a group.
     *
     * This covers the scenario where the file is the only one in its sub-group
     * but another extension already claimed the base name.
     */
    #[Test]
    public function createDuplicateTargetFileInfoFirstWithCanonicalDisambiguationGetsSuffix(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $this->setServiceDirectories($service, $sourceDirectory);

        $source = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg');
        $target = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . '2024-01-01.jpg');

        $duplicateCount = 1;
        $method         = new ReflectionMethod($service, 'createDuplicateTargetFileInfo');

        /** @var SplFileInfo $result */
        $result = $method->invokeArgs($service, [
            $source,
            $target,
            &$duplicateCount,
            true,  // isFirst
            false,
            true,  // requiresCanonicalDisambiguation
            [],
        ]);

        self::assertNotSame($target->getPathname(), $result->getPathname());
        self::assertStringContainsString(Constants::DUPLICATE_IDENTIFIER, $result->getPathname());
    }

    /**
     * Verifies that the first (and only) file in a group without additional renames
     * or disambiguation requirements receives the target path unchanged.
     *
     * This is the simplest happy path: one file, one target, no conflicts.
     * The duplicate counter must not change.
     */
    #[Test]
    public function createDuplicateTargetFileInfoFirstWithoutAdditionalRenamesReturnsTarget(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $this->setServiceDirectories($service, $sourceDirectory);

        $source = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg');
        $target = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . '2024-01-01.jpg');

        $duplicateCount = 1;
        $method         = new ReflectionMethod($service, 'createDuplicateTargetFileInfo');

        $args = [
            $source,
            $target,
            &$duplicateCount,
            true,  // isFirst
            false, // NO additional renames
            false, // NO canonical disambiguation
            [],
        ];

        /** @var SplFileInfo $result */
        $result = $method->invokeArgs($service, $args);

        self::assertSame($target->getPathname(), $result->getPathname());

        /** @var int $actualCount */
        $actualCount = $duplicateCount;
        self::assertSame(1, $actualCount, 'Counter must not change for single file');
    }

    /**
     * Verifies that getNewUniqueDuplicateTargetFileInfo() throws a RuntimeException
     * when all 999 possible -duplicate-NNN suffixes are already occupied in the
     * disk index.
     *
     * This is a safety net against infinite loops. In practice 999 true duplicates
     * of the same file are extremely unlikely, but the guard must exist.
     */
    #[Test]
    public function getNewUniqueDuplicateTargetFileInfoThrowsWhenMaxSuffixExceeded(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $this->setServiceDirectories($service, $sourceDirectory);

        $source   = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg');
        $target   = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg');
        $basename = 'photo';

        // Populate the diskIndex with entries that block every suffix 001..999
        $diskIndexProp = new ReflectionProperty($service, 'diskIndex');

        /** @var array<string, true> $diskIndex */
        $diskIndex = [];

        for ($i = 1; $i <= 999; ++$i) {
            $diskIndex[sprintf(
                '%s%sphoto%s%03d.jpg',
                $sourceDirectory,
                DIRECTORY_SEPARATOR,
                Constants::DUPLICATE_IDENTIFIER,
                $i,
            )] = true;
        }

        $diskIndexProp->setValue($service, $diskIndex);

        $duplicateCount = 1;

        $method = new ReflectionMethod($service, 'getNewUniqueDuplicateTargetFileInfo');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Exceeded 999 duplicate suffix attempts');

        $method->invoke($service, $source, $target, $basename, $duplicateCount, true, []);
    }

    /**
     * Verifies that getTargetPathname() throws a RuntimeException when the target
     * filename contains a forward slash, preventing directory traversal attacks
     * (e.g. "../evil.jpg") that could write files outside the target directory.
     */
    #[Test]
    public function getTargetPathnameThrowsOnForwardSlashInFilename(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $this->setServiceDirectories($service, $sourceDirectory);

        $sourceFile = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not contain directory separators');

        $method = new ReflectionMethod($service, 'getTargetPathname');
        $method->invoke($service, $sourceFile, '../evil.jpg');
    }

    /**
     * Verifies that getTargetPathname() throws a RuntimeException when the target
     * filename contains a subdirectory path separator (e.g. "sub/file.jpg"),
     * preventing unintended creation of nested directories via crafted filenames.
     */
    #[Test]
    public function getTargetPathnameThrowsOnSubdirectoryInFilename(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $this->setServiceDirectories($service, $sourceDirectory);

        $sourceFile = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not contain directory separators');

        $method = new ReflectionMethod($service, 'getTargetPathname');
        $method->invoke($service, $sourceFile, 'sub/file.jpg');
    }

    /**
     * Verifies that getTargetPathname() throws a RuntimeException when the target
     * filename contains a platform directory separator (DIRECTORY_SEPARATOR),
     * covering Windows-style backslash separators on non-Unix systems.
     */
    #[Test]
    public function getTargetPathnameThrowsOnDirectorySeparatorInFilename(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $this->setServiceDirectories($service, $sourceDirectory);

        $sourceFile = new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . 'photo.jpg');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not contain directory separators');

        $method = new ReflectionMethod($service, 'getTargetPathname');
        $method->invoke($service, $sourceFile, 'sub' . DIRECTORY_SEPARATOR . 'file.jpg');
    }

    /**
     * Verifies that a video companion (MOV) with its own EXIF date defers to the
     * still image's group when both share the same Live Photo content identifier.
     *
     * Without the fix, the MOV creates its own group by EXIF date, and the Live
     * Photo pairing second pass skips it because it's already in the collection.
     * After the fix, the MOV defers during Phase 1 and joins the still image's
     * group, ensuring it receives the paired still image's timestamp.
     */
    #[Test]
    public function groupFilesByDuplicateIdentifierDefersVideoCompanionToStillImageGroup(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $jpgPath = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.jpg';
        $movPath = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.mov';

        file_put_contents($jpgPath, 'photo-content');
        file_put_contents($movPath, 'video-content');

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
        // JPG first, then MOV — both have EXIF dates and the same content ID.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([
                new SplFileInfo($jpgPath),
                new SplFileInfo($movPath),
            ], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $renameStrategy = new DummyLivePhotoRenameStrategy([
            $jpgPath => '2025-01-01_12-00-00-000.jpg',
            $movPath => '2025-01-01_11-00-00-000.mov',
        ], [
            $jpgPath => 'content-id-123',
            $movPath => 'content-id-123',
        ]);

        // TargetBasenameStrategy groups by target basename (without extension).
        // JPG => "2025-01-01_12-00-00-000", MOV => "2025-01-01_11-00-00-000".
        // Without the fix, these produce two separate groups.
        $duplicateIdentifierStrategy = new TargetBasenameStrategy();

        $collection = $service->groupFilesByDuplicateIdentifier(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
            $sourceDirectory,
        );

        // After the fix: 1 group (JPG's), MOV is a member of it.
        self::assertCount(1, $collection);
        self::assertTrue($collection->has('2025-01-01_12-00-00-000'));

        $duplicate = $collection->get('2025-01-01_12-00-00-000');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $files = iterator_to_array($duplicate->getFiles());
        self::assertCount(2, $files);

        $paths = [
            $files[0]->getPathname(),
            $files[1]->getPathname(),
        ];

        self::assertContains($jpgPath, $paths);
        self::assertContains($movPath, $paths);
    }

    /**
     * Verifies that a standalone video with a content identifier but no paired
     * still image still gets its own EXIF date group via the post-loop fallback.
     *
     * When the video defers to Live Photo pairing but no still image is ever
     * found for its content ID, the video must not be lost. The post-loop
     * fallback creates a group using the video's own EXIF date target.
     */
    #[Test]
    public function groupFilesByDuplicateIdentifierFallsBackForStandaloneVideoWithContentId(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $movPath = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0099.mov';

        file_put_contents($movPath, 'orphan-video-content');

        // DIR_CONTEXT: src=$sourceDirectory tgt=$sourceDirectory ext=None
        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([
                new SplFileInfo($movPath),
            ], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $renameStrategy = new DummyLivePhotoRenameStrategy([
            $movPath => '2025-01-01_11-00-00-000.mov',
        ], [
            $movPath => 'orphan-id',
        ]);

        $duplicateIdentifierStrategy = new TargetBasenameStrategy();

        $collection = $service->groupFilesByDuplicateIdentifier(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
            $sourceDirectory,
        );

        // The standalone video must still have its own group (not be lost).
        self::assertCount(1, $collection);
        self::assertTrue($collection->has('2025-01-01_11-00-00-000'));

        $duplicate = $collection->get('2025-01-01_11-00-00-000');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $files = iterator_to_array($duplicate->getFiles());
        self::assertCount(1, $files);
        self::assertSame($movPath, $files[0]->getPathname());
    }

    /**
     * Verifies that two Live Photo pairs sharing the same EXIF timestamp but with
     * different content hashes produce correct sub-group assignments for BOTH the
     * still images AND their video companions.
     *
     * The MOV companions have their own EXIF dates (same as their paired stills)
     * and content IDs. Both MOVs are deferred during Phase 1 grouping and added
     * to the still image's group via the content identifier cache.
     *
     * Expected assignment:
     *   pair1.jpg -> 2025-01-01_12-00-00-000.jpg       (canonical, sub-group 0)
     *   pair1.mov -> 2025-01-01_12-00-00-000.mov       (companion, sub-group 0)
     *   pair2.jpg -> 2025-01-01_12-00-00-000-002.jpg   (sub-group 2)
     *   pair2.mov -> 2025-01-01_12-00-00-000-002.mov   (companion, sub-group 2)
     */
    #[Test]
    public function createDuplicateFilenamesVideoCompanionsInheritPairedStillSubGroupNumber(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        // Two Live Photo pairs with different content but same EXIF timestamp.
        $pair1Jpg = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.jpg';
        $pair1Mov = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.mov';
        $pair2Jpg = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.jpg';
        $pair2Mov = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.mov';

        // Different content per pair so hashes differ between pairs.
        file_put_contents($pair1Jpg, 'pair1-photo-content');
        file_put_contents($pair1Mov, 'pair1-video-content');
        file_put_contents($pair2Jpg, 'pair2-photo-content-different');
        file_put_contents($pair2Mov, 'pair2-video-content-different');

        // All four files produce the same target basename from EXIF date.
        // Each pair shares a content ID: pair1 = "content-id-A", pair2 = "content-id-B".
        $renameStrategy = new DummyLivePhotoRenameStrategy([
            $pair1Jpg => '2025-01-01_12-00-00-000.jpg',
            $pair1Mov => '2025-01-01_12-00-00-000.mov',
            $pair2Jpg => '2025-01-01_12-00-00-000.jpg',
            $pair2Mov => '2025-01-01_12-00-00-000.mov',
        ], [
            $pair1Jpg => 'content-id-A',
            $pair1Mov => 'content-id-A',
            $pair2Jpg => 'content-id-B',
            $pair2Mov => 'content-id-B',
        ]);

        $duplicateIdentifierStrategy = new TargetBasenameStrategy();

        // Phase 1: group files by duplicate identifier.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([
                new SplFileInfo($pair1Jpg),
                new SplFileInfo($pair1Mov),
                new SplFileInfo($pair2Jpg),
                new SplFileInfo($pair2Mov),
            ], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $collection = $service->groupFilesByDuplicateIdentifier(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
            $sourceDirectory,
        );

        // All four files must land in one group (same target basename).
        self::assertCount(1, $collection);

        $duplicate = $collection->get('2025-01-01_12-00-00-000');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $files = iterator_to_array($duplicate->getFiles());
        self::assertCount(4, $files);

        // Phase 2: assign duplicate filenames with hash sub-grouping.
        $service->createDuplicateFilenames(
            $collection,
            $sourceDirectory,
            useFileExtensionFromSource: true,
        );

        $renames       = iterator_to_array($duplicate->getRenames());
        $renameTargets = [];

        foreach ($renames as $rename) {
            $renameTargets[$rename->getSource()->getPathname()] = $rename->getTarget()->getFilename();
        }

        self::assertCount(4, $renames);

        // pair1: canonical sub-group (no number).
        self::assertSame('2025-01-01_12-00-00-000.jpg', $renameTargets[$pair1Jpg]);
        self::assertSame('2025-01-01_12-00-00-000.mov', $renameTargets[$pair1Mov]);

        // pair2: sub-group 002 — both still AND video companion.
        self::assertSame('2025-01-01_12-00-00-000-002.jpg', $renameTargets[$pair2Jpg]);
        self::assertSame('2025-01-01_12-00-00-000-002.mov', $renameTargets[$pair2Mov]);
    }

    /**
     * Verifies that two Live Photo pairs sharing the same EXIF timestamp but with
     * different content hashes produce correct sub-group assignments when the MOV
     * companions have NO EXIF date (generateFilename returns null) and are only
     * attached to the group via the pending-file mechanism.
     *
     * Without the fix, the video companion of the non-canonical pair loses its
     * sub-group number: pair2.mov gets sub-group 0 instead of sub-group 2,
     * colliding with pair1.mov and receiving a -duplicate-001 suffix.
     *
     * Expected assignment:
     *   pair1.jpg -> 2025-01-01_12-00-00-000.jpg       (canonical, sub-group 0)
     *   pair1.mov -> 2025-01-01_12-00-00-000.mov       (companion, sub-group 0)
     *   pair2.jpg -> 2025-01-01_12-00-00-000-002.jpg   (sub-group 2)
     *   pair2.mov -> 2025-01-01_12-00-00-000-002.mov   (companion, sub-group 2)
     */
    #[Test]
    public function createDuplicateFilenamesVideoCompanionsWithoutExifInheritSubGroupNumber(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        // Two Live Photo pairs with different content but same EXIF timestamp.
        $pair1Jpg = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.jpg';
        $pair1Mov = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.mov';
        $pair2Jpg = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.jpg';
        $pair2Mov = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.mov';

        // Different content per pair so hashes differ between pairs.
        file_put_contents($pair1Jpg, 'pair1-photo-content');
        file_put_contents($pair1Mov, 'pair1-video-content');
        file_put_contents($pair2Jpg, 'pair2-photo-content-different');
        file_put_contents($pair2Mov, 'pair2-video-content-different');

        // MOV files return null for generateFilename (no EXIF date).
        // They are only attached to the group via content ID pending-file mechanism.
        $renameStrategy = new DummyLivePhotoRenameStrategy([
            $pair1Jpg => '2025-01-01_12-00-00-000.jpg',
            $pair1Mov => null,
            $pair2Jpg => '2025-01-01_12-00-00-000.jpg',
            $pair2Mov => null,
        ], [
            $pair1Jpg => 'content-id-A',
            $pair1Mov => 'content-id-A',
            $pair2Jpg => 'content-id-B',
            $pair2Mov => 'content-id-B',
        ]);

        $duplicateIdentifierStrategy = new TargetBasenameStrategy();

        // Phase 1: group files by duplicate identifier.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([
                new SplFileInfo($pair1Jpg),
                new SplFileInfo($pair1Mov),
                new SplFileInfo($pair2Jpg),
                new SplFileInfo($pair2Mov),
            ], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $collection = $service->groupFilesByDuplicateIdentifier(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
            $sourceDirectory,
        );

        // All four files must land in one group (same target basename).
        self::assertCount(1, $collection);

        $duplicate = $collection->get('2025-01-01_12-00-00-000');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $files = iterator_to_array($duplicate->getFiles());
        self::assertCount(4, $files);

        // Phase 2: assign duplicate filenames with hash sub-grouping.
        $service->createDuplicateFilenames(
            $collection,
            $sourceDirectory,
            useFileExtensionFromSource: true,
        );

        $renames       = iterator_to_array($duplicate->getRenames());
        $renameTargets = [];

        foreach ($renames as $rename) {
            $renameTargets[$rename->getSource()->getPathname()] = $rename->getTarget()->getFilename();
        }

        self::assertCount(4, $renames);

        // pair1: canonical sub-group (no number).
        self::assertSame('2025-01-01_12-00-00-000.jpg', $renameTargets[$pair1Jpg]);
        self::assertSame('2025-01-01_12-00-00-000.mov', $renameTargets[$pair1Mov]);

        // pair2: sub-group 002 — both still AND video companion.
        self::assertSame('2025-01-01_12-00-00-000-002.jpg', $renameTargets[$pair2Jpg]);
        self::assertSame('2025-01-01_12-00-00-000-002.mov', $renameTargets[$pair2Mov]);
    }

    /**
     * Verifies that two Live Photo pairs sharing the same EXIF timestamp but with
     * different content hashes produce correct sub-group assignments when only the
     * still images have content identifiers (MOVs have no content ID in metadata).
     *
     * When MOVs lack content identifiers, the content-ID-to-sub-group mapping in
     * HashSubGroupingService cannot pair them with their stills. The MOVs default
     * to sub-group 0 instead of inheriting their paired still's sub-group number.
     *
     * Expected assignment:
     *   pair1.jpg -> 2025-01-01_12-00-00-000.jpg       (canonical, sub-group 0)
     *   pair1.mov -> 2025-01-01_12-00-00-000.mov       (companion, sub-group 0)
     *   pair2.jpg -> 2025-01-01_12-00-00-000-002.jpg   (sub-group 2)
     *   pair2.mov -> 2025-01-01_12-00-00-000-002.mov   (companion, sub-group 2)
     */
    #[Test]
    public function createDuplicateFilenamesVideoCompanionsWithoutContentIdInheritSubGroup(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        // Two Live Photo pairs with different content but same EXIF timestamp.
        $pair1Jpg = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.jpg';
        $pair1Mov = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.mov';
        $pair2Jpg = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.jpg';
        $pair2Mov = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.mov';

        // Different content per pair so hashes differ between pairs.
        file_put_contents($pair1Jpg, 'pair1-photo-content');
        file_put_contents($pair1Mov, 'pair1-video-content');
        file_put_contents($pair2Jpg, 'pair2-photo-content-different');
        file_put_contents($pair2Mov, 'pair2-video-content-different');

        // Only stills have content identifiers. MOVs return null (no content ID
        // in metadata). Without content IDs, MOVs are NOT deferred and create
        // their own EXIF date group entry. But with the same target basename,
        // all files end up in the same group.
        $renameStrategy = new DummyLivePhotoRenameStrategy([
            $pair1Jpg => '2025-01-01_12-00-00-000.jpg',
            $pair1Mov => '2025-01-01_12-00-00-000.mov',
            $pair2Jpg => '2025-01-01_12-00-00-000.jpg',
            $pair2Mov => '2025-01-01_12-00-00-000.mov',
        ], [
            $pair1Jpg => 'content-id-A',
            $pair1Mov => null,
            $pair2Jpg => 'content-id-B',
            $pair2Mov => null,
        ]);

        $duplicateIdentifierStrategy = new TargetBasenameStrategy();

        // Phase 1: group files by duplicate identifier.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([
                new SplFileInfo($pair1Jpg),
                new SplFileInfo($pair1Mov),
                new SplFileInfo($pair2Jpg),
                new SplFileInfo($pair2Mov),
            ], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $collection = $service->groupFilesByDuplicateIdentifier(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
            $sourceDirectory,
        );

        // All four files must land in one group (same target basename).
        self::assertCount(1, $collection);

        $duplicate = $collection->get('2025-01-01_12-00-00-000');
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $files = iterator_to_array($duplicate->getFiles());
        self::assertCount(4, $files);

        // Phase 2: assign duplicate filenames with hash sub-grouping.
        $service->createDuplicateFilenames(
            $collection,
            $sourceDirectory,
            useFileExtensionFromSource: true,
        );

        $renames       = iterator_to_array($duplicate->getRenames());
        $renameTargets = [];

        foreach ($renames as $rename) {
            $renameTargets[$rename->getSource()->getPathname()] = $rename->getTarget()->getFilename();
        }

        self::assertCount(4, $renames);

        // pair1: canonical sub-group (no number).
        self::assertSame('2025-01-01_12-00-00-000.jpg', $renameTargets[$pair1Jpg]);
        self::assertSame('2025-01-01_12-00-00-000.mov', $renameTargets[$pair1Mov]);

        // pair2: sub-group 002 — both still AND video companion.
        self::assertSame('2025-01-01_12-00-00-000-002.jpg', $renameTargets[$pair2Jpg]);
        self::assertSame('2025-01-01_12-00-00-000-002.mov', $renameTargets[$pair2Mov]);
    }

    /**
     * Verifies the idempotent re-run scenario where pair1 already has its target
     * names on disk (source == target for both .jpg and .mov) while pair2 still
     * needs renaming. This is the exact scenario from the reported bug.
     *
     * Without the fix, pair2.mov gets sub-group 0 instead of inheriting sub-group 2
     * from its paired still image, causing pair1.mov to receive a -duplicate-001
     * suffix and pair2.mov to steal the unsuffixed base name.
     *
     * Expected assignment:
     *   pair1.jpg -> 2025-01-01_12-00-00-000.jpg       (canonical, idempotent)
     *   pair1.mov -> 2025-01-01_12-00-00-000.mov       (companion, idempotent)
     *   pair2.jpg -> 2025-01-01_12-00-00-000-002.jpg   (sub-group 2)
     *   pair2.mov -> 2025-01-01_12-00-00-000-002.mov   (companion, sub-group 2)
     */
    #[Test]
    public function createDuplicateFilenamesIdempotentRerunWithTwoLivePhotoPairs(): void
    {
        [$service] = $this->createService();

        $directory = $this->createTempDirectory();

        // Pair1 already has correct target names on disk (from a previous run).
        $pair1Jpg = $directory . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.jpg';
        $pair1Mov = $directory . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.mov';
        // Pair2 still has original camera names.
        $pair2Jpg = $directory . DIRECTORY_SEPARATOR . 'IMG_0002.jpg';
        $pair2Mov = $directory . DIRECTORY_SEPARATOR . 'IMG_0002.mov';

        // Different content per pair so hashes differ between pairs.
        file_put_contents($pair1Jpg, 'pair1-photo-content');
        file_put_contents($pair1Mov, 'pair1-video-content');
        file_put_contents($pair2Jpg, 'pair2-photo-content-different');
        file_put_contents($pair2Mov, 'pair2-video-content-different');

        // All four files produce the same target basename from EXIF date.
        $renameStrategy = new DummyLivePhotoRenameStrategy([
            $pair1Jpg => '2025-01-01_12-00-00-000.jpg',
            $pair1Mov => '2025-01-01_12-00-00-000.mov',
            $pair2Jpg => '2025-01-01_12-00-00-000.jpg',
            $pair2Mov => '2025-01-01_12-00-00-000.mov',
        ], [
            $pair1Jpg => 'content-id-A',
            $pair1Mov => 'content-id-A',
            $pair2Jpg => 'content-id-B',
            $pair2Mov => 'content-id-B',
        ]);

        $duplicateIdentifierStrategy = new TargetBasenameStrategy();

        // Phase 1: group files by duplicate identifier.
        // source == target directory to trigger idempotent code paths.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([
                new SplFileInfo($pair1Jpg),
                new SplFileInfo($pair1Mov),
                new SplFileInfo($pair2Jpg),
                new SplFileInfo($pair2Mov),
            ], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $collection = $service->groupFilesByDuplicateIdentifier(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
            $directory,
        );

        // All four files must land in one group (same target basename, same directory).
        self::assertCount(1, $collection);

        $groupKey  = '2025-01-01_12-00-00-000';
        $duplicate = $collection->get($groupKey);
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $files = iterator_to_array($duplicate->getFiles());
        self::assertCount(4, $files);

        // Phase 2: assign duplicate filenames with hash sub-grouping.
        $service->createDuplicateFilenames(
            $collection,
            $directory,
            useFileExtensionFromSource: true,
        );

        $renames       = iterator_to_array($duplicate->getRenames());
        $renameTargets = [];

        foreach ($renames as $rename) {
            $renameTargets[$rename->getSource()->getPathname()] = $rename->getTarget()->getFilename();
        }

        self::assertCount(4, $renames);

        // pair1: canonical sub-group — already named correctly.
        self::assertSame('2025-01-01_12-00-00-000.jpg', $renameTargets[$pair1Jpg]);
        self::assertSame('2025-01-01_12-00-00-000.mov', $renameTargets[$pair1Mov]);

        // pair2: sub-group 002 — both still AND video companion.
        self::assertSame('2025-01-01_12-00-00-000-002.jpg', $renameTargets[$pair2Jpg]);
        self::assertSame('2025-01-01_12-00-00-000-002.mov', $renameTargets[$pair2Mov]);
    }

    /**
     * Verifies that two Live Photo pairs sharing the same EXIF timestamp correctly
     * inherit sub-group numbers when the MOV companions have their own EXIF dates
     * but those dates produce a DIFFERENT basename than their paired stills.
     *
     * In real-world scenarios, a MOV's EXIF date may be in a different timezone
     * or slightly different, producing a different target basename. The video
     * deferral mechanism should still group the MOV with its paired still, and
     * hash sub-grouping should assign the correct sub-group number.
     *
     * Expected assignment:
     *   pair1.jpg -> 2025-01-01_12-00-00-000.jpg       (canonical, sub-group 0)
     *   pair1.mov -> 2025-01-01_12-00-00-000.mov       (companion, sub-group 0)
     *   pair2.jpg -> 2025-01-01_12-00-00-000-002.jpg   (sub-group 2)
     *   pair2.mov -> 2025-01-01_12-00-00-000-002.mov   (companion, sub-group 2)
     */
    #[Test]
    public function createDuplicateFilenamesVideoCompanionsWithDifferentExifDateInheritSubGroup(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();

        $pair1Jpg = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.jpg';
        $pair1Mov = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.mov';
        $pair2Jpg = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.jpg';
        $pair2Mov = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.mov';

        file_put_contents($pair1Jpg, 'pair1-photo-content');
        file_put_contents($pair1Mov, 'pair1-video-content');
        file_put_contents($pair2Jpg, 'pair2-photo-content-different');
        file_put_contents($pair2Mov, 'pair2-video-content-different');

        // MOVs have DIFFERENT target basenames from their paired stills (different EXIF dates).
        // The video deferral mechanism must still group them with their paired stills.
        $renameStrategy = new DummyLivePhotoRenameStrategy([
            $pair1Jpg => '2025-01-01_12-00-00-000.jpg',
            $pair1Mov => '2025-01-01_11-00-00-000.mov',
            $pair2Jpg => '2025-01-01_12-00-00-000.jpg',
            $pair2Mov => '2025-01-01_11-00-00-000.mov',
        ], [
            $pair1Jpg => 'content-id-A',
            $pair1Mov => 'content-id-A',
            $pair2Jpg => 'content-id-B',
            $pair2Mov => 'content-id-B',
        ]);

        $duplicateIdentifierStrategy = new TargetBasenameStrategy();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator([
                new SplFileInfo($pair1Jpg),
                new SplFileInfo($pair1Mov),
                new SplFileInfo($pair2Jpg),
                new SplFileInfo($pair2Mov),
            ], RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );

        $collection = $service->groupFilesByDuplicateIdentifier(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
            $sourceDirectory,
        );

        self::assertCount(1, $collection);

        $groupKey  = '2025-01-01_12-00-00-000';
        $duplicate = $collection->get($groupKey);
        self::assertInstanceOf(FileDuplicate::class, $duplicate);

        $files = iterator_to_array($duplicate->getFiles());
        self::assertCount(4, $files);

        $service->createDuplicateFilenames(
            $collection,
            $sourceDirectory,
            useFileExtensionFromSource: true,
        );

        $renames       = iterator_to_array($duplicate->getRenames());
        $renameTargets = [];

        foreach ($renames as $rename) {
            $renameTargets[$rename->getSource()->getPathname()] = $rename->getTarget()->getFilename();
        }

        self::assertCount(4, $renames);

        self::assertSame('2025-01-01_12-00-00-000.jpg', $renameTargets[$pair1Jpg]);
        self::assertSame('2025-01-01_12-00-00-000.mov', $renameTargets[$pair1Mov]);

        self::assertSame('2025-01-01_12-00-00-000-002.jpg', $renameTargets[$pair2Jpg]);
        self::assertSame('2025-01-01_12-00-00-000-002.mov', $renameTargets[$pair2Mov]);
    }

    /**
     * @return array{DuplicateDetectionService, BufferedOutput, FileSystemService}
     */
    private function createService(): array
    {
        $output = new BufferedOutput();
        $io     = new SymfonyStyle(new ArrayInput([]), $output);

        $fileSystemService      = new FileSystemService($io, new RenameOutputRenderer($io));
        $hashCalculator         = new SafeHashCalculator();
        $mediaTypeClassifier    = new MediaTypeClassifier();
        $hashSubGroupingService = new HashSubGroupingService($hashCalculator, $io, $mediaTypeClassifier);
        $service                = new DuplicateDetectionService($io, $hashSubGroupingService, $mediaTypeClassifier);

        return [$service, $output, $fileSystemService];
    }

    /**
     * Sets the internal sourceDirectory and targetDirectory on the service via
     * reflection for tests that call private/protected methods directly.
     */
    private function setServiceDirectories(
        DuplicateDetectionService $service,
        string $sourceDirectory,
    ): void {
        $sourceProperty = new ReflectionProperty($service, 'sourceDirectory');
        $sourceProperty->setValue($service, $sourceDirectory);
    }

    /**
     * Verifies that files in different subdirectories with the same EXIF date
     * are grouped together into a single duplicate group. This is essential for
     * detecting duplicates across a large photo collection with nested folders.
     */
    #[Test]
    public function groupFilesByDuplicateIdentifierGroupsFilesAcrossDirectories(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $subDirectory    = $sourceDirectory . DIRECTORY_SEPARATOR . 'sub';

        if (!is_dir($subDirectory)) {
            mkdir($subDirectory, 0777, true);
        }

        $fileRoot = $sourceDirectory . DIRECTORY_SEPARATOR . 'root-photo.jpg';
        $fileSub  = $subDirectory . DIRECTORY_SEPARATOR . 'sub-photo.jpg';

        file_put_contents($fileRoot, 'content-root');
        file_put_contents($fileSub, 'content-sub');

        $iterator = $this->createIterator($sourceDirectory);

        $renameStrategy = $this->createMock(RenameStrategyInterface::class);
        $renameStrategy
            ->expects(self::exactly(2))
            ->method('generateFilename')
            ->willReturn('2025-01-01_12-00-00-000.jpg');

        $duplicateIdentifierStrategy = $this->createMock(DuplicateIdentifierStrategyInterface::class);
        $duplicateIdentifierStrategy
            ->expects(self::exactly(2))
            ->method('generateIdentifier')
            ->willReturn('2025-01-01_12-00-00-000');

        $collection = $service->groupFilesByDuplicateIdentifier(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
            $sourceDirectory,
        );

        // Both files should land in ONE group despite being in different directories
        self::assertCount(1, $collection);
        self::assertTrue($collection->has('2025-01-01_12-00-00-000'));

        $group = $collection->get('2025-01-01_12-00-00-000');
        self::assertInstanceOf(FileDuplicate::class, $group);
        self::assertCount(2, $group->getFiles());
    }

    /**
     * Verifies that cross-directory duplicates retain their original directory
     * when assigned filenames. The duplicate in sub/ stays in sub/, not moved to root.
     */
    #[Test]
    public function createDuplicateFilenamesCrossDirectoryRetainsOriginalDirectory(): void
    {
        [$service] = $this->createService();

        $sourceDirectory = $this->createTempDirectory();
        $subDirectory    = $sourceDirectory . DIRECTORY_SEPARATOR . 'sub';

        if (!is_dir($subDirectory)) {
            mkdir($subDirectory, 0777, true);
        }

        $fileRoot = $sourceDirectory . DIRECTORY_SEPARATOR . 'root-photo.jpg';
        $fileSub  = $subDirectory . DIRECTORY_SEPARATOR . 'sub-photo.jpg';

        file_put_contents($fileRoot, 'identical-content');
        file_put_contents($fileSub, 'identical-content');

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileRoot))
            ->addFile(new SplFileInfo($fileSub))
            ->setTarget(new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.jpg'));

        $fileDuplicate->addRename(new Rename(
            new SplFileInfo($fileRoot),
            new SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.jpg'),
        ));
        $fileDuplicate->addRename(new Rename(
            new SplFileInfo($fileSub),
            new SplFileInfo($subDirectory . DIRECTORY_SEPARATOR . '2025-01-01_12-00-00-000.jpg'),
        ));

        $collection = new FileDuplicateCollection();
        $collection->set('2025-01-01_12-00-00-000', $fileDuplicate);

        $service->createDuplicateFilenames($collection, $sourceDirectory);

        $group = $collection->get('2025-01-01_12-00-00-000');
        self::assertInstanceOf(FileDuplicate::class, $group);

        $renames = $group->getRenames();

        // Root file = canonical, sub file = duplicate
        $rootRename = null;
        $subRename  = null;

        foreach ($renames as $rename) {
            if (str_contains($rename->getSource()->getPathname(), 'sub')) {
                $subRename = $rename;
            } else {
                $rootRename = $rename;
            }
        }

        self::assertNotNull($rootRename);
        self::assertNotNull($subRename);

        // Root keeps original directory
        self::assertSame(
            $sourceDirectory,
            $rootRename->getTarget()->getPath(),
        );

        // Sub keeps its subdirectory — NOT moved to root
        self::assertSame(
            $subDirectory,
            $subRename->getTarget()->getPath(),
        );

        // One is canonical (no duplicate suffix), the other has -duplicate-
        $rootBasename = $rootRename->getTarget()->getBasename();
        $subBasename  = $subRename->getTarget()->getBasename();

        $hasDuplicate = str_contains($rootBasename, '-duplicate-') || str_contains($subBasename, '-duplicate-');
        self::assertTrue($hasDuplicate, 'One file should have a -duplicate- suffix');
    }

    private function createTempDirectory(): string
    {
        $directory               = $this->createTempWorkspace('photo-renamer-');
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
}

/**
 * Test double implementing LivePhotoAwareRenameStrategyInterface with pre-programmed
 * filename and content identifier responses keyed by source path.
 *
 * Used by DuplicateDetectionServiceTest to simulate the ExifDateFilenameStrategy
 * without requiring real EXIF metadata.
 */
final readonly class DummyLivePhotoRenameStrategy implements LivePhotoAwareRenameStrategyInterface
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

/**
 * Test double implementing DuplicateIdentifierStrategyInterface with pre-programmed
 * identifier responses. Returns "live-photo:<id>" for mapped paths, or the target
 * filename for unmapped paths.
 *
 * Used by DuplicateDetectionServiceTest to simulate TargetBasenameStrategy with
 * Live Photo content identifier prefixing.
 */
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
