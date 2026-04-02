<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use Closure;
use MagicSunday\Renamer\Command\Concern\ConfiguresMetadataProvider;
use MagicSunday\Renamer\Command\Concern\ResolvesSourcePath;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Model\LinkConfig;
use MagicSunday\Renamer\Service\ExiftoolWriter;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\WriteDate\WriteDateCandidateAnalyzer;
use MagicSunday\Renamer\Service\WriteDate\WriteDatePendingWrite;
use MagicSunday\Renamer\Service\WriteDate\WriteDateReasonCatalog;
use Override;
use RuntimeException;
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
use function exec;
use function explode;
use function function_exists;
use function is_file;
use function is_string;
use function mb_strlen;
use function sprintf;
use function str_repeat;
use function strtolower;
use function trim;

/**
 * Extracts dates from filenames and writes them into EXIF/QuickTime metadata
 * using exiftool. Only touches files with missing or wrong metadata. Supports
 * --dry-run for safe preview.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class WriteDateCommand extends Command
{
    use ConfiguresMetadataProvider;
    use ResolvesSourcePath;

    /**
     * Callable that checks whether exiftool is available. Injectable for testing.
     *
     * @var Closure(): bool
     */
    private readonly Closure $exiftoolAvailabilityCheck;

    /**
     * @param ExifMetadataProvider       $exifMetadataProvider       Metadata provider with caching
     * @param FileSystemServiceInterface $fileSystemService          Provides file iteration
     * @param ExiftoolWriter             $exiftoolWriter             Writes metadata via exiftool
     * @param RenameOutputRenderer       $renderer                   Shared output rendering utilities
     * @param WriteDateCandidateAnalyzer $writeDateCandidateAnalyzer Scans files and produces pending metadata writes
     * @param (Closure(): bool)|null     $exiftoolAvailabilityCheck  Overrides the default exiftool check (for testing)
     */
    public function __construct(
        private readonly ExifMetadataProvider $exifMetadataProvider,
        private readonly FileSystemServiceInterface $fileSystemService,
        private readonly ExiftoolWriter $exiftoolWriter,
        private readonly RenameOutputRenderer $renderer,
        private readonly WriteDateCandidateAnalyzer $writeDateCandidateAnalyzer,
        ?Closure $exiftoolAvailabilityCheck = null,
    ) {
        $this->exiftoolAvailabilityCheck = $exiftoolAvailabilityCheck ?? static function (): bool {
            if (!function_exists('exec')) {
                return false;
            }

            exec('which exiftool', $output, $exitCode);

            return $exitCode === 0;
        };

        parent::__construct();
    }

    /**
     * Configures the command with its name, description, arguments and options.
     */
    #[Override]
    protected function configure(): void
    {
        $this
            ->setName('rename:write-date')
            ->setDescription('Writes date metadata from filenames into EXIF/QuickTime tags via exiftool.')
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Source directory or single file to process.',
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Preview changes without modifying any files.',
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
                'Timezone for video files without timezone metadata (e.g. Europe/Berlin). Overrides TIMEZONE env var.',
            )
            ->addOption(
                'reason',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter by write reason (comma-separated: nodata, fallback, timezone, drift). Default: all reasons.',
            )
            ->addOption(
                'local-as-utc',
                null,
                InputOption::VALUE_NONE,
                'Treat QuickTime CreateDate as local time (not real UTC). Adds timezone offset without converting. Use for non-Apple cameras that store local time as "UTC".',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Overwrite existing metadata even when it is already reliable. Use to correct previously wrong writes.',
            );
    }

    /**
     * Executes the date writing process: scans files, extracts capture dates
     * from filenames, and writes them to EXIF/QuickTime tags using exiftool.
     *
     * @param InputInterface  $input  The input interface.
     * @param OutputInterface $output The output interface.
     *
     * @return int The exit code (0 for success, non-zero for failure).
     *
     * @throws RuntimeException If the source does not exist or exiftool is missing.
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

        if (!$this->isExiftoolAvailable()) {
            $io->error('exiftool is not installed or not found in PATH. Please install exiftool first.');

            return self::FAILURE;
        }

        $dryRun       = (bool) $input->getOption('dry-run');
        $maxDateDrift = $this->resolveMaxDateDrift($input);
        $reasonFilter = $this->resolveReasonFilter($input);

        $this->configureProviderTimezone($this->exifMetadataProvider, $input);

        $cache = $this->configureProviderCache($this->exifMetadataProvider);

        $scannedFiles     = 0;
        $alreadyCorrect   = 0;
        $wouldWrite       = 0;
        $written          = 0;
        $writeFailed      = 0;
        $noDateInName     = 0;
        $readErrors       = 0;
        $unsupportedWrite = 0;

        $files = $isSingleFile
            ? [new SplFileInfo($source)]
            : $this->fileSystemService->collectFiles($sourceDirectory);

        $io->text(sprintf('<fg=cyan>Scanning:</> %s', $source));

        $progressBar = $files !== [] ? $io->createProgressBar(count($files)) : null;
        $progressBar?->setFormat(Constants::PROGRESS_BAR_FORMAT);
        $progressBar?->start();

        $scanResult = $this->writeDateCandidateAnalyzer->scan(
            $files,
            $maxDateDrift,
            $reasonFilter,
            (bool) $input->getOption('force'),
            (bool) $input->getOption('local-as-utc'),
            $this->resolveTimezone($input),
            static function () use ($progressBar): void {
                $progressBar?->advance();
            },
        );

        $progressBar?->finish();
        $io->newLine(2);

        $scannedFiles     = $scanResult->scannedFiles;
        $alreadyCorrect   = $scanResult->alreadyCorrect;
        $noDateInName     = $scanResult->noDateInName;
        $readErrors       = $scanResult->readErrors;
        $unsupportedWrite = $scanResult->unsupportedWrite;
        /** @var list<WriteDatePendingWrite> $pendingWrites */
        $pendingWrites = $scanResult->pendingWrites;

        // Post-scan summary before listing individual entries
        if ($pendingWrites !== []) {
            $reasonCounts = [];

            foreach ($pendingWrites as $entry) {
                $rk                = $entry->reasonKey;
                $reasonCounts[$rk] = ($reasonCounts[$rk] ?? 0) + 1;
            }

            $io->text(sprintf(
                '<fg=cyan>Found %d file(s) needing metadata repair:</>',
                count($pendingWrites),
            ));

            foreach ($reasonCounts as $reason => $cnt) {
                $label = WriteDateReasonCatalog::formatLabel($reason);
                $io->text(sprintf('  %d %s <fg=gray>(%s)</>', $cnt, $cnt === 1 ? 'file' : 'files', $label));
            }

            $io->newLine();
        } elseif ($scannedFiles > 0) {
            $io->text('<fg=green>All files have correct metadata — nothing to do.</>');
            $io->newLine();
        }

        // Compute max path length for aligned output
        $maxPathLength = 0;

        foreach ($pendingWrites as $entry) {
            $relativePath  = FileHelper::relativizePath($entry->path, $sourceDirectory);
            $maxPathLength = max($maxPathLength, mb_strlen($relativePath));
        }

        // Safety confirmation for non-dry-run (Principle 9)
        if (!$dryRun && ($pendingWrites !== []) && !$io->confirm('This will modify metadata in ' . count($pendingWrites) . ' file(s). Are you sure?', false)) {
            $io->text('<fg=yellow>Aborted.</>');

            return self::SUCCESS;
        }

        $linkConfig = LinkConfig::fromEnv();

        // Process pending writes
        foreach ($pendingWrites as $entry) {
            $relativePath = FileHelper::relativizePath($entry->path, $sourceDirectory);
            $padding      = str_repeat(' ', $maxPathLength - mb_strlen($relativePath));
            $linkedPath   = FileHelper::linkifyPath($relativePath, $relativePath, $sourceDirectory, $linkConfig, 'yellow');
            $targetField  = $entry->isVideo ? 'QuickTime:CreateDate' : 'DateTimeOriginal';

            if ($dryRun) {
                $this->renderWriteEntry($io, '<fg=yellow>[W]</>', $linkedPath, $padding, $targetField . ': ' . $entry->writeDateTime->format('Y:m:d H:i:s'), $entry->reasonKey, $entry->reasonLabel);
                ++$wouldWrite;
            } else {
                $fileInfo = new SplFileInfo($entry->path);
                $success  = $this->exiftoolWriter->writeDateTime($fileInfo, $entry->writeDateTime, $entry->isVideo, $entry->preserveCreateDate);

                if ($success) {
                    $this->renderWriteEntry($io, '<fg=green>[W]</>', $linkedPath, $padding, $targetField . ': ' . $entry->writeDateTime->format('Y:m:d H:i:s'), $entry->reasonKey, $entry->reasonLabel);
                    ++$written;
                } else {
                    $this->renderWriteEntry($io, '<fg=red>[E]</>', $linkedPath, $padding, 'FAILED to write: ' . $entry->writeDateTime->format('Y:m:d H:i:s'));
                    ++$writeFailed;
                }
            }
        }

        // Flush metadata cache
        $cache->flush();
        $this->exifMetadataProvider->clearCache();

        // Render summary
        $io->newLine();
        $this->renderSummary($io, $scannedFiles, $alreadyCorrect, $wouldWrite, $written, $writeFailed, $noDateInName, $readErrors, $unsupportedWrite, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Renders a single write-date output entry with aligned formatting.
     */
    private function renderWriteEntry(
        SymfonyStyle $io,
        string $tag,
        string $linkedPath,
        string $padding,
        string $detail,
        ?string $reasonKey = null,
        ?string $reasonLabel = null,
    ): void {
        $io->text(sprintf(' %s %s' . $padding . ' <fg=cyan>→</> %s', $tag, $linkedPath, $detail));

        if ($reasonKey !== null) {
            $io->text(sprintf('      <fg=gray>[%s] %s</>', $reasonKey, $reasonLabel ?? ''));
        }
    }

    /**
     * Resolves and parses the 'reason' filter option into a list of reason keys.
     *
     * @param InputInterface $input The input interface carrying the 'reason' option.
     *
     * @return list<string>|null A list of lowercase reason keys to include, or null for all.
     */
    private function resolveReasonFilter(InputInterface $input): ?array
    {
        $option = $input->getOption('reason');

        if (!is_string($option)) {
            return null;
        }

        return array_map(
            static fn (string $token): string => strtolower(trim($token)),
            explode(',', $option),
        );
    }

    /**
     * Checks whether exiftool is available in the system PATH.
     */
    private function isExiftoolAvailable(): bool
    {
        return ($this->exiftoolAvailabilityCheck)();
    }

    /**
     * Renders the summary table with file counts.
     */
    private function renderSummary(
        SymfonyStyle $io,
        int $scannedFiles,
        int $alreadyCorrect,
        int $wouldWrite,
        int $written,
        int $writeFailed,
        int $noDateInName,
        int $readErrors,
        int $unsupportedWrite,
        bool $dryRun,
    ): void {
        /** @var list<array{string, string}> $rows */
        $rows = [
            ['Scanned files', (string) $scannedFiles],
            ['Already correct', (string) $alreadyCorrect],
        ];

        if ($dryRun) {
            $rows[] = ['Would write', (string) $wouldWrite];
        } else {
            if ($written > 0) {
                $rows[] = ['Written', (string) $written];
            }

            if ($writeFailed > 0) {
                $rows[] = ['Write failed', (string) $writeFailed];
            }
        }

        if ($noDateInName > 0) {
            $rows[] = ['No date in name', (string) $noDateInName];
        }

        if ($unsupportedWrite > 0) {
            $rows[] = ['Unsupported write', (string) $unsupportedWrite];
        }

        if ($readErrors > 0) {
            $rows[] = ['Read errors', (string) $readErrors];
        }

        $this->renderer->renderSummarySection($rows, $io);
    }
}
