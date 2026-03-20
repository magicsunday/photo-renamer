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
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Service\ExiftoolWriter;
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
use function getenv;
use function in_array;
use function is_file;
use function is_string;
use function realpath;
use function sprintf;
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

    /**
     * Reason key for files with no metadata date at all.
     */
    private const string REASON_NODATA = 'nodata';

    /**
     * Reason key for files using only ModifyDate (0x0132) as fallback.
     */
    private const string REASON_FALLBACK = 'fallback';

    /**
     * Reason key for QuickTime files with ambiguous UTC timestamps.
     */
    private const string REASON_TIMEZONE = 'timezone';

    /**
     * Reason key for files whose metadata date differs significantly from filename date.
     */
    private const string REASON_DRIFT = 'drift';

    /**
     * Maps reason keys to human-readable labels for output.
     *
     * @var array<string, string>
     */
    private const array REASON_LABELS = [
        self::REASON_NODATA   => 'no date in metadata',
        self::REASON_FALLBACK => 'only ModifyDate (0x0132), no DateTimeOriginal',
        self::REASON_TIMEZONE => 'QuickTime timestamp without timezone info',
        self::REASON_DRIFT    => 'metadata date differs by %d days',
    ];

    /**
     * Callable that checks whether exiftool is available. Injectable for testing.
     *
     * @var callable(): bool
     */
    private readonly mixed $exiftoolAvailabilityCheck;

    /**
     * @param ExifMetadataProvider         $exifMetadataProvider      Metadata provider with caching
     * @param MediaTypeClassifierInterface $mediaTypeClassifier       Classifies files as still or video
     * @param FileSystemServiceInterface   $fileSystemService         Provides file iteration
     * @param ExiftoolWriter               $exiftoolWriter            Writes metadata via exiftool
     * @param RenameOutputRenderer         $renderer                  Shared output rendering utilities
     * @param (callable(): bool)|null      $exiftoolAvailabilityCheck Overrides the default exiftool check (for testing)
     */
    public function __construct(
        private readonly ExifMetadataProvider $exifMetadataProvider,
        private readonly MediaTypeClassifierInterface $mediaTypeClassifier,
        private readonly FileSystemServiceInterface $fileSystemService,
        private readonly ExiftoolWriter $exiftoolWriter,
        private readonly RenameOutputRenderer $renderer,
        ?callable $exiftoolAvailabilityCheck = null,
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
            );
    }

    /**
     * Executes the write-date command: scans files, determines which need metadata
     * correction, and writes dates via exiftool.
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getName() ?? '');

        $source = $this->resolveSource($input);

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
        $maxDateDrift = $this->resolveMaxDateDrift($input, 7);
        $reasonFilter = $this->resolveReasonFilter($input);

        $this->configureProviderTimezone($this->exifMetadataProvider, $input);

        $cache = $this->configureProviderCache($this->exifMetadataProvider);

        $scannedFiles   = 0;
        $alreadyCorrect = 0;
        $wouldWrite     = 0;
        $written        = 0;
        $writeFailed    = 0;
        $noDateInName   = 0;
        $readErrors     = 0;

        $files = $isSingleFile
            ? [new SplFileInfo($source)]
            : $this->fileSystemService->collectFiles($sourceDirectory);

        $io->text(sprintf('<fg=cyan>Scanning:</> %s', $source));

        $progressBar = $files !== [] ? $io->createProgressBar(count($files)) : null;
        $progressBar?->setFormat(Constants::PROGRESS_BAR_FORMAT);
        $progressBar?->start();

        /** @var list<array{path: string, date: string, reasonKey: string, reason: string, isVideo: bool, dateTime: DateTimeImmutable, preserveCreateDate: bool}> $pendingWrites */
        $pendingWrites = [];

        foreach ($files as $file) {
            ++$scannedFiles;
            $progressBar?->advance();

            $extension = strtolower($file->getExtension());

            // Skip unsupported file types
            if (!in_array($extension, Constants::SUPPORTED_MEDIA_EXTENSIONS, true)) {
                continue;
            }

            // Extract date+time from filename
            $filenameDateTime = FileHelper::extractDateTimeFromPath($file->getPathname());

            if (!$filenameDateTime instanceof DateTimeImmutable) {
                ++$noDateInName;

                continue;
            }

            // Read current metadata
            try {
                $captureDateTime = $this->exifMetadataProvider->getCaptureDateTime($file);
            } catch (ExifMetadataReadException) {
                ++$readErrors;

                continue;
            }

            $isVideo = !$this->mediaTypeClassifier->isLivePhotoStill($file);

            // Determine if write is needed
            [$reasonKey, $reasonLabel] = $this->determineWriteReason(
                $file,
                $captureDateTime,
                $filenameDateTime,
                $maxDateDrift,
            );

            if ($reasonKey === null) {
                ++$alreadyCorrect;

                continue;
            }

            // Apply reason filter
            if (($reasonFilter !== null) && !in_array($reasonKey, $reasonFilter, true)) {
                ++$alreadyCorrect;

                continue;
            }

            // For timezone reason: use the raw (unconverted) metadata time and stamp
            // it with the configured timezone. Non-Apple cameras store local time as
            // "UTC" in QuickTime containers, so the raw value IS the local time.
            if ($reasonKey === self::REASON_TIMEZONE) {
                $rawDateTime = $this->exifMetadataProvider->getRawCaptureDateTime($file);

                if ($rawDateTime instanceof DateTimeInterface) {
                    $parsed = DateTimeImmutable::createFromFormat(
                        'Y-m-d H:i:s',
                        $rawDateTime->format('Y-m-d H:i:s'),
                        $this->resolveTimezone($input),
                    );

                    $writeDateTime = $parsed instanceof DateTimeImmutable ? $parsed : $filenameDateTime;
                } else {
                    $writeDateTime = $filenameDateTime;
                }
            } else {
                $writeDateTime = $filenameDateTime;
            }

            $pendingWrites[] = [
                'path'               => $file->getPathname(),
                'date'               => $writeDateTime->format('Y:m:d H:i:s'),
                'reasonKey'          => $reasonKey,
                'reason'             => $reasonLabel,
                'isVideo'            => $isVideo,
                'dateTime'           => $writeDateTime,
                'preserveCreateDate' => $reasonKey === self::REASON_TIMEZONE,
            ];
        }

        $progressBar?->finish();
        $io->newLine(2);

        // Process pending writes
        foreach ($pendingWrites as $entry) {
            $relativePath = FileHelper::relativizePath($entry['path'], $sourceDirectory);

            if ($dryRun) {
                $targetField = $entry['isVideo'] ? 'QuickTime:CreateDate' : 'DateTimeOriginal';

                $io->text(sprintf(
                    '<fg=yellow>[W]</> %s <fg=cyan>→</> %s: %s',
                    $relativePath,
                    $targetField,
                    $entry['date'],
                ));
                $io->text(sprintf(
                    '      <fg=gray>Reason [%s]: %s</>',
                    $entry['reasonKey'],
                    $entry['reason'],
                ));

                ++$wouldWrite;
            } else {
                $fileInfo = new SplFileInfo($entry['path']);
                $success  = $this->exiftoolWriter->writeDateTime($fileInfo, $entry['dateTime'], $entry['isVideo'], $entry['preserveCreateDate']);

                if ($success) {
                    $targetField = $entry['isVideo'] ? 'QuickTime:CreateDate' : 'DateTimeOriginal';

                    $io->text(sprintf(
                        '<fg=green>[W]</> %s <fg=cyan>→</> %s: %s',
                        $relativePath,
                        $targetField,
                        $entry['date'],
                    ));
                    $io->text(sprintf(
                        '      <fg=gray>[%s] %s</>',
                        $entry['reasonKey'],
                        $entry['reason'],
                    ));

                    ++$written;
                } else {
                    $io->text(sprintf(
                        '<fg=red>[E]</> %s <fg=cyan>→</> FAILED to write: %s',
                        $relativePath,
                        $entry['date'],
                    ));

                    ++$writeFailed;
                }
            }
        }

        // Flush metadata cache
        $cache->flush();
        $this->exifMetadataProvider->clearCache();

        // Render summary
        $io->newLine();
        $this->renderSummary($io, $scannedFiles, $alreadyCorrect, $wouldWrite, $written, $writeFailed, $noDateInName, $readErrors, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Determines whether metadata needs to be written for the given file and returns
     * a reason key + label pair. Returns [null, null] when the metadata is already correct.
     *
     * @param SplFileInfo            $file             File to check
     * @param DateTimeInterface|null $captureDateTime  Current metadata date, or null
     * @param DateTimeImmutable      $filenameDateTime Date extracted from the filename
     * @param int                    $maxDateDrift     Maximum allowed drift in days
     *
     * @return array{string|null, string|null} Reason key and label, or [null, null] when no write is needed
     */
    private function determineWriteReason(
        SplFileInfo $file,
        ?DateTimeInterface $captureDateTime,
        DateTimeImmutable $filenameDateTime,
        int $maxDateDrift,
    ): array {
        // No capture date at all
        if (!$captureDateTime instanceof DateTimeInterface) {
            return [self::REASON_NODATA, self::REASON_LABELS[self::REASON_NODATA]];
        }

        // Date drift check — always runs, even for reliable dates. A large drift
        // indicates the metadata was written incorrectly (e.g. re-encoded file).
        if ($maxDateDrift > 0) {
            $drift = $filenameDateTime->diff($captureDateTime)->days;

            if (($drift !== false) && ($drift > $maxDateDrift)) {
                return [self::REASON_DRIFT, sprintf(self::REASON_LABELS[self::REASON_DRIFT], $drift)];
            }
        }

        // If the date is reliable (no issues, or raw matches filename) → no write needed.
        if ($this->exifMetadataProvider->hasReliableDateTime($file)) {
            return [null, null];
        }

        // Fallback DateTime only (0x0132)
        if ($this->exifMetadataProvider->isFallbackDateTime($file)) {
            return [self::REASON_FALLBACK, self::REASON_LABELS[self::REASON_FALLBACK]];
        }

        // Ambiguous timezone (QuickTime UTC ambiguity)
        if ($this->exifMetadataProvider->isAmbiguousTimezone($file)) {
            return [self::REASON_TIMEZONE, self::REASON_LABELS[self::REASON_TIMEZONE]];
        }

        return [null, null];
    }

    /**
     * Resolves the --reason filter option into a list of reason keys.
     *
     * @return list<string>|null Reason keys to include, or null for all
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
     * Resolves the source path from input. Accepts both files and directories.
     */
    private function resolveSource(InputInterface $input): ?string
    {
        $source = $input->getArgument('source');

        if (!is_string($source)) {
            return null;
        }

        $resolved = realpath($source);

        if ($resolved === false) {
            return null;
        }

        return $resolved;
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
        bool $dryRun,
    ): void {
        $io->text('<fg=cyan>Summary</>');
        $io->newLine();

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

        if ($readErrors > 0) {
            $rows[] = ['Read errors', (string) $readErrors];
        }

        $this->renderer->renderAlignedTable($rows, $io);

        $io->newLine();
    }

    /**
     * Resolves the configured timezone from --timezone option or TIMEZONE env var.
     * Returns null when no timezone is configured.
     */
    private function resolveTimezone(InputInterface $input): ?DateTimeZone
    {
        $timezone = $input->getOption('timezone');

        if (!is_string($timezone)) {
            $envTimezone = getenv('TIMEZONE');
            $timezone    = is_string($envTimezone) && ($envTimezone !== '') ? $envTimezone : null;
        }

        if (!is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return null;
        }

        return new DateTimeZone($timezone);
    }
}
