<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use Closure;
use Imagick;
use ImagickPixel;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\Collection\FileList;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Service\HashSubGroupingService;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\PerceptualHash\LocalDifferenceAnalyzer;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityResult;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Service\SafeHashCalculatorInterface;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubPerceptualHashCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function file_put_contents;
use function iterator_to_array;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies the HashSubGroupingService, which splits a FileDuplicate group into
 * sub-groups based on content hashes and assigns sequential -NNN suffixes.
 *
 * Hash sub-grouping distinguishes "different photos taken at the same second"
 * from "true duplicates of the same photo". Files with the same hash stay in one
 * sub-group and receive -duplicate-NNN suffixes; files with different hashes get
 * their own sub-group with a sequential number (-002, -003, ...).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(HashSubGroupingService::class)]
#[CoversClass(FileDuplicate::class)]
#[CoversClass(RenameList::class)]
#[CoversClass(Rename::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(FileList::class)]
#[UsesClass(ImagickImageLoader::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(SafeHashCalculator::class)]
#[UsesClass(SimilarityResult::class)]
final class HashSubGroupingServiceTest extends TestCase
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
     * Verifies that apply() returns null for a group containing only one file,
     * because sub-grouping is unnecessary when there is nothing to compare.
     *
     * Returning false tells the caller that the rename list was not modified
     * and no further sub-group processing is needed.
     */
    #[Test]
    public function applyReturnsNullForSingleFile(): void
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

        $result = $service->apply($fileDuplicate, $renameA, null, [], $this->createTargetPathnameResolver($sourceDirectory, $targetDirectory));

        self::assertNull($result);
    }

    /**
     * Verifies that apply() returns null when all files in the group share the
     * same hash (all are true duplicates, only one sub-group exists).
     *
     * No sequential numbering is needed because there is only one distinct content
     * variant. The standard -duplicate-NNN suffixes will be assigned by the caller.
     */
    #[Test]
    public function applyReturnsNullForSingleHashGroup(): void
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

        $result = $service->apply($fileDuplicate, $renameA, null, [], $this->createTargetPathnameResolver($sourceDirectory, $targetDirectory));

        self::assertNull($result);
    }

    /**
     * Verifies that apply() returns cluster map and assigns sub-group numbers when two
     * files have different content hashes: the canonical keeps the unsuffixed
     * base name, the second file gets -002.
     *
     * This is the core sub-grouping contract: distinct content within the same
     * date group produces sequential numbers instead of -duplicate-NNN.
     */
    #[Test]
    public function applyReturnsClusterMapForDistinctHashes(): void
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

        $result = $service->apply($fileDuplicate, $renameA, null, [], $this->createTargetPathnameResolver($sourceDirectory, $targetDirectory));

        self::assertIsArray($result);

        $renames = iterator_to_array($fileDuplicate->getRenames());

        self::assertCount(2, $renames);

        // Canonical sub-group: unsuffixed base name
        self::assertSame('target.jpg', $renames[0]->getTarget()->getFilename());
        // Second sub-group: -002
        self::assertSame('target-002.jpg', $renames[1]->getTarget()->getFilename());
    }

    /**
     * Verifies the combined sub-grouping and duplicate naming for five files across
     * three hash groups, each with a mix of unique and duplicate files.
     *
     * Expected target filenames:
     *   A  -> basename.jpg                     (canonical, hash X)
     *   A' -> basename-duplicate-001.jpg       (duplicate of A, hash X)
     *   B  -> basename-002.jpg                 (sub-group 002, hash Y)
     *   B' -> basename-002-duplicate-001.jpg   (duplicate of B, hash Y)
     *   C  -> basename-003.jpg                 (sub-group 003, hash Z)
     *
     * This is the most comprehensive sub-grouping scenario.
     */
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

        $result = $service->apply($fileDuplicate, $renameA, null, [], $this->createTargetPathnameResolver($sourceDirectory, $targetDirectory));

        self::assertIsArray($result);

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

    /**
     * Verifies that companion media types (MOV, MP4, etc.) are excluded from hash
     * sub-grouping and instead receive their own sequential sub-group number.
     *
     * Without this exclusion, a Live Photo MOV with a different hash than its
     * paired HEIC would be incorrectly placed in a separate content-based sub-group.
     * By excluding companion types from the hash comparison, they can later inherit
     * the sub-group number from their paired still image.
     */
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
            $this->createTargetPathnameResolver($sourceDirectory, $targetDirectory),
        );

        self::assertIsArray($result);

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

    /**
     * Verifies that apply() returns null when companion videos (MOVs) all share
     * the same hash, indicating the stills are semantic duplicates of the same
     * capture (different JPG encoding/metadata, not different photos).
     */
    #[Test]
    public function applySkipsSubGroupingWhenCompanionVideosShareHash(): void
    {
        $service = $this->createHashSubGroupingService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $jpgA = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.jpg';
        $movA = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0001.mov';
        $jpgB = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.jpg';
        $movB = $sourceDirectory . DIRECTORY_SEPARATOR . 'IMG_0002.mov';

        // Stills have different content (different hashes).
        file_put_contents($jpgA, 'still-content-A');
        file_put_contents($jpgB, 'still-content-B-different');

        // Companion videos have IDENTICAL content (same hash).
        file_put_contents($movA, 'identical-video-content');
        file_put_contents($movB, 'identical-video-content');

        $target = $targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($jpgA))
            ->addFile(new SplFileInfo($movA))
            ->addFile(new SplFileInfo($jpgB))
            ->addFile(new SplFileInfo($movB))
            ->setTarget(new SplFileInfo($target));

        $renameJpgA = new Rename(
            new SplFileInfo($jpgA),
            new SplFileInfo($targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.jpg'),
        );
        $renameMovA = new Rename(
            new SplFileInfo($movA),
            new SplFileInfo($targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.mov'),
        );
        $renameJpgB = new Rename(
            new SplFileInfo($jpgB),
            new SplFileInfo($targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.jpg'),
        );
        $renameMovB = new Rename(
            new SplFileInfo($movB),
            new SplFileInfo($targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28.mov'),
        );

        $fileDuplicate->addRename($renameJpgA);
        $fileDuplicate->addRename($renameMovA);
        $fileDuplicate->addRename($renameJpgB);
        $fileDuplicate->addRename($renameMovB);

        // Content identifier map links stills to their companion videos.
        $contentIdentifierMap = [
            $jpgA => 'live-photo-001',
            $movA => 'live-photo-001',
            $jpgB => 'live-photo-002',
            $movB => 'live-photo-002',
        ];

        $result = $service->apply(
            $fileDuplicate,
            $renameJpgA,
            $renameMovA,
            $contentIdentifierMap,
            $this->createTargetPathnameResolver($sourceDirectory, $targetDirectory),
        );

        self::assertNull($result);
    }

    /**
     * Verifies that apply() performs sub-grouping even when the canonical target
     * basename contains a non-zero subsecond timestamp. The SubSecond heuristic
     * has been moved to DuplicateDetectionService where software tags are checked.
     */
    #[Test]
    public function applySubGroupsEvenWithSubsecondTimestamp(): void
    {
        $service = $this->createHashSubGroupingService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $fileB = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';

        file_put_contents($fileA, 'content-A');
        file_put_contents($fileB, 'content-B-different');

        // Target basename ends with -411 (non-zero subseconds).
        $target = $targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28-411.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($target));

        $renameA = new Rename(new SplFileInfo($fileA), new SplFileInfo($target));
        $renameB = new Rename(new SplFileInfo($fileB), new SplFileInfo($target));
        $fileDuplicate->addRename($renameA);
        $fileDuplicate->addRename($renameB);

        $result = $service->apply(
            $fileDuplicate,
            $renameA,
            null,
            [],
            $this->createTargetPathnameResolver($sourceDirectory, $targetDirectory),
        );

        self::assertIsArray($result);
    }

    /**
     * Verifies that apply() still applies sub-grouping when the canonical target
     * basename ends with -000 (zero subseconds). Zero subseconds do not provide
     * enough precision to determine capture uniqueness.
     */
    #[Test]
    public function applyStillSubGroupsWhenSubsecondIsZero(): void
    {
        $service = $this->createHashSubGroupingService();

        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $fileB = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';

        file_put_contents($fileA, 'content-A');
        file_put_contents($fileB, 'content-B-different');

        // Target basename ends with -000 (zero subseconds).
        $target = $targetDirectory . DIRECTORY_SEPARATOR . '2025-01-01_00-02-28-000.jpg';

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($target));

        $renameA = new Rename(new SplFileInfo($fileA), new SplFileInfo($target));
        $renameB = new Rename(new SplFileInfo($fileB), new SplFileInfo($target));
        $fileDuplicate->addRename($renameA);
        $fileDuplicate->addRename($renameB);

        $result = $service->apply(
            $fileDuplicate,
            $renameA,
            null,
            [],
            $this->createTargetPathnameResolver($sourceDirectory, $targetDirectory),
        );

        self::assertIsArray($result);
    }

    /**
     * @return Closure(SplFileInfo, string): string
     */
    private function createTargetPathnameResolver(string $sourceDirectory, string $targetDirectory): Closure
    {
        return static function (SplFileInfo $sourceFileInfo, string $targetFilename) use ($sourceDirectory, $targetDirectory): string {
            $sourcePath   = $sourceFileInfo->getPath();
            $relativePath = $sourcePath;

            if (str_starts_with($sourcePath, $sourceDirectory)) {
                $relativePath = substr($sourcePath, strlen($sourceDirectory));
            }

            $relativePath = trim($relativePath, DIRECTORY_SEPARATOR);

            $targetPath = rtrim($targetDirectory, DIRECTORY_SEPARATOR);

            if ($relativePath !== '') {
                $targetPath .= DIRECTORY_SEPARATOR . $relativePath;
            }

            return $targetPath . DIRECTORY_SEPARATOR . $targetFilename;
        };
    }

    /**
     * Verifies that apply() merges hash groups when their dHash Hamming distance
     * is below the threshold, treating them as perceptual duplicates.
     *
     * Two files with different xxh128 content hashes but identical visual content
     * (same dHash) should be merged into a single group → apply() returns null.
     */
    #[Test]
    public function applyMergesPerceptuallySimilarHashGroups(): void
    {
        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $fileB = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';

        // Must be valid JPEG files so Imagick can load them for RMSE analysis.
        // Conservative merge policy: Imagick load failure → no merge.
        $this->createSyntheticJpeg($fileA);
        $this->createSyntheticJpeg($fileB);

        $target = $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        // Both files produce the same dHash → visually identical → merge
        $stub = new StubPerceptualHashCalculator()
            ->withHash($fileA, 'abcdef0123456789')
            ->withHash($fileB, 'abcdef0123456789');

        $service = $this->createHashSubGroupingServiceWithStub($stub);

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($target));

        $renameA = new Rename(new SplFileInfo($fileA), new SplFileInfo($target));
        $renameB = new Rename(new SplFileInfo($fileB), new SplFileInfo($target));
        $fileDuplicate->addRename($renameA);
        $fileDuplicate->addRename($renameB);

        $result = $service->apply(
            $fileDuplicate,
            $renameA,
            null,
            [],
            $this->createTargetPathnameResolver($sourceDirectory, $targetDirectory),
        );

        self::assertNull($result, 'Perceptually identical files should be merged → no sub-grouping');
    }

    /**
     * Verifies that dHash-identical format backups stay merged when decoder noise
     * produces RMSE slightly above the old internal exact-match safe zone.
     */
    #[Test]
    public function applyMergesDhashExactFormatNoiseWithinDefaultThreshold(): void
    {
        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $fileB = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';

        $this->createSyntheticJpeg($fileA, color: '#808080');
        $this->createSyntheticJpeg($fileB, color: '#8c8c8c');

        $target = $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        $stub = new StubPerceptualHashCalculator()
            ->withHash($fileA, 'abcdef0123456789')
            ->withHash($fileB, 'abcdef0123456789');

        $service = $this->createHashSubGroupingServiceWithStub($stub);

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($target));

        $renameA = new Rename(new SplFileInfo($fileA), new SplFileInfo($target));
        $renameB = new Rename(new SplFileInfo($fileB), new SplFileInfo($target));
        $fileDuplicate->addRename($renameA);
        $fileDuplicate->addRename($renameB);

        $result = $service->apply(
            $fileDuplicate,
            $renameA,
            null,
            [],
            $this->createTargetPathnameResolver($sourceDirectory, $targetDirectory),
        );

        self::assertNull($result, 'dHash-identical format noise should merge under the default threshold.');
    }

    /**
     * Verifies that simple two-file format backups are merged even when the
     * perceptual pre-classifier downgrades them to edited variants.
     */
    #[Test]
    public function applyMergesSimpleFormatBackupEditedVariantWithoutMetadata(): void
    {
        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.heic';
        $fileB = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';

        $this->createSyntheticJpeg($fileA, color: '#808080');
        $this->createSyntheticJpeg($fileB, color: '#8c8c8c');

        $target = $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        $stub = new StubPerceptualHashCalculator()
            ->withHash($fileA, 'abcdef0123456789')
            ->withHash($fileB, 'abcdef01234567ff');

        $service = $this->createHashSubGroupingServiceWithStub($stub);

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($target));

        $renameA = new Rename(new SplFileInfo($fileA), new SplFileInfo($target));
        $renameB = new Rename(new SplFileInfo($fileB), new SplFileInfo($target));
        $fileDuplicate->addRename($renameA);
        $fileDuplicate->addRename($renameB);

        $result = $service->apply(
            $fileDuplicate,
            $renameA,
            null,
            [],
            $this->createTargetPathnameResolver($sourceDirectory, $targetDirectory),
        );

        self::assertNull($result, 'HEIC/JPG backup pairs with the same target basename should stay in one sub-group.');
    }

    /**
     * Verifies that an explicit stricter merge threshold still overrides the
     * dHash-exact safe zone.
     */
    #[Test]
    public function applyKeepsDhashExactFormatNoiseSeparateWhenUserThresholdIsStricter(): void
    {
        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $fileB = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';

        $this->createSyntheticJpeg($fileA, color: '#808080');
        $this->createSyntheticJpeg($fileB, color: '#8c8c8c');

        $target = $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        $stub = new StubPerceptualHashCalculator()
            ->withHash($fileA, 'abcdef0123456789')
            ->withHash($fileB, 'abcdef0123456789');

        $service = $this->createHashSubGroupingServiceWithStub($stub);
        $service->setMaxMergeRmse(0.04);

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($target));

        $renameA = new Rename(new SplFileInfo($fileA), new SplFileInfo($target));
        $renameB = new Rename(new SplFileInfo($fileB), new SplFileInfo($target));
        $fileDuplicate->addRename($renameA);
        $fileDuplicate->addRename($renameB);

        $result = $service->apply(
            $fileDuplicate,
            $renameA,
            null,
            [],
            $this->createTargetPathnameResolver($sourceDirectory, $targetDirectory),
        );

        self::assertIsArray($result, 'A stricter user threshold must keep the files in separate sub-groups.');
    }

    /**
     * Verifies that perceptual merges remain safe when the lexicographically
     * smallest content hash is not the initial union-find root.
     *
     * This guards the deterministic re-rooting step against creating parent cycles
     * after a successful merge. The merged pair must still collapse into a single
     * sub-group instead of hanging during root lookup.
     */
    #[Test]
    public function applyMergesPerceptualGroupsWhenSmallestHashStartsAsNonRoot(): void
    {
        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $fileB = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';

        $this->createSyntheticJpeg($fileA);
        $this->createSyntheticJpeg($fileB);

        $target = $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        $perceptualStub = new StubPerceptualHashCalculator()
            ->withHash($fileA, 'abcdef0123456789')
            ->withHash($fileB, 'abcdef0123456789');

        $hashCalculator = $this->createMock(SafeHashCalculatorInterface::class);
        $hashCalculator->expects(self::exactly(2))
            ->method('hashFile')
            ->willReturnCallback(static fn (SplFileInfo $file): string => match ($file->getFilename()) {
                'a.jpg' => 'ffff000000000000',
                'b.jpg' => '0000ffff00000000',
                default => '9999999999999999',
            });

        $service = $this->createHashSubGroupingServiceWithCalculator($hashCalculator, $perceptualStub);

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($target));

        $renameA = new Rename(new SplFileInfo($fileA), new SplFileInfo($target));
        $renameB = new Rename(new SplFileInfo($fileB), new SplFileInfo($target));
        $fileDuplicate->addRename($renameA);
        $fileDuplicate->addRename($renameB);

        $result = $service->apply(
            $fileDuplicate,
            $renameA,
            null,
            [],
            $this->createTargetPathnameResolver($sourceDirectory, $targetDirectory),
        );

        self::assertNull($result, 'Perceptually merged groups with a smaller non-root hash must remain cycle-free.');
    }

    /**
     * Verifies that apply() keeps hash groups separate when their dHash Hamming
     * distance exceeds the threshold (visually distinct content → sub-groups).
     */
    #[Test]
    public function applyKeepsPerceptuallyDistinctHashGroups(): void
    {
        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $fileB = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';

        file_put_contents($fileA, 'content-A');
        file_put_contents($fileB, 'content-B-different');

        $target = $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        // Maximally different dHashes → visually distinct → keep separate
        $stub = new StubPerceptualHashCalculator()
            ->withHash($fileA, '0000000000000000')
            ->withHash($fileB, 'ffffffffffffffff');

        $service = $this->createHashSubGroupingServiceWithStub($stub);

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($target));

        $renameA = new Rename(new SplFileInfo($fileA), new SplFileInfo($target));
        $renameB = new Rename(new SplFileInfo($fileB), new SplFileInfo($target));
        $fileDuplicate->addRename($renameA);
        $fileDuplicate->addRename($renameB);

        $result = $service->apply(
            $fileDuplicate,
            $renameA,
            null,
            [],
            $this->createTargetPathnameResolver($sourceDirectory, $targetDirectory),
        );

        self::assertIsArray($result, 'Visually distinct files should remain in separate sub-groups');
    }

    /**
     * Verifies that apply() gracefully handles dHash computation failures
     * by keeping hash groups separate (conservative: assume different content).
     */
    #[Test]
    public function applyHandlesDhashFailureGracefully(): void
    {
        $sourceDirectory = $this->createTempDirectory();
        $targetDirectory = $this->createTempDirectory();

        $fileA = $sourceDirectory . DIRECTORY_SEPARATOR . 'a.jpg';
        $fileB = $sourceDirectory . DIRECTORY_SEPARATOR . 'b.jpg';

        file_put_contents($fileA, 'content-A');
        file_put_contents($fileB, 'content-B-different');

        $target = $targetDirectory . DIRECTORY_SEPARATOR . 'target.jpg';

        // dHash returns null for both → cannot compare → keep separate
        $stub = new StubPerceptualHashCalculator()
            ->withHash($fileA, null)
            ->withHash($fileB, null);

        $service = $this->createHashSubGroupingServiceWithStub($stub);

        $fileDuplicate = new FileDuplicate();
        $fileDuplicate
            ->addFile(new SplFileInfo($fileA))
            ->addFile(new SplFileInfo($fileB))
            ->setTarget(new SplFileInfo($target));

        $renameA = new Rename(new SplFileInfo($fileA), new SplFileInfo($target));
        $renameB = new Rename(new SplFileInfo($fileB), new SplFileInfo($target));
        $fileDuplicate->addRename($renameA);
        $fileDuplicate->addRename($renameB);

        $result = $service->apply(
            $fileDuplicate,
            $renameA,
            null,
            [],
            $this->createTargetPathnameResolver($sourceDirectory, $targetDirectory),
        );

        self::assertIsArray($result, 'Failed dHash should result in separate sub-groups (conservative)');
    }

    private function createHashSubGroupingService(): HashSubGroupingService
    {
        return $this->createHashSubGroupingServiceWithStub(new StubPerceptualHashCalculator());
    }

    private function createHashSubGroupingServiceWithStub(PerceptualHashCalculatorInterface $perceptualHashCalculator): HashSubGroupingService
    {
        return $this->createHashSubGroupingServiceWithCalculator(
            new SafeHashCalculator(),
            $perceptualHashCalculator,
        );
    }

    private function createHashSubGroupingServiceWithCalculator(
        SafeHashCalculatorInterface $hashCalculator,
        PerceptualHashCalculatorInterface $perceptualHashCalculator,
    ): HashSubGroupingService {
        $imageLoader = new ImagickImageLoader(new MediaTypeClassifier());

        return new HashSubGroupingService(
            $hashCalculator,
            new NullProgressReporter(),
            new MediaTypeClassifier(),
            $perceptualHashCalculator,
            new LocalDifferenceAnalyzer(),
            $imageLoader,
        );
    }

    private function createTempDirectory(): string
    {
        $directory               = $this->createTempWorkspace('photo-renamer-');
        $this->tempDirectories[] = $directory;

        return $directory;
    }

    /**
     * Creates a minimal valid JPEG file for tests that need Imagick-loadable images.
     */
    private function createSyntheticJpeg(string $path, int $width = 100, int $height = 100, string $color = 'gray'): void
    {
        $img = new Imagick();
        $img->newImage($width, $height, new ImagickPixel($color));
        $img->setImageFormat('jpeg');
        $img->writeImage($path);
        $img->clear();
    }
}
