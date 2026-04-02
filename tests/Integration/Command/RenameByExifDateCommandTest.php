<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration\Command;

use DateTimeImmutable;
use MagicSunday\Renamer\Command\RenameByExifDateCommand;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AbstractCollection;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Collection\FileList;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\Execution\ExecutionGroup;
use MagicSunday\Renamer\Model\Execution\ExecutionItem;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\Execution\ExecutionPreview;
use MagicSunday\Renamer\Model\Execution\ExecutionResult;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\LinkConfig;
use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\OutputEntryType;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Model\TargetFileResult;
use MagicSunday\Renamer\Regex\RegexMatchResult;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\CanonicalScorer;
use MagicSunday\Renamer\Service\DuplicateDetectionService;
use MagicSunday\Renamer\Service\Execution\ExecutionPlanBuilder;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\HashSubGroupingService;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoBasenameTargetMap;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoConflictDetector;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoContentIdentifierTarget;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoContentIdentifierTargetMap;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoExistingFilePathnameIndex;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingCollection;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingService;
use MagicSunday\Renamer\Service\MediaCompatibilityPolicy;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\MetadataCache;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\PerceptualHash\LocalDifferenceAnalyzer;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use MagicSunday\Renamer\Service\PerceptualHash\SimilarityResult;
use MagicSunday\Renamer\Service\Pipeline\AssetGroupPipeline;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuilder;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuildState;
use MagicSunday\Renamer\Service\Pipeline\CollisionResolver;
use MagicSunday\Renamer\Service\Pipeline\CompanionDetector;
use MagicSunday\Renamer\Service\Pipeline\ExifRenamePipelineResult;
use MagicSunday\Renamer\Service\Pipeline\OrphanLivePhotoVideoReconciler;
use MagicSunday\Renamer\Service\Pipeline\RoleAssigner;
use MagicSunday\Renamer\Service\Pipeline\SubgroupClassifier;
use MagicSunday\Renamer\Service\Pipeline\TargetNameResolver;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\RenamePlanValidator;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Service\ValidationResult;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubPerceptualHashCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

use function array_filter;
use function array_keys;
use function array_search;
use function array_values;
use function file_put_contents;
use function mkdir;
use function preg_replace;
use function rtrim;
use function str_starts_with;
use function strpos;
use function substr;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

/**
 * End-to-end integration tests for the complete rename pipeline:
 * scan -> group -> pair (Live Photo) -> hash sub-group -> assign filenames -> execute.
 *
 * These tests use real services (no mocks) with temporary directories and stub
 * metadata to verify the full rename mapping for complex multi-file scenarios
 * including true duplicates, hash sub-grouping, Live Photo pairing, subdirectory
 * handling, mixed extensions, and idempotency on re-runs.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(RenameByExifDateCommand::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(ExecutionGroup::class)]
#[UsesClass(ExecutionItem::class)]
#[UsesClass(ExecutionPlan::class)]
#[UsesClass(ExecutionPreview::class)]
#[UsesClass(ExecutionResult::class)]
#[UsesClass(OutputEntry::class)]
#[UsesClass(OutputEntryType::class)]
#[UsesClass(PipelineContext::class)]
#[UsesClass(ValidationResult::class)]
#[UsesClass(RecursiveRegexFileFilterIterator::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(ExifMetadataProvider::class)]
#[UsesClass(TemporalMetadata::class)]
#[UsesClass(AbstractCollection::class)]
#[UsesClass(FileDuplicateCollection::class)]
#[UsesClass(FileList::class)]
#[UsesClass(RenameList::class)]
#[UsesClass(FileDuplicate::class)]
#[UsesClass(LinkConfig::class)]
#[UsesClass(OutputEntryTag::class)]
#[UsesClass(Rename::class)]
#[UsesClass(RenameOptions::class)]
#[UsesClass(RenameResult::class)]
#[UsesClass(TargetFileResult::class)]
#[UsesClass(RegexMatchResult::class)]
#[UsesClass(SafeRegex::class)]
#[UsesClass(DuplicateDetectionService::class)]
#[UsesClass(FileSystemService::class)]
#[UsesClass(HashSubGroupingService::class)]
#[UsesClass(ImagickImageLoader::class)]
#[UsesClass(LivePhotoBasenameTargetMap::class)]
#[UsesClass(LivePhotoPairingService::class)]
#[UsesClass(LivePhotoConflictDetector::class)]
#[UsesClass(LivePhotoContentIdentifierTarget::class)]
#[UsesClass(LivePhotoContentIdentifierTargetMap::class)]
#[UsesClass(LivePhotoExistingFilePathnameIndex::class)]
#[UsesClass(LivePhotoPairingCollection::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(MetadataCache::class)]
#[UsesClass(PerceptualSignalCache::class)]
#[UsesClass(ExecutionPlanBuilder::class)]
#[UsesClass(CanonicalScorer::class)]
#[UsesClass(AssetGroupPipeline::class)]
#[UsesClass(ExifRenamePipelineResult::class)]
#[UsesClass(CaptureGroupBuilder::class)]
#[UsesClass(CaptureGroupBuildState::class)]
#[UsesClass(CollisionResolver::class)]
#[UsesClass(CompanionDetector::class)]
#[UsesClass(RoleAssigner::class)]
#[UsesClass(SubgroupClassifier::class)]
#[UsesClass(TargetNameResolver::class)]
#[UsesClass(RenamePlanValidator::class)]
#[UsesClass(RenameOutputRenderer::class)]
#[UsesClass(SafeHashCalculator::class)]
#[UsesClass(SimilarityResult::class)]
#[UsesClass(TargetBasenameStrategy::class)]
#[UsesClass(ExifDateFilenameStrategy::class)]
final class RenameByExifDateCommandTest extends TestCase
{
    use WorkspaceTrait;

    private const string DATE_A = '2025-01-01T00:02:20.016+00:00';

    private const string DATE_B = '2025-01-01T00:02:21.345+00:00';

    private const string DATE_C = '2025-01-01T00:02:22.000+00:00';

    private const string DATE_D = '2025-01-01T00:02:23.000+00:00';

    private const string DATE_E = '2025-01-01T00:02:25.000+00:00';

    /**
     * A timestamp with zero subseconds, used by idempotency tests for hash
     * sub-grouping. Zero subseconds ensure sub-grouping is not bypassed by
     * the semantic-duplicate subsecond heuristic.
     */
    private const string DATE_SUBGROUP = '2025-01-01T00:02:24.000+00:00';

    /**
     * Verifies the complete rename mapping for 13 files across multiple scenarios:
     *
     * - Live Photo LP-1: canonical HEIC gets unsuffixed name, MOV companion inherits
     *   it, duplicate MOV gets -duplicate-001
     * - True duplicates of the canonical (same hash 123): renumbered sequentially
     *   including a file with a pre-existing -duplicate-001 suffix from a previous run
     * - Hash sub-grouping LP-B: different hash at the same timestamp gets -002,
     *   companion MOV inherits the -002 sub-group number
     * - Unique files: unsuffixed names at their respective timestamps
     * - Mixed case/extension: A.JPG (uppercase ext) gets lowercased and duplicate-suffixed
     * - Subdirectory: nested file gets its relative path preserved with own date
     * - Parent-before-child ordering: parent dir files are processed before nested ones
     * - No nested -duplicate--duplicate- patterns in any target name
     *
     * File scenarios:
     *   1.jpg       hash:123  dateA  LP-1    → canonical (Live Photo)
     *   2.jpg       hash:123  dateA  —       → duplicate of 1 (same hash, no LP)
     *   3.jpg       hash:456  dateB  —       → unique
     *   4.jpg       hash:789  dateC  —       → unique
     *   sub/1.jpg   hash:456  dateE  —       → unique (subdirectory, different date)
     *   a.jpg       hash:234  dateD  —       → canonical for dateD
     *   A.jpg       hash:234  dateD  —       → duplicate of a (same hash)
     *   A.JPG       hash:123  dateA  —       → duplicate of 1 (same hash, uppercase ext)
     *   1-dup.jpg   hash:123  dateA  —       → duplicate of 1 (already suffixed)
     *   1.mov       hash:abc  dateA  LP-1    → companion mov (Live Photo)
     *   mov.mov     hash:abc  —     LP-1    → duplicate mov (paired by content ID)
     *   B.jpg       hash:cde  dateA  LP-B    → hash sub-group 002 (Live Photo)
     *   B.mov       hash:fgh  —     LP-B    → companion inherits sub-group 002
     */
    #[Test]
    public function executeProducesExpectedRenameMapping(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('renamer_full_', true);
        $subDir    = $workspace . DIRECTORY_SEPARATOR . 'sub';

        mkdir($workspace, 0o755);
        mkdir($subDir, 0o755);

        try {
            // ---- File definitions: name => [content, date, livePhotoId] ----
            $definitions = [
                '1.jpg'               => ['hash-123', self::DATE_A, 'LP-1'],
                '2.jpg'               => ['hash-123', self::DATE_A, null],
                '3.jpg'               => ['hash-456', self::DATE_B, null],
                '4.jpg'               => ['hash-789', self::DATE_C, null],
                'sub/1.jpg'           => ['hash-456', self::DATE_E, null],
                'a.jpg'               => ['hash-234', self::DATE_D, null],
                'A.jpg'               => ['hash-234', self::DATE_D, null],
                'A.JPG'               => ['hash-123', self::DATE_A, null],
                '1-duplicate-001.jpg' => ['hash-123', self::DATE_A, null],
                '1.mov'               => ['hash-abc', self::DATE_A, 'LP-1'],
                'mov.mov'             => ['hash-abc', null,         'LP-1'],
                'B.jpg'               => ['hash-cde', self::DATE_A, 'LP-B'],
                'B.mov'               => ['hash-fgh', null,         'LP-B'],
            ];

            $metadataExtractor = new StubMetadataExtractor();

            foreach ($definitions as $name => [$content, $date, $livePhotoId]) {
                $path = $workspace . DIRECTORY_SEPARATOR . $name;
                file_put_contents($path, $content);

                $dateTime = $date !== null ? new DateTimeImmutable($date) : null;
                $metadataExtractor->withResponse($path, new TemporalMetadata($dateTime, $livePhotoId));
            }

            // ---- Execute ----
            $mappings = $this->runDryRun($workspace, $metadataExtractor);

            self::assertCount(13, $mappings);

            // ---- Live Photo group LP-1: canonical + companion ----
            self::assertSame(
                '2025-01-01_00-02-20-016.jpg',
                $mappings['1.jpg'],
                'LP-1 canonical gets unsuffixed base name',
            );
            self::assertSame(
                '2025-01-01_00-02-20-016.mov',
                $mappings['1.mov'],
                'LP-1 companion inherits base name',
            );
            self::assertSame(
                '2025-01-01_00-02-20-016-duplicate-001.mov',
                $mappings['mov.mov'],
                'LP-1 non-companion MOV gets -duplicate-001 (only MOV duplicate)',
            );

            // ---- True duplicates of 1.jpg (same hash 123, no LP) ----
            // Alphabetical order: 1-duplicate-001 → dup-001, 2 → dup-002, A → dup-003
            self::assertSame(
                '2025-01-01_00-02-20-016-duplicate-001.jpg',
                $mappings['1-duplicate-001.jpg'],
                'Already-suffixed file gets renumbered to -duplicate-001',
            );
            self::assertSame(
                '2025-01-01_00-02-20-016-duplicate-002.jpg',
                $mappings['2.jpg'],
                'True duplicate gets -duplicate-002.jpg',
            );
            self::assertSame(
                '2025-01-01_00-02-20-016-duplicate-003.jpg',
                $mappings['A.JPG'],
                'A.JPG gets -duplicate-003 (extension lowercased by hash sub-grouping)',
            );

            // ---- Hash sub-grouping: B.jpg (different hash, same date, LP-B) ----
            self::assertSame(
                '2025-01-01_00-02-20-016-002.jpg',
                $mappings['B.jpg'],
                'Different hash → sub-group 002',
            );
            self::assertSame(
                '2025-01-01_00-02-20-016-002.mov',
                $mappings['B.mov'],
                'LP-B companion inherits sub-group 002',
            );

            // ---- Unique files ----
            self::assertSame(
                '2025-01-01_00-02-21-345.jpg',
                $mappings['3.jpg'],
                'Unique file gets unsuffixed name',
            );
            self::assertSame(
                '2025-01-01_00-02-22-000.jpg',
                $mappings['4.jpg'],
                'Unique file gets unsuffixed name',
            );

            // ---- Different date group: A.jpg / a.jpg (A before a in ASCII) ----
            self::assertSame(
                '2025-01-01_00-02-23-000.jpg',
                $mappings['A.jpg'],
                'A.jpg is canonical for dateD (uppercase sorts first)',
            );
            self::assertSame(
                '2025-01-01_00-02-23-000-duplicate-001.jpg',
                $mappings['a.jpg'],
                'a.jpg is duplicate',
            );

            // ---- Subdirectory: independent group (basename-only grouping, different date) ----
            self::assertSame(
                'sub' . DIRECTORY_SEPARATOR . '2025-01-01_00-02-25-000.jpg',
                $mappings['sub' . DIRECTORY_SEPARATOR . '1.jpg'],
                'Subdirectory file has its own date and gets unsuffixed name',
            );

            // ---- Ordering: parent dir before subdirectory ----
            $keys        = array_keys($mappings);
            $parentIndex = array_search('3.jpg', $keys, true);
            $subIndex    = array_search('sub' . DIRECTORY_SEPARATOR . '1.jpg', $keys, true);

            self::assertIsInt($parentIndex);
            self::assertIsInt($subIndex);
            self::assertLessThan($subIndex, $parentIndex, 'Parent directory before subdirectory');

            // ---- No duplicate-duplicate names ----
            foreach ($mappings as $target) {
                self::assertStringNotContainsString(
                    '-duplicate--duplicate-',
                    $target,
                    'No nested duplicate suffixes',
                );
                self::assertDoesNotMatchRegularExpression(
                    '/-duplicate-\d+-duplicate-/',
                    $target,
                    'No duplicate-NNN-duplicate pattern',
                );
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies idempotency when all files in a group already carry -duplicate-NNN
     * suffixes from a previous run. The canonical must reclaim the unsuffixed base
     * name, and the remaining files must keep their -duplicate-NNN suffixes.
     *
     * This is a regression test for the bug where all files kept -duplicate-NNN
     * and no file received the clean base name on re-run, causing the canonical
     * to be "lost" with every iteration.
     */
    #[Test]
    public function executeIdempotentWhenAllFilesAlreadyHaveDuplicateSuffixes(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('renamer_idempotent_', true);

        mkdir($workspace, 0o755);

        try {
            $fileNames = [
                '2025-01-01_00-02-20-016-duplicate-001.jpg',
                '2025-01-01_00-02-20-016-duplicate-002.jpg',
                '2025-01-01_00-02-20-016-duplicate-003.jpg',
            ];

            $metadataExtractor = new StubMetadataExtractor();
            $dateTime          = new DateTimeImmutable(self::DATE_A);

            foreach ($fileNames as $name) {
                $path = $workspace . DIRECTORY_SEPARATOR . $name;
                file_put_contents($path, 'same-content');
                $metadataExtractor->withResponse($path, new TemporalMetadata($dateTime, null));
            }

            $mappings = $this->runDryRun($workspace, $metadataExtractor);
            $targets  = array_values($mappings);

            self::assertContains(
                '2025-01-01_00-02-20-016.jpg',
                $targets,
                'Canonical must reclaim the unsuffixed base name',
            );

            $suffixedTargets = array_filter(
                $targets,
                static fn (string $targetPath): bool => $targetPath !== '2025-01-01_00-02-20-016.jpg',
            );

            foreach ($suffixedTargets as $target) {
                self::assertMatchesRegularExpression(
                    '/2025-01-01_00-02-20-016-duplicate-\d{3}\.jpg/',
                    $target,
                    'Non-canonical files keep duplicate suffix',
                );
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies idempotency for hash sub-group naming: files that are already named
     * with the correct sub-group numbers from a previous run (unsuffixed canonical
     * and -002 for a different hash) must all produce [O] (no-op) mappings where
     * source == target on re-run.
     *
     * This is a regression test to ensure that re-running the command on an already-
     * processed directory does not shuffle, rename, or re-number files when each
     * sub-group contains exactly one file (no within-sub-group duplicates).
     *
     * Source layout (output of a previous run):
     *   2025-01-01_00-02-20-016.jpg      hashA  -> canonical sub-group (unsuffixed)
     *   2025-01-01_00-02-20-016-002.jpg   hashB  -> second sub-group
     *
     * Expected: both files map to themselves (all [O], no renames).
     */
    #[Test]
    public function executeIdempotentWhenFilesAlreadyHaveSubGroupNumbers(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('renamer_subgroup_idem_', true);

        mkdir($workspace, 0o755);

        try {
            // Simulate the output of a previous successful run with hash sub-grouping.
            // Two distinct hashes (A, B) at the same timestamp, one file per sub-group.
            // Uses zero-subsecond timestamp so the subsecond heuristic does not bypass
            // sub-grouping (non-zero subseconds are treated as semantic duplicates).
            $fileDefinitions = [
                '2025-01-01_00-02-24-000.jpg'     => 'hash-content-A',
                '2025-01-01_00-02-24-000-002.jpg' => 'hash-content-B',
            ];

            $metadataExtractor = new StubMetadataExtractor();
            $dateTime          = new DateTimeImmutable(self::DATE_SUBGROUP);

            foreach ($fileDefinitions as $name => $content) {
                $path = $workspace . DIRECTORY_SEPARATOR . $name;
                file_put_contents($path, $content);
                $metadataExtractor->withResponse($path, new TemporalMetadata($dateTime, null));
            }

            $mappings = $this->runDryRun($workspace, $metadataExtractor);

            self::assertCount(2, $mappings, 'Both files must appear in the mapping');

            // The canonical hashA file keeps the unsuffixed base name.
            self::assertSame(
                '2025-01-01_00-02-24-000.jpg',
                $mappings['2025-01-01_00-02-24-000.jpg'],
                'Canonical hashA file must be idempotent (source == target)',
            );

            // The hashB sub-group file keeps its -002 suffix.
            self::assertSame(
                '2025-01-01_00-02-24-000-002.jpg',
                $mappings['2025-01-01_00-02-24-000-002.jpg'],
                'HashB sub-group file must be idempotent (source == target)',
            );
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies idempotency for the canonical sub-group with true duplicates: when
     * the canonical file has the unsuffixed base name and its duplicate has
     * -duplicate-001, re-running keeps both files at their current names.
     *
     * This complements the sub-group idempotency test above by verifying that
     * within-sub-group duplicate suffixes are also stable across runs when the
     * files are in the canonical (unsuffixed) sub-group.
     *
     * Source layout (output of a previous run):
     *   2025-01-01_00-02-24-000.jpg                hashA  -> canonical
     *   2025-01-01_00-02-24-000-duplicate-001.jpg   hashA  -> true duplicate
     *   2025-01-01_00-02-24-000-002.jpg             hashB  -> second sub-group
     *
     * Uses zero-subsecond timestamp so the subsecond heuristic does not bypass
     * sub-grouping (non-zero subseconds are treated as semantic duplicates).
     *
     * Expected: all three files map to themselves.
     */
    #[Test]
    public function executeIdempotentWhenCanonicalSubGroupHasDuplicates(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('renamer_subgroup_dup_', true);

        mkdir($workspace, 0o755);

        try {
            $fileDefinitions = [
                '2025-01-01_00-02-24-000.jpg'               => 'hash-content-A',
                '2025-01-01_00-02-24-000-duplicate-001.jpg' => 'hash-content-A',
                '2025-01-01_00-02-24-000-002.jpg'           => 'hash-content-B',
            ];

            $metadataExtractor = new StubMetadataExtractor();
            $dateTime          = new DateTimeImmutable(self::DATE_SUBGROUP);

            foreach ($fileDefinitions as $name => $content) {
                $path = $workspace . DIRECTORY_SEPARATOR . $name;
                file_put_contents($path, $content);
                $metadataExtractor->withResponse($path, new TemporalMetadata($dateTime, null));
            }

            $mappings = $this->runDryRun($workspace, $metadataExtractor);

            self::assertCount(3, $mappings, 'All three files must appear in the mapping');

            self::assertSame(
                '2025-01-01_00-02-24-000.jpg',
                $mappings['2025-01-01_00-02-24-000.jpg'],
                'Canonical file must be idempotent',
            );

            self::assertSame(
                '2025-01-01_00-02-24-000-duplicate-001.jpg',
                $mappings['2025-01-01_00-02-24-000-duplicate-001.jpg'],
                'Canonical sub-group duplicate must be idempotent',
            );

            self::assertSame(
                '2025-01-01_00-02-24-000-002.jpg',
                $mappings['2025-01-01_00-02-24-000-002.jpg'],
                'Second sub-group file must be idempotent',
            );
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that a still/video pair in the same timestamp group with
     * conflicting non-null content identifiers is surfaced as a review
     * candidate [C] and skipped instead of being auto-paired.
     */
    #[Test]
    public function executeMarksConflictingLivePhotoContentIdentifiersAsCandidates(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('renamer_live_conflict_', true);

        mkdir($workspace, 0o755);

        try {
            $jpgPath = $workspace . DIRECTORY_SEPARATOR . 'IMG_0001.jpg';
            $movPath = $workspace . DIRECTORY_SEPARATOR . 'IMG_0001.mov';

            file_put_contents($jpgPath, 'photo');
            file_put_contents($movPath, 'video');

            $dateTime          = new DateTimeImmutable(self::DATE_A);
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $jpgPath,
                new TemporalMetadata(
                    $dateTime,
                    'photo-content-id',
                    false,
                    false,
                    8192,
                    'Apple',
                    'iPhone 8',
                    '13.6.1',
                    51.79375,
                    10.60537,
                ),
            );
            $metadataExtractor->withResponse(
                $movPath,
                new TemporalMetadata(
                    $dateTime,
                    'video-content-id',
                    false,
                    false,
                    null,
                    'Apple',
                    'iPhone 8',
                    '13.6.1',
                    51.79376,
                    10.60538,
                    2.6,
                    true,
                ),
            );

            $output = $this->runDryRunOutput($workspace, $metadataExtractor);
            $clean  = preg_replace('/<[^>]+>/', '', $output) ?? $output;

            self::assertNotFalse(
                strpos($clean, '[C]'),
                'Conflicting Live Photo candidates must be marked with [C]',
            );
            self::assertStringContainsString('IMG_0001.jpg', $clean);
            self::assertStringContainsString('IMG_0001.mov', $clean);
            self::assertStringContainsString('Conflicting Live Photo content ID across groups', $clean);
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that the decision log is rendered in --dry-run --list-all output
     * when the pipeline encounters a multi-file group that requires canonical
     * selection. The RoleAssigner logs "Canonical: <file> (score N: ...)" for
     * every group with more than one candidate, and the RenameOutputRenderer
     * wraps these in a "Decision Log" section.
     *
     * Uses two files with the same EXIF date but different hashes so
     * CaptureGroupBuilder creates a single group with two items, triggering
     * canonical scoring and decision logging.
     */
    #[Test]
    public function decisionLogVisibleInDryRunWithListAll(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('renamer_declog_', true);

        mkdir($workspace, 0o755);

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $dateTime          = new DateTimeImmutable(self::DATE_A);

            // Two files, same date, different content → single capture group with two items
            $fileA = $workspace . DIRECTORY_SEPARATOR . 'photo-a.jpg';
            $fileB = $workspace . DIRECTORY_SEPARATOR . 'photo-b.jpg';

            file_put_contents($fileA, 'content-AAA');
            file_put_contents($fileB, 'content-BBB');

            $metadataExtractor->withResponse($fileA, new TemporalMetadata($dateTime, null));
            $metadataExtractor->withResponse($fileB, new TemporalMetadata($dateTime, null));

            $output = $this->runDryRunOutput($workspace, $metadataExtractor);
            $clean  = preg_replace('/<[^>]+>/', '', $output) ?? $output;

            self::assertStringContainsString(
                'Decision Log',
                $clean,
                'Dry-run --list-all output must contain the "Decision Log" header',
            );

            self::assertStringContainsString(
                'Canonical:',
                $clean,
                'Decision log must contain a canonical selection entry',
            );
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies idempotency for cross-directory groups: when a canonical file in the
     * root and a duplicate in a subdirectory are already correctly named from a
     * previous run, re-running produces all no-ops (source == target).
     *
     * This proves that cross-directory group naming is stable across runs —
     * the canonical keeps its clean name in the root and the duplicate keeps
     * its -duplicate-001 suffix in the subdirectory.
     *
     * Source layout (output of a previous run):
     *   2025-01-01_00-02-24-000.jpg                    hashA  -> canonical (root)
     *   backup/2025-01-01_00-02-24-000-duplicate-001.jpg  hashA  -> duplicate (subdir)
     *
     * Uses zero-subsecond timestamp so the subsecond heuristic does not bypass
     * sub-grouping (non-zero subseconds are treated as semantic duplicates).
     *
     * Expected: both files map to themselves (all [O], no renames).
     */
    #[Test]
    public function secondRunOnCrossDirectoryGroupProducesNoOps(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('renamer_crossdir_idem_', true);
        $subDir    = $workspace . DIRECTORY_SEPARATOR . 'backup';

        mkdir($workspace, 0o755);
        mkdir($subDir, 0o755);

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $dateTime          = new DateTimeImmutable(self::DATE_SUBGROUP);

            // Canonical in root — already correctly named
            $canonicalPath = $workspace . DIRECTORY_SEPARATOR . '2025-01-01_00-02-24-000.jpg';
            file_put_contents($canonicalPath, 'same-content');
            $metadataExtractor->withResponse($canonicalPath, new TemporalMetadata($dateTime, null));

            // Duplicate in subdirectory — already correctly named with -duplicate-001
            $duplicatePath = $subDir . DIRECTORY_SEPARATOR . '2025-01-01_00-02-24-000-duplicate-001.jpg';
            file_put_contents($duplicatePath, 'same-content');
            $metadataExtractor->withResponse($duplicatePath, new TemporalMetadata($dateTime, null));

            $mappings = $this->runDryRun($workspace, $metadataExtractor);

            self::assertCount(2, $mappings, 'Both files must appear in the mapping');

            // Canonical in root: source == target (no-op)
            self::assertSame(
                '2025-01-01_00-02-24-000.jpg',
                $mappings['2025-01-01_00-02-24-000.jpg'],
                'Canonical in root must be idempotent (source == target)',
            );

            // Duplicate in subdirectory: source == target (no-op)
            self::assertSame(
                'backup' . DIRECTORY_SEPARATOR . '2025-01-01_00-02-24-000-duplicate-001.jpg',
                $mappings['backup' . DIRECTORY_SEPARATOR . '2025-01-01_00-02-24-000-duplicate-001.jpg'],
                'Duplicate in subdirectory must be idempotent (source == target)',
            );
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    // ---- Validation warnings for unsafe plans ----
    // Circular swap warnings are impractical to trigger with real fixtures because
    // they require two files whose source names are each other's target — a situation
    // the pipeline is designed to prevent. The RenamePlanValidator unit tests
    // (tests/Unit/Service/RenamePlanValidatorTest.php) already cover duplicate-target,
    // case-conflict, and circular-swap detection exhaustively.

    /**
     * Runs the command in dry-run mode and returns the source -> target mapping.
     *
     * @return array<string, string>
     */
    private function runDryRun(string $workspace, StubMetadataExtractor $metadataExtractor): array
    {
        $output = $this->runDryRunOutput($workspace, $metadataExtractor);

        return $this->extractRenameMappings($output, $workspace);
    }

    private function runDryRunOutput(string $workspace, StubMetadataExtractor $metadataExtractor): string
    {
        $output = new BufferedOutput();
        $style  = new SymfonyStyle(new ArrayInput([]), $output);

        $mediaTypeClassifier       = new MediaTypeClassifier();
        $hashSubGroupingService    = new HashSubGroupingService(new SafeHashCalculator(), $style, $mediaTypeClassifier, new StubPerceptualHashCalculator(), new LocalDifferenceAnalyzer(), new ImagickImageLoader(new MediaTypeClassifier()));
        $livePhotoConflictDetector = new LivePhotoConflictDetector($mediaTypeClassifier);

        $captureGroupBuilder = new CaptureGroupBuilder(
            $style,
            $mediaTypeClassifier,
            $livePhotoConflictDetector,
            new LivePhotoPairingService(),
        );
        $subgroupClassifier = new SubgroupClassifier(
            $hashSubGroupingService,
            $mediaTypeClassifier,
            new OrphanLivePhotoVideoReconciler($mediaTypeClassifier, new StubPerceptualHashCalculator(), $style),
            $style,
        );
        $mediaCompatibilityPolicy = new MediaCompatibilityPolicy($mediaTypeClassifier);
        $companionDetector        = new CompanionDetector($mediaCompatibilityPolicy);
        $canonicalScorer          = new CanonicalScorer();
        $roleAssigner             = new RoleAssigner($canonicalScorer, $companionDetector, $mediaCompatibilityPolicy);
        $targetNameResolver       = new TargetNameResolver();
        $collisionResolver        = new CollisionResolver();
        $renamePlanValidator      = new RenamePlanValidator();
        $executionPlanBuilder     = new ExecutionPlanBuilder();

        $pipeline = new AssetGroupPipeline(
            $captureGroupBuilder,
            $subgroupClassifier,
            $roleAssigner,
            $targetNameResolver,
            $collisionResolver,
            $renamePlanValidator,
        );

        $renderer = new RenameOutputRenderer($style);

        $command = new RenameByExifDateCommand(
            new FileSystemService($style, $renderer),
            new DuplicateDetectionService(
                $style,
                $hashSubGroupingService,
                $mediaTypeClassifier,
                $livePhotoConflictDetector,
            ),
            new ExifMetadataProvider($metadataExtractor),
            new StubPerceptualHashCalculator(),
            $hashSubGroupingService,
            $pipeline,
            $canonicalScorer,
            $executionPlanBuilder,
            $renderer,
        );

        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([
            'source'     => $workspace,
            '--dry-run'  => true,
            '--list-all' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        return $output->fetch();
    }

    /**
     * Parses console output into an ordered map of relative source → relative target paths.
     *
     * @return array<string, string>
     */
    private function extractRenameMappings(string $consoleOutput, string $workspace): array
    {
        $clean = preg_replace('/<[^>]+>/', '', $consoleOutput) ?? $consoleOutput;

        $mappings       = [];
        $absolutePrefix = rtrim($workspace, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $relativePrefix = basename(rtrim($workspace, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR;

        if (preg_match_all('/\[(?:O|D|R)]\s+(\S+)\s+→\s+(\S+)/u', $clean, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $source = $this->stripPrefix($match[1], $absolutePrefix, $relativePrefix);
                $target = $this->stripPrefix($match[2], $absolutePrefix, $relativePrefix);

                $mappings[$source] = $target;
            }
        }

        return $mappings;
    }

    private function stripPrefix(string $path, string $absolutePrefix, string $relativePrefix): string
    {
        if (str_starts_with($path, $absolutePrefix)) {
            return substr($path, strlen($absolutePrefix));
        }

        if (str_starts_with($path, $relativePrefix)) {
            return substr($path, strlen($relativePrefix));
        }

        return $path;
    }
}
