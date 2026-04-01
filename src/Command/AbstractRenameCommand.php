<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use FilesystemIterator;
use MagicSunday\Renamer\Command\Concern\ConfiguresMetadataProvider;
use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_filter;
use function array_map;
use function array_values;
use function basename;
use function explode;
use function getcwd;
use function is_dir;
use function is_file;
use function is_string;
use function ltrim;
use function preg_quote;
use function realpath;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;

/**
 * Base class for all rename commands. Provides shared CLI option parsing,
 * directory path normalization, dry-run confirmation, and the template-method
 * pipeline: scan -> group -> assign filenames -> execute in-place renames.
 * Concrete subclasses supply the rename strategy and duplicate identifier strategy.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
abstract class AbstractRenameCommand extends Command
{
    use ConfiguresMetadataProvider;

    protected InputInterface $input;

    protected SymfonyStyle $io;

    /**
     * When true, duplicate targets preserve the source file's original extension
     * instead of inheriting the canonical target's extension. Enabled by the
     * EXIF date command where JPG, HEIC and MOV share the same target basename.
     */
    protected bool $useFileExtensionFromSource = false;

    /**
     * Absolute path to the source directory provided by the user.
     */
    protected string $sourceDirectory = '';

    /**
     * When true, the pipeline simulates all operations without touching the file system.
     */
    protected bool $dryRun = false;

    /**
     * When true, the output lists all files including unchanged originals.
     */
    protected bool $listAll = false;

    /**
     * Maximum allowed date drift in days between source filename and target date.
     * Files exceeding this threshold are tagged as Warning and skipped.
     * Null or 0 disables the check.
     */
    protected ?int $maxDateDrift = null;

    /**
     * When set, only output entries matching these tags are shown (e.g. ['R', 'D']).
     * Null means show all entries (default).
     *
     * @var list<string>|null
     */
    protected ?array $showFilter = null;

    /**
     * When true, a single file was passed instead of a directory.
     */
    protected bool $isSingleFile = false;

    /**
     * @param FileSystemServiceInterface         $fileSystemService         Handles file iteration, counting and rename execution
     * @param DuplicateDetectionServiceInterface $duplicateDetectionService Orchestrates grouping and suffix assignment
     */
    public function __construct(
        protected FileSystemServiceInterface $fileSystemService,
        protected DuplicateDetectionServiceInterface $duplicateDetectionService,
    ) {
        parent::__construct();
    }

    /**
     * Registers the shared CLI arguments (source-directory) and options
     * (--dry-run, --list-all) common to all rename commands.
     */
    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Source directory or single file to process.',
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Perform a dry run, without actually changing anything.'
            )
            ->addOption(
                'list-all',
                null,
                InputOption::VALUE_NONE,
                'Display all files, including originals and duplicates, in the final output.'
            )
            ->addOption(
                'show',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter output to specific entry types (comma-separated: C=content ID conflict, R=renamed, F=fallback, D=duplicate, O=original, W=warning, S=skipped, E=error).'
            )
            ->addOption(
                'timezone',
                null,
                InputOption::VALUE_REQUIRED,
                'Timezone for video files without timezone metadata (e.g. Europe/Berlin). Overrides TIMEZONE env var.'
            )
            ->addOption(
                'max-date-drift',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum allowed date drift in days between source filename and target date. Files exceeding this are skipped. Default: 7.',
            );
    }

    /**
     * Entry point called by Symfony Console. Initializes IO, parses input, validates
     * options, handles dry-run confirmation, normalizes paths and delegates to
     * {@see executeCommand()} for the actual pipeline execution.
     */
    #[Override]
    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title($this->getName() ?? '');

        $this->input = $input;

        $this->initializeCommandParameters($input);

        $validationResult = $this->validateCommandOptions();

        if ($validationResult !== self::SUCCESS) {
            return $validationResult;
        }

        $confirmationResult = $this->handleDryRunConfirmation();

        if ($confirmationResult !== self::SUCCESS) {
            return $confirmationResult;
        }

        $this->normalizeDirectoryPaths();

        return $this->executeCommand();
    }

    /**
     * Initializes command parameters from input.
     */
    private function initializeCommandParameters(InputInterface $input): void
    {
        $this->dryRun  = (bool) $input->getOption('dry-run');
        $this->listAll = (bool) $input->getOption('list-all');

        $this->maxDateDrift = $this->resolveMaxDateDrift($input);

        $showOption = $input->getOption('show');

        if (is_string($showOption)) {
            // Explicit --show filter: use exactly what the user specified
            $this->showFilter = array_map(strtoupper(...), array_map(trim(...), explode(',', $showOption)));
        } elseif ($this->listAll) {
            // --list-all: show everything (including [O])
            $this->showFilter = null;
        } else {
            // Default: show everything except [O] (only changes and problems)
            $this->showFilter = array_values(array_map(
                static fn (OutputEntryTag $tag): string => $tag->letter(),
                array_filter(
                    OutputEntryTag::cases(),
                    static fn (OutputEntryTag $tag): bool => $tag !== OutputEntryTag::Original,
                ),
            ));
        }

        $source = $input->getArgument('source');

        if (is_string($source)) {
            $resolved = realpath($source);

            if ($resolved !== false && is_file($resolved)) {
                $this->isSingleFile    = true;
                $this->sourceDirectory = dirname($resolved);
            } else {
                $this->sourceDirectory = $source;
            }
        }
    }

    /**
     * Validates command options for consistency.
     *
     * @return int SUCCESS if validation passes, FAILURE otherwise
     */
    private function validateCommandOptions(): int
    {
        return self::SUCCESS;
    }

    /**
     * Handles dry run confirmation or user confirmation for file operations.
     *
     * @return int SUCCESS if confirmed, FAILURE otherwise
     */
    private function handleDryRunConfirmation(): int
    {
        if ($this->dryRun) {
            $this->io->text('<fg=cyan>Performing dry run. No files will be changed.</>');
            $this->io->newLine();

            return self::SUCCESS;
        }

        if (!$this->io->confirm('This will rename all files in the selected directory. Are you sure?', false)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Normalizes the source directory path.
     */
    private function normalizeDirectoryPaths(): void
    {
        $this->sourceDirectory = $this->canonicalizeDirectoryPath($this->sourceDirectory);
    }

    /**
     * Converts a directory path to an absolute canonical form.
     */
    private function canonicalizeDirectoryPath(string $directory, ?string $fallbackBase = null): string
    {
        if ($directory === '') {
            return $directory;
        }

        if (!str_starts_with($directory, '/')) {
            $baseDirectory = getcwd();

            if (!is_string($baseDirectory)) {
                $baseDirectory = $fallbackBase;
            }

            if (is_string($baseDirectory) && ($baseDirectory !== '')) {
                $baseDirectory = rtrim($baseDirectory, '/');

                if ($baseDirectory === '') {
                    $baseDirectory = '/';
                }

                $directory = $baseDirectory . '/' . ltrim($directory, '/');
            }
        }

        $resolved = realpath($directory);

        if ($resolved !== false) {
            $trimmed = rtrim($resolved, '/');

            return $trimmed === '' ? '/' : $trimmed;
        }

        if (str_contains($directory, '..')) {
            throw new RuntimeException(
                sprintf('Directory "%s" does not exist and contains path traversal components', $directory)
            );
        }

        $trimmed = rtrim($directory, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }

    /**
     * Template method that runs the rename pipeline (scan, group, assign, execute).
     * Subclasses may override to add pre/post-processing steps (e.g. Live Photo pairing).
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    protected function executeCommand(): int
    {
        try {
            $this->processAndRenameFiles();
        } catch (RuntimeException $exception) {
            $this->io->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->io->success('done');

        return self::SUCCESS;
    }

    /**
     * Executes the full pipeline: creates the file iterator, counts files, groups
     * them by duplicate identifier, assigns duplicate filenames and invokes the
     * file system service to perform the actual rename/copy operations.
     */
    private function processAndRenameFiles(): void
    {
        // Single file mode: create a temp directory with a symlink so the pipeline
        // processes exactly one file while keeping the iterator type consistent.
        $iterator = $this->createFileIterator();

        $duplicates = $this->groupFilesByDuplicateIdentifier($iterator);

        $fileDuplicateCollection = $this->createDuplicateFilenames($duplicates);

        $result = new RenameResult(
            scannedFiles: $this->duplicateDetectionService->getLastScannedFileCount(),
            namingCollisions: $this->duplicateDetectionService->getNamingCollisions(),
            skippedFiles: $this->duplicateDetectionService->getSkippedFiles(),
            fallbackDateFiles: $this->duplicateDetectionService->getFallbackDateFiles(),
            ambiguousTimezoneFiles: $this->duplicateDetectionService->getAmbiguousTimezoneFiles(),
            livePhotoConflictFiles: $this->duplicateDetectionService->getLivePhotoConflictFiles(),
        );

        $this->renderPostScanSummary($result);

        $this->fileSystemService
            ->renameFiles(
                $fileDuplicateCollection,
                new RenameOptions(
                    dryRun: $this->dryRun,
                    listAll: $this->listAll,
                    sourceBaseDirectory: $this->sourceDirectory,
                    maxDateDrift: $this->maxDateDrift,
                ),
                $result,
                $this->showFilter,
            );

        $this->duplicateDetectionService->clearHashCache();
    }

    /**
     * Renders a short post-scan summary showing what the pipeline found.
     */
    protected function renderPostScanSummary(RenameResult $result): void
    {
        $skippedCount  = count($result->skippedFiles);
        $warningCount  = count($result->ambiguousTimezoneFiles);
        $fallbackCount = count($result->fallbackDateFiles);
        $conflictCount = count($result->livePhotoConflictFiles);
        $issueCount    = $skippedCount + $warningCount + $fallbackCount + $conflictCount;

        if ($issueCount > 0) {
            /** @var list<string> $parts */
            $parts = [];

            if ($warningCount > 0) {
                $parts[] = sprintf('%d ambiguous timezone', $warningCount);
            }

            if ($fallbackCount > 0) {
                $parts[] = sprintf('%d fallback date', $fallbackCount);
            }

            if ($skippedCount > 0) {
                $parts[] = sprintf('%d skipped', $skippedCount);
            }

            if ($conflictCount > 0) {
                $parts[] = sprintf('%d LP conflict', $conflictCount);
            }

            $this->io->text(sprintf(
                '<fg=yellow>%d file(s) with issues:</> %s',
                $issueCount,
                implode(', ', $parts),
            ));

            $this->io->newLine();
        }

        $this->io->newLine();
        $this->io->text('Renaming files');
        $this->io->newLine();
    }

    /**
     * Scans all files from the iterator, applies the rename strategy and groups
     * them by the duplicate identifier strategy. Subclasses may override to add
     * additional passes (e.g. Live Photo companion pairing).
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner> $iterator Iterator yielding candidate files
     *
     * @return FileDuplicateCollection Grouped duplicate collection
     */
    protected function groupFilesByDuplicateIdentifier(RecursiveIteratorIterator $iterator): FileDuplicateCollection
    {
        $this->io->text(sprintf('<fg=cyan>Scanning:</> %s', $this->sourceDirectory));

        // Process list of all files
        return $this->duplicateDetectionService
            ->groupFilesByDuplicateIdentifier(
                iterator: $iterator,
                renameStrategy: $this->getTargetFilenameStrategy(),
                duplicateIdentifierStrategy: $this->getDuplicateIdentifierStrategy(),
                sourceDirectory: $this->sourceDirectory,
            );
    }

    /**
     * Assigns sequential "-duplicate-NNN" filenames to all non-canonical files in each
     * group, applying hash sub-grouping when enabled. Preserves the iteration order
     * from the grouping phase.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection Groups produced by the grouping phase
     *
     * @return FileDuplicateCollection Same collection with rename targets populated
     */
    private function createDuplicateFilenames(FileDuplicateCollection $fileDuplicateCollection): FileDuplicateCollection
    {
        $this->io->newLine();
        $this->io->text('<fg=cyan>Resolving duplicates</>');

        return $this->duplicateDetectionService
            ->createDuplicateFilenames(
                $fileDuplicateCollection,
                $this->sourceDirectory,
                $this->useFileExtensionFromSource,
                $this->skipHashSubGrouping(),
            );
    }

    /**
     * Returns whether content-hash sub-grouping should be skipped.
     *
     * Override in subclasses that already group by content hash (e.g. rename:hash).
     */
    protected function skipHashSubGrouping(): bool
    {
        return false;
    }

    /**
     * Returns the rename strategy that computes target filenames from source files.
     * Each concrete command provides its own strategy (EXIF date, pattern, lowercase, etc.).
     */
    abstract protected function getTargetFilenameStrategy(): RenameStrategyInterface;

    /**
     * Returns the duplicate identifier strategy that determines how files are grouped.
     * Each concrete command selects the grouping granularity (basename, filename, pathname, hash).
     */
    abstract protected function getDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface;

    /**
     * Creates the file iterator for scanning the source directory or single file.
     * Subclasses may override to apply file type filters (e.g. EXIF command
     * filters by image/video extensions).
     *
     * @return RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>>
     */
    protected function createFileIterator(): RecursiveIteratorIterator
    {
        if ($this->isSingleFile) {
            $source   = $this->input->getArgument('source');
            $resolved = is_string($source) ? realpath($source) : false;

            if ($resolved === false || !is_file($resolved)) {
                throw new RuntimeException('Source file does not exist');
            }

            $basename = basename($resolved);

            // Use a regex filter on the parent directory that matches only the target file
            return $this->fileSystemService->createFileIterator(
                $this->sourceDirectory,
                new RecursiveRegexFileFilterIterator(
                    new RecursiveDirectoryIterator(
                        $this->sourceDirectory,
                        FilesystemIterator::SKIP_DOTS,
                    ),
                    '/^' . preg_quote($basename, '/') . '$/i',
                ),
            );
        }

        if (!is_dir($this->sourceDirectory)) {
            throw new RuntimeException(
                sprintf('Source directory "%s" does not exist', $this->sourceDirectory)
            );
        }

        return $this->fileSystemService
            ->createFileIterator($this->sourceDirectory);
    }
}
