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
use MagicSunday\Renamer\Command\Concern\ConfiguresMetadataProvider;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Service\ExiftoolWriter;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use Override;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function getenv;
use function in_array;
use function is_dir;
use function is_string;
use function max;
use function realpath;
use function rtrim;
use function sprintf;
use function strlen;
use function strtolower;

/**
 * Extracts dates from filenames and writes them into EXIF/QuickTime metadata
 * using exiftool. Only touches files with missing or wrong metadata. Supports
 * --dry-run for safe preview.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class WriteDateCommand extends Command
{
    use ConfiguresMetadataProvider;

    /**
     * File extensions recognized as processable media files.
     *
     * @var list<string>
     */
    private const array SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'heic', 'mov', 'mp4'];

    /**
     * @param ExifMetadataProvider         $exifMetadataProvider Metadata provider with caching
     * @param MediaTypeClassifierInterface $mediaTypeClassifier  Classifies files as still or video
     * @param FileSystemServiceInterface   $fileSystemService    Provides file iteration
     * @param ExiftoolWriter               $exiftoolWriter       Writes metadata via exiftool
     */
    public function __construct(
        private readonly ExifMetadataProvider $exifMetadataProvider,
        private readonly MediaTypeClassifierInterface $mediaTypeClassifier,
        private readonly FileSystemServiceInterface $fileSystemService,
        private readonly ExiftoolWriter $exiftoolWriter,
    ) {
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
                'source-directory',
                InputArgument::REQUIRED,
                'Source directory with photos/videos to process.',
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

        $sourceDirectory = $this->resolveSourceDirectory($input);

        if ($sourceDirectory === null) {
            $io->error('Source directory does not exist.');

            return self::FAILURE;
        }

        if (!$this->isExiftoolAvailable()) {
            $io->error('exiftool is not installed or not found in PATH. Please install exiftool first.');

            return self::FAILURE;
        }

        $dryRun       = (bool) $input->getOption('dry-run');
        $maxDateDrift = $this->resolveMaxDateDrift($input);

        $this->configureProviderTimezone($this->exifMetadataProvider, $input);

        $cache = $this->configureProviderCache($this->exifMetadataProvider);

        $scannedFiles   = 0;
        $alreadyCorrect = 0;
        $wouldWrite     = 0;
        $written        = 0;
        $writeFailed    = 0;
        $noDateInName   = 0;
        $readErrors     = 0;

        $files = $this->collectFiles($sourceDirectory);

        $io->text(sprintf('<fg=cyan>Scanning:</> %s', $sourceDirectory));

        $progressBar = $files !== [] ? $io->createProgressBar(count($files)) : null;
        $progressBar?->setFormat(Constants::PROGRESS_BAR_FORMAT);
        $progressBar?->start();

        /** @var list<array{path: string, date: string, reason: string, isVideo: bool, dateTime: DateTimeImmutable}> $pendingWrites */
        $pendingWrites = [];

        foreach ($files as $file) {
            ++$scannedFiles;
            $progressBar?->advance();

            $extension = strtolower($file->getExtension());

            // Skip unsupported file types
            if (!in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
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
            $reason = $this->determineWriteReason(
                $file,
                $captureDateTime,
                $filenameDateTime,
                $maxDateDrift,
            );

            if ($reason === null) {
                ++$alreadyCorrect;

                continue;
            }

            $formattedDate = $filenameDateTime->format('Y:m:d H:i:s');

            $pendingWrites[] = [
                'path'     => $file->getPathname(),
                'date'     => $formattedDate,
                'reason'   => $reason,
                'isVideo'  => $isVideo,
                'dateTime' => $filenameDateTime,
            ];
        }

        $progressBar?->finish();
        $io->newLine(2);

        // Process pending writes
        foreach ($pendingWrites as $entry) {
            $relativePath = FileSystemService::relativizePath($entry['path'], $sourceDirectory);

            if ($dryRun) {
                $targetField = $entry['isVideo'] ? 'QuickTime:CreateDate' : 'DateTimeOriginal';

                $io->text(sprintf(
                    '<fg=yellow>[W]</> %s <fg=cyan>-></> %s: %s',
                    $relativePath,
                    $targetField,
                    $entry['date'],
                ));
                $io->text(sprintf(
                    '      <fg=gray>Reason: %s</>',
                    $entry['reason'],
                ));

                ++$wouldWrite;
            } else {
                $fileInfo = new SplFileInfo($entry['path']);
                $success  = $this->exiftoolWriter->writeDateTime($fileInfo, $entry['dateTime'], $entry['isVideo']);

                if ($success) {
                    $io->text(sprintf(
                        '<fg=green>[W]</> %s <fg=cyan>-></> DateTimeOriginal: %s',
                        $relativePath,
                        $entry['date'],
                    ));
                    $io->text(sprintf(
                        '      <fg=gray>%s</>',
                        $entry['reason'],
                    ));

                    ++$written;
                } else {
                    $io->text(sprintf(
                        '<fg=red>[E]</> %s <fg=cyan>-></> FAILED to write: %s',
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
     * the reason string. Returns null when the metadata is already correct.
     *
     * @param SplFileInfo            $file             File to check
     * @param DateTimeInterface|null $captureDateTime  Current metadata date, or null
     * @param DateTimeImmutable      $filenameDateTime Date extracted from the filename
     * @param int                    $maxDateDrift     Maximum allowed drift in days
     *
     * @return string|null Reason string, or null when no write is needed
     */
    private function determineWriteReason(
        SplFileInfo $file,
        ?DateTimeInterface $captureDateTime,
        DateTimeImmutable $filenameDateTime,
        int $maxDateDrift,
    ): ?string {
        // No capture date at all
        if (!$captureDateTime instanceof DateTimeInterface) {
            return 'no date in metadata';
        }

        // If the metadata date already matches the filename date, no write needed
        // — regardless of fallback/ambiguous status.
        if ($captureDateTime->format('Y-m-d H:i') === $filenameDateTime->format('Y-m-d H:i')) {
            return null;
        }

        // Fallback DateTime only (0x0132)
        if ($this->exifMetadataProvider->isFallbackDateTime($file)) {
            return 'only ModifyDate (0x0132), no DateTimeOriginal';
        }

        // Ambiguous timezone (QuickTime UTC ambiguity)
        if ($this->exifMetadataProvider->isAmbiguousTimezone($file)) {
            return 'QuickTime timestamp without timezone info';
        }

        // Date drift check
        if ($maxDateDrift > 0) {
            $drift = $filenameDateTime->diff($captureDateTime)->days;

            if (($drift !== false) && ($drift > $maxDateDrift)) {
                return sprintf('metadata date differs by %d days', $drift);
            }
        }

        return null;
    }

    /**
     * Checks whether exiftool is available in the system PATH.
     */
    private function isExiftoolAvailable(): bool
    {
        $command = 'which exiftool';
        $result  = @exec($command, $output, $exitCode);

        return $result !== false && $exitCode === 0;
    }

    /**
     * Resolves and validates the source directory path from the input argument.
     *
     * @return string|null Absolute source directory path, or null if invalid
     */
    private function resolveSourceDirectory(InputInterface $input): ?string
    {
        $sourceDirectory = $input->getArgument('source-directory');

        if (!is_string($sourceDirectory)) {
            return null;
        }

        $resolved = realpath($sourceDirectory);

        if (($resolved === false) || !is_dir($resolved)) {
            return null;
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /**
     * Resolves the max date drift threshold from input option or env var.
     * Default is 7 days (more aggressive than verify's 30).
     */
    private function resolveMaxDateDrift(InputInterface $input): int
    {
        $driftOption = $input->getOption('max-date-drift');

        if (is_string($driftOption)) {
            return (int) $driftOption;
        }

        $envDrift = getenv('MAX_DATE_DRIFT');

        return is_string($envDrift) && $envDrift !== '' ? (int) $envDrift : 7;
    }

    /**
     * Collects all regular files from the source directory into a flat list.
     *
     * @return list<SplFileInfo> All files found in the directory tree
     */
    private function collectFiles(string $sourceDirectory): array
    {
        $iterator = $this->fileSystemService->createFileIterator($sourceDirectory);

        /** @var list<SplFileInfo> $files */
        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file;
            }
        }

        return $files;
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

        $maxLabelLength = 0;
        $maxValueLength = 0;

        foreach ($rows as $row) {
            $maxLabelLength = max($maxLabelLength, strlen($row[0]));
            $maxValueLength = max($maxValueLength, strlen($row[1]));
        }

        foreach ($rows as $row) {
            $io->text(sprintf(
                ' %-' . $maxLabelLength . 's  %' . $maxValueLength . 's',
                $row[0],
                $row[1],
            ));
        }

        $io->newLine();
    }
}
