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
use FilesystemIterator;
use MagicSunday\Renamer\Command\RenameByExifDateCommand;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Service\DuplicateDetectionService;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\HashSubGroupingService;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingService;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

use function array_filter;
use function array_keys;
use function array_search;
use function array_values;
use function assert;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function rtrim;
use function str_starts_with;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

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
final class RenameByExifDateCommandTest extends TestCase
{
    private const string DATE_A = '2025-01-01T00:02:20.016+00:00';

    private const string DATE_B = '2025-01-01T00:02:21.345+00:00';

    private const string DATE_C = '2025-01-01T00:02:22.000+00:00';

    private const string DATE_D = '2025-01-01T00:02:23.000+00:00';

    /**
     * Comprehensive rename mapping covering:
     *
     * - True duplicates (same hash, same date)
     * - Hash sub-grouping (different hash, same date → sequential -NNN)
     * - Live Photo pairing (JPG + MOV with same content ID)
     * - Live Photo companion inherits sub-group number
     * - Subdirectory duplicates (parent dir first)
     * - Unique files (single file per date)
     * - Mixed extensions (.jpg/.JPG) preserve source extension
     * - Already-suffixed files get renumbered correctly
     *
     * Source layout:
     *   1.jpg       hash:123  dateA  LP-1    → canonical jpg  (Live Photo)
     *   2.jpg       hash:123  dateA  —       → duplicate of 1 (same hash, no LP)
     *   3.jpg       hash:456  dateB  —       → unique
     *   4.jpg       hash:789  dateC  —       → unique
     *   sub/1.jpg   hash:456  dateB  —       → duplicate of 3 (subdirectory)
     *   a.jpg       hash:234  dateD  —       → canonical for dateD
     *   A.jpg       hash:234  dateD  —       → duplicate of a (same hash)
     *   A.JPG       hash:123  dateA  —       → duplicate of 1 (same hash, uppercase ext)
     *   1-dup.jpg   hash:123  dateA  —       → duplicate of 1 (already suffixed)
     *   1.mov       hash:abc  dateA  LP-1    → companion mov (Live Photo)
     *   mov.mov     hash:abc  —     LP-1    → duplicate mov (paired by content ID)
     *   B.jpg       hash:cde  dateA  LP-B    → hash sub-group 002 (Live Photo)
     *   B.mov       hash:fgh  —     LP-B    → companion inherits sub-group 002
     */
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
     * - Subdirectory: nested file gets its relative path preserved with duplicate suffix
     * - Parent-before-child ordering: parent dir file becomes canonical before nested one
     * - No nested -duplicate--duplicate- patterns in any target name
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
                'sub/1.jpg'           => ['hash-456', self::DATE_B, null],
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
                'LP-1 duplicate MOV gets -duplicate-001',
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

            // ---- Subdirectory duplicate ----
            self::assertSame(
                'sub' . DIRECTORY_SEPARATOR . '2025-01-01_00-02-21-345-duplicate-001.jpg',
                $mappings['sub' . DIRECTORY_SEPARATOR . '1.jpg'],
                'Subdirectory duplicate gets suffix',
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
            $this->removeDirectory($workspace);
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
                static fn (string $t): bool => $t !== '2025-01-01_00-02-20-016.jpg',
            );

            foreach ($suffixedTargets as $target) {
                self::assertMatchesRegularExpression(
                    '/2025-01-01_00-02-20-016-duplicate-\d{3}\.jpg/',
                    $target,
                    'Non-canonical files keep duplicate suffix',
                );
            }
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    /**
     * Runs the command in dry-run mode and returns the source → target mapping.
     *
     * @return array<string, string>
     */
    private function runDryRun(string $workspace, StubMetadataExtractor $metadataExtractor): array
    {
        $output = new BufferedOutput();
        $style  = new SymfonyStyle(new ArrayInput([]), $output);

        $command = new RenameByExifDateCommand(
            new FileSystemService($style),
            new DuplicateDetectionService($style, new HashSubGroupingService(new SafeHashCalculator(), $style)),
            new ExifMetadataProvider($metadataExtractor),
            new LivePhotoPairingService(),
        );

        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([
            'source-directory' => $workspace,
            '--dry-run'        => true,
            '--list-all'       => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        return $this->extractRenameMappings($output->fetch(), $workspace);
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

        if (preg_match_all('/\[(?:O|D|R)]\s+(\S+)\s+→\s+(\S+)/', $clean, $matches, PREG_SET_ORDER) > 0) {
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

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            assert($item instanceof SplFileInfo);

            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
