<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MagicSunday\Renamer\Command\Concern\ConfiguresMetadataProvider;
use MagicSunday\Renamer\Command\Concern\ResolvesSourcePath;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Service\DateDriftAnalyzer;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use Override;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_map;
use function count;
use function dirname;
use function escapeshellarg;
use function explode;
use function filesize;
use function in_array;
use function is_file;
use function is_string;
use function sort;
use function sprintf;
use function strtolower;
use function strtoupper;

/**
 * Read-only analysis command that scans photo/video directories and reports
 * metadata problems. Does not modify any files. Categorizes issues into:
 * ambiguous timezone, fallback dates, date drift, missing Live Photo companions,
 * metadata read errors, no metadata, and unrecognized file types.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class VerifyCommand extends Command
{
    use ConfiguresMetadataProvider;
    use ResolvesSourcePath;

    /**
     * Category definitions mapping internal IDs to display labels.
     *
     * @var array<string, string>
     */
    private const array CATEGORY_LABELS = [
        'timezone'  => 'Ambiguous timezone',
        'fallback'  => 'No DateTimeOriginal',
        'drift'     => 'Date drift',
        'livephoto' => 'Missing Live Photo companion',
        'error'     => 'Metadata read errors',
        'nodata'    => 'No metadata',
        'filetype'  => 'Unrecognized file types',
    ];

    /**
     * @param ExifMetadataProvider         $exifMetadataProvider Metadata provider with caching
     * @param DateDriftAnalyzer            $dateDriftAnalyzer    Calculates filename-versus-metadata drift consistently
     * @param MediaTypeClassifierInterface $mediaTypeClassifier  Classifies files as still or video
     * @param FileSystemServiceInterface   $fileSystemService    Provides file iteration
     * @param RenameOutputRenderer         $renderer             Shared output rendering utilities
     */
    public function __construct(
        private readonly ExifMetadataProvider $exifMetadataProvider,
        private readonly DateDriftAnalyzer $dateDriftAnalyzer,
        private readonly MediaTypeClassifierInterface $mediaTypeClassifier,
        private readonly FileSystemServiceInterface $fileSystemService,
        private readonly RenameOutputRenderer $renderer,
    ) {
        parent::__construct();
    }

    /**
     * Configures the verify command with its name, description, arguments and options.
     */
    #[Override]
    protected function configure(): void
    {
        $this
            ->setName('rename:verify')
            ->setDescription('Analyzes photo/video collections for metadata problems.')
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Source directory or single file to analyze.',
            )
            ->addOption(
                'show',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter output to specific categories (comma-separated: timezone, fallback, drift, livephoto, error, nodata, filetype). Also accepts tag letters: W=timezone, F=fallback, S=nodata, E=error.'
            )
            ->addOption(
                'max-date-drift',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum allowed date drift in days between filename date and metadata date. Default: 7.',
            )
            ->addOption(
                'timezone',
                null,
                InputOption::VALUE_REQUIRED,
                'Timezone for video files without timezone metadata (e.g. Europe/Berlin). Overrides TIMEZONE env var.'
            )
            ->addOption(
                'detail',
                null,
                InputOption::VALUE_NONE,
                'Show actionable fix suggestions with metadata details for each problematic file.',
            );
    }

    /**
     * Executes the verification process: scans all files, checks metadata,
     * and categorizes findings into groups like "Duplicate Content", "Missing Date",
     * or "Ambiguous Timezone" for reporting.
     *
     * @param InputInterface  $input  The input interface.
     * @param OutputInterface $output The output interface.
     *
     * @return int The exit code (0 for success, non-zero for failure).
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getName() ?? '');

        $source = $this->resolveSourcePath($input);

        if ($source === null) {
            $io->error('Source path does not exist.');

            return self::FAILURE;
        }

        $isSingleFile    = is_file($source);
        $sourceDirectory = $isSingleFile ? dirname($source) : $source;

        $maxDateDrift       = $this->resolveMaxDateDrift($input);
        $showFilter         = $this->resolveShowFilter($input);
        $detail             = (bool) $input->getOption('detail');
        $configuredTimezone = $this->resolveTimezone($input);

        $this->configureProviderTimezone($this->exifMetadataProvider, $input);

        $cache = $this->configureProviderCache($this->exifMetadataProvider);

        /** @var array<string, list<string>> $categories */
        $categories = [
            'timezone'  => [],
            'fallback'  => [],
            'drift'     => [],
            'livephoto' => [],
            'error'     => [],
            'nodata'    => [],
            'filetype'  => [],
        ];

        /**
         * Content identifier map: directory => { contentId => { pathname, isStill } }.
         *
         * @var array<string, array<string, list<array{pathname: string, isStill: bool}>>> $contentIdMap
         */
        $contentIdMap = [];

        $scannedFiles = 0;
        $okCount      = 0;

        $files = $isSingleFile
            ? [new SplFileInfo($source)]
            : $this->fileSystemService->collectFiles($sourceDirectory);

        $io->text(sprintf('<fg=cyan>Scanning:</> %s', $source));

        $progressBar = $files !== [] ? $io->createProgressBar(count($files)) : null;
        $progressBar?->setFormat(Constants::PROGRESS_BAR_FORMAT);
        $progressBar?->start();

        foreach ($files as $file) {
            ++$scannedFiles;
            $progressBar?->advance();

            $relativePath = FileHelper::relativizePath($file->getPathname(), $sourceDirectory);
            $extension    = strtolower($file->getExtension());

            // Closure for detail-aware category entries (avoids ternary duplication).
            $absolutePath = $file->getPathname();
            $entry        = fn (string $cat, ?DateTimeInterface $dt = null): string => $detail
                ? $this->formatDetailEntry($relativePath, $absolutePath, $cat, $dt, $configuredTimezone)
                : $relativePath;

            // Check for unrecognized file type
            if (!in_array($extension, Constants::SUPPORTED_MEDIA_EXTENSIONS, true)) {
                $categories['filetype'][] = $relativePath;

                continue;
            }

            // Try to extract metadata
            try {
                $captureDateTime = $this->exifMetadataProvider->getCaptureDateTime($file);
            } catch (ExifMetadataReadException) {
                $categories['error'][] = $relativePath;

                continue;
            }

            // No metadata at all
            if (!$captureDateTime instanceof DateTimeInterface) {
                // Still check for content identifier (LP video without date)
                $contentId = $this->exifMetadataProvider->getContentIdentifier($file);

                if (is_string($contentId)) {
                    $this->addToContentIdMap($contentIdMap, $file, $contentId);
                }

                $categories['nodata'][] = $entry('nodata');

                continue;
            }

            $hasIssue = false;

            // Check ambiguous timezone and fallback date — but only if the
            // date is not already reliable (hasReliableDateTime handles the
            // "raw matches filename" check centrally).
            if (!$this->exifMetadataProvider->hasReliableDateTime($file)) {
                if ($this->exifMetadataProvider->isAmbiguousTimezone($file)) {
                    $categories['timezone'][] = $entry('timezone', $captureDateTime);
                    $hasIssue                 = true;
                }

                if ($this->exifMetadataProvider->isFallbackDateTime($file)) {
                    $categories['fallback'][] = $entry('fallback', $captureDateTime);
                    $hasIssue                 = true;
                }
            }

            // Check date drift
            if ($maxDateDrift > 0) {
                $drift = $this->dateDriftAnalyzer->calculateFilenameDateOnlyDriftInDays($file, $captureDateTime);

                if (($drift !== null) && ($drift > $maxDateDrift)) {
                    $categories['drift'][] = $relativePath;
                    $hasIssue              = true;
                }
            }

            // Collect content identifier for LP check
            $contentId = $this->exifMetadataProvider->getContentIdentifier($file);

            if (is_string($contentId)) {
                $this->addToContentIdMap($contentIdMap, $file, $contentId);
            }

            if (!$hasIssue) {
                ++$okCount;
            }
        }

        $progressBar?->finish();
        $io->newLine(2);

        // Check LP completeness per directory
        foreach ($contentIdMap as $dirFiles) {
            foreach ($dirFiles as $contentIdFiles) {
                $hasStill = false;
                $hasVideo = false;

                foreach ($contentIdFiles as $entry) {
                    if ($entry['isStill']) {
                        $hasStill = true;
                    } else {
                        $hasVideo = true;
                    }
                }

                if ($hasStill && $hasVideo) {
                    continue;
                }

                foreach ($contentIdFiles as $entry) {
                    $relativePath = FileHelper::relativizePath($entry['pathname'], $sourceDirectory);

                    if ($entry['isStill']) {
                        $categories['livephoto'][] = $relativePath . ' → no paired MOV';
                    } else {
                        $categories['livephoto'][] = $relativePath . ' → no paired JPG/HEIC';
                    }
                }
            }
        }

        // Flush metadata cache
        $cache->flush();
        $this->exifMetadataProvider->clearCache();

        // Post-scan summary before detailed listing
        $totalIssues = 0;

        foreach ($categories as $categoryFiles) {
            $totalIssues += count($categoryFiles);
        }

        if ($totalIssues > 0) {
            $io->text(sprintf(
                '<fg=cyan>Found %d issue(s) in %d scanned file(s):</>',
                $totalIssues,
                $scannedFiles,
            ));

            foreach (self::CATEGORY_LABELS as $categoryId => $label) {
                $cnt = count($categories[$categoryId]);

                if ($cnt > 0) {
                    $io->text(sprintf('  %d %s', $cnt, $label));
                }
            }

            $io->newLine();
        } elseif ($scannedFiles > 0) {
            $io->text('<fg=green>All files OK — no metadata issues found.</>');
            $io->newLine();
        }

        // Render output
        $this->renderCategories($io, $categories, $showFilter);
        $this->renderSummary($io, $scannedFiles, $okCount, $categories);

        return self::SUCCESS;
    }

    /**
     * Tag letter aliases mapping single-character shortcuts to category IDs.
     * Allows using the same letters as rename:exif's --show option.
     *
     * @var array<string, string>
     */
    private const array TAG_ALIASES = [
        'W' => 'timezone',
        'F' => 'fallback',
        'S' => 'nodata',
        'E' => 'error',
    ];

    /**
     * Resolves and parses the 'show' filter option.
     *
     * Accepts both category IDs (timezone, fallback, drift, ...) and
     * tag letter aliases (W, F, S, E).
     *
     * @param InputInterface $input The input interface carrying the 'show' option.
     *
     * @return list<string>|null A list of upper-cased filter tags, or null to show all.
     */
    private function resolveShowFilter(InputInterface $input): ?array
    {
        $showOption = $input->getOption('show');

        if (!is_string($showOption)) {
            return null;
        }

        $tokens = array_map(trim(...), explode(',', $showOption));

        return array_map(
            static fn (string $token): string => self::TAG_ALIASES[strtoupper($token)] ?? strtolower($token),
            $tokens,
        );
    }

    /**
     * Adds a file's content identifier to the per-directory content ID map.
     *
     * @param array<string, array<string, list<array{pathname: string, isStill: bool}>>> $contentIdMap
     */
    private function addToContentIdMap(array &$contentIdMap, SplFileInfo $file, string $contentId): void
    {
        $directory = dirname($file->getPathname());
        $isStill   = $this->mediaTypeClassifier->isLivePhotoStill($file);

        $contentIdMap[$directory][$contentId][] = [
            'pathname' => $file->getPathname(),
            'isStill'  => $isStill,
        ];
    }

    /**
     * Formats a detail entry with problem description and fix suggestion.
     */
    private function formatDetailEntry(
        string $relativePath,
        string $absolutePath,
        string $category,
        ?DateTimeInterface $captureDateTime,
        ?DateTimeZone $configuredTimezone = null,
    ): string {
        $fileSize    = filesize($absolutePath);
        $sizeLabel   = ($fileSize !== false) ? FileHelper::formatSize($fileSize) : '?';
        $lines       = [sprintf('%s <fg=gray>(%s)</>', $relativePath, $sizeLabel)];
        $escapedPath = escapeshellarg($absolutePath);

        $tzFlag = ($configuredTimezone instanceof DateTimeZone)
            ? '--timezone=' . $configuredTimezone->getName()
            : '--timezone=<TZ>';

        // Problem description per category
        $problem = match ($category) {
            'timezone' => '     <fg=yellow>Problem:</>    Ambiguous timezone — QuickTime UTC without offset',
            'fallback' => '     <fg=yellow>Problem:</>    Only ModifyDate (0x0132) — no DateTimeOriginal or CreateDate',
            'nodata'   => '     <fg=yellow>Problem:</>    No capture date found (no DateTimeOriginal, CreateDate, or ModifyDate)',
            default    => null,
        };

        if ($problem !== null) {
            $lines[] = $problem;
        }

        // Show what metadata IS present
        if ($captureDateTime instanceof DateTimeInterface) {
            $label   = ($category === 'timezone') ? 'CreateDate (UTC)' : 'ModifyDate';
            $lines[] = sprintf('     <fg=gray>Metadata:</>   %s = %s', $label, $captureDateTime->format('Y:m:d H:i:s'));
        } else {
            $lines[] = '     <fg=gray>Metadata:</>   (none)';
        }

        // Check if filename contains a date that write-date could use
        $filenameDateTime = FileHelper::extractDateTimeFromPath($absolutePath);

        if ($filenameDateTime instanceof DateTimeImmutable) {
            $lines[] = sprintf('     <fg=gray>Recovery:</>   date from filename: %s', $filenameDateTime->format('Y-m-d H:i:s'));
        } elseif ($category === 'nodata') {
            $lines[] = '     <fg=gray>Recovery:</>   no date in filename — rename file first';
        }

        $suggestion = match ($category) {
            'timezone' => sprintf(
                '     <fg=green>Fix:</>        rename:write-date --reason=timezone %s %s',
                $tzFlag,
                $escapedPath,
            ),
            'fallback' => sprintf(
                '     <fg=green>Fix:</>        rename:write-date --reason=fallback %s',
                $escapedPath,
            ),
            'nodata' => ($filenameDateTime instanceof DateTimeImmutable)
                ? sprintf('     <fg=green>Fix:</>        rename:write-date --reason=nodata %s', $escapedPath)
                : sprintf('     <fg=green>Fix:</>        Rename to date-based name, then: rename:write-date --reason=nodata %s', $escapedPath),
            default => null,
        };

        if ($suggestion !== null) {
            $lines[] = $suggestion;
        }

        return implode("\n", $lines);
    }

    /**
     * Renders the categorized file lists.
     *
     * @param SymfonyStyle                $io         Console IO
     * @param array<string, list<string>> $categories Categorized file lists
     * @param list<string>|null           $showFilter Categories to display, or null for all
     */
    private function renderCategories(SymfonyStyle $io, array $categories, ?array $showFilter): void
    {
        foreach (self::CATEGORY_LABELS as $categoryId => $label) {
            if (($showFilter !== null) && (!in_array($categoryId, $showFilter, true))) {
                continue;
            }

            $files = $categories[$categoryId];

            if ($files === []) {
                continue;
            }

            sort($files);

            $io->text(sprintf('<fg=cyan>%s</> (%d files):', $label, count($files)));

            $isDetail = str_contains($files[0], "\n");

            foreach ($files as $file) {
                $io->text(sprintf('  %s', $file));

                if ($isDetail) {
                    $io->newLine();
                }
            }

            $io->newLine();
        }
    }

    /**
     * Renders a summary of the verification results.
     *
     * Displays total scanned files, number of files with no issues (OK),
     * and a breakdown of findings by category in a table format.
     *
     * @param SymfonyStyle                $io         The SymfonyStyle IO instance.
     * @param int                         $scanned    Total number of files scanned.
     * @param int                         $ok         Number of files with no identified issues.
     * @param array<string, list<string>> $categories Map of category names to lists of formatted detail entries.
     */
    private function renderSummary(SymfonyStyle $io, int $scanned, int $ok, array $categories): void
    {
        /** @var list<array{string, string}> $rows */
        $rows = [
            ['Scanned files', (string) $scanned],
            ['OK', (string) $ok],
        ];

        foreach (self::CATEGORY_LABELS as $categoryId => $label) {
            $count = count($categories[$categoryId]);

            if ($count > 0) {
                $rows[] = [$label, (string) $count];
            }
        }

        $this->renderer->renderSummarySection($rows, $io);
    }
}
