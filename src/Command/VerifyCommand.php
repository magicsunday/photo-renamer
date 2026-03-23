<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use DateTimeInterface;
use MagicSunday\Renamer\Command\Concern\ConfiguresMetadataProvider;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
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
use function explode;
use function in_array;
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
     * @param MediaTypeClassifierInterface $mediaTypeClassifier  Classifies files as still or video
     * @param FileSystemServiceInterface   $fileSystemService    Provides file iteration
     * @param RenameOutputRenderer         $renderer             Shared output rendering utilities
     */
    public function __construct(
        private readonly ExifMetadataProvider $exifMetadataProvider,
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
                'source-directory',
                InputArgument::REQUIRED,
                'Source directory with photos/videos to analyze.'
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
                'Maximum allowed date drift in days between filename date and metadata date. Default: 30.',
            )
            ->addOption(
                'timezone',
                null,
                InputOption::VALUE_REQUIRED,
                'Timezone for video files without timezone metadata (e.g. Europe/Berlin). Overrides TIMEZONE env var.'
            );
    }

    /**
     * Executes the verify analysis: scans all files, checks metadata, and reports issues.
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getName() ?? '');

        $sourceDirectory = $this->resolveSourceDirectory($input);

        if ($sourceDirectory === null) {
            $io->error('Source directory does not exist.');

            return self::FAILURE;
        }

        $maxDateDrift = $this->resolveMaxDateDrift($input);
        $showFilter   = $this->resolveShowFilter($input);

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

        $files = $this->fileSystemService->collectFiles($sourceDirectory);

        $io->text(sprintf('<fg=cyan>Scanning:</> %s', $sourceDirectory));

        $progressBar = $files !== [] ? $io->createProgressBar(count($files)) : null;
        $progressBar?->setFormat(Constants::PROGRESS_BAR_FORMAT);
        $progressBar?->start();

        foreach ($files as $file) {
            ++$scannedFiles;
            $progressBar?->advance();

            $relativePath = FileHelper::relativizePath($file->getPathname(), $sourceDirectory);
            $extension    = strtolower($file->getExtension());

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

                $categories['nodata'][] = $relativePath;

                continue;
            }

            $hasIssue = false;

            // Check ambiguous timezone and fallback date — but only if the
            // date is not already reliable (hasReliableDateTime handles the
            // "raw matches filename" check centrally).
            if (!$this->exifMetadataProvider->hasReliableDateTime($file)) {
                if ($this->exifMetadataProvider->isAmbiguousTimezone($file)) {
                    $categories['timezone'][] = $relativePath;
                    $hasIssue                 = true;
                }

                if ($this->exifMetadataProvider->isFallbackDateTime($file)) {
                    $categories['fallback'][] = $relativePath;
                    $hasIssue                 = true;
                }
            }

            // Check date drift
            if ($maxDateDrift > 0) {
                $drift = FileHelper::computeDateDriftFromDateTime($file->getPathname(), $captureDateTime);

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
     * Resolves the show filter from the --show option. Accepts both category IDs
     * (timezone, fallback, drift, ...) and tag letter aliases (W, F, S, E).
     *
     * @return list<string>|null Category IDs to display, or null for all
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

            foreach ($files as $file) {
                $io->text(sprintf('  %s', $file));
            }

            $io->newLine();
        }
    }

    /**
     * Renders the summary table with file counts per category.
     *
     * @param SymfonyStyle                $io         Console IO
     * @param int                         $scanned    Total files scanned
     * @param int                         $ok         Files without issues
     * @param array<string, list<string>> $categories Categorized file lists
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
