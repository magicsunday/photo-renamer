<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;
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

use function assert;
use function getcwd;
use function is_string;
use function ltrim;
use function preg_match;
use function realpath;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;

/**
 * Base class for all rename commands. Provides shared CLI option parsing,
 * directory path normalization, dry-run confirmation, and the template-method
 * pipeline: scan -> group -> assign filenames -> execute renames. Concrete
 * subclasses supply the rename strategy and duplicate identifier strategy.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
abstract class AbstractRenameCommand extends Command
{
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
     * Absolute path to the target directory. Defaults to the source directory
     * when omitted by the user.
     */
    protected ?string $targetDirectory = null;

    /**
     * When true, the pipeline simulates all operations without touching the file system.
     */
    protected bool $dryRun = false;

    /**
     * When true, files are copied to the target directory instead of moved.
     */
    protected bool $copyFiles = false;

    /**
     * When true, files identified as duplicates are excluded from the copy/move operation.
     */
    protected bool $skipDuplicates = false;

    /**
     * When true, the output lists all files including unchanged originals.
     */
    protected bool $listAll = false;

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
     * Registers the shared CLI arguments (source-directory, target-directory) and
     * options (--dry-run, --copy, --skip-duplicates, --list-all) common to all
     * rename commands.
     */
    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument(
                'source-directory',
                InputArgument::REQUIRED,
                'Source directory with photos.'
            )
            ->addArgument(
                'target-directory',
                InputArgument::OPTIONAL,
                'Target directory with photos. If this argument is omitted, the operation '
                . 'takes place directly in the source directory.'
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Perform a dry run, without actually changing anything.'
            )
            ->addOption(
                'copy',
                'c',
                InputOption::VALUE_NONE,
                'Copies the files to the target directory instead of renaming/moving them directly.'
            )
            ->addOption(
                'skip-duplicates',
                's',
                InputOption::VALUE_NONE,
                'Skip duplicate files from copy/rename action. The files remain unchanged in the source directory.'
            )
            ->addOption(
                'list-all',
                null,
                InputOption::VALUE_NONE,
                'Display all files, including originals and duplicates, in the final output.'
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
        $this->configureDuplicateDetectionService();

        return $this->executeCommand();
    }

    /**
     * Initializes command parameters from input.
     */
    private function initializeCommandParameters(InputInterface $input): void
    {
        $this->copyFiles      = (bool) $input->getOption('copy');
        $this->dryRun         = (bool) $input->getOption('dry-run');
        $this->skipDuplicates = (bool) $input->getOption('skip-duplicates');
        $this->listAll        = (bool) $input->getOption('list-all');

        $sourceDirectory = $input->getArgument('source-directory');
        $targetDirectory = $input->getArgument('target-directory');

        if (is_string($sourceDirectory)) {
            $this->sourceDirectory = $sourceDirectory;
        }

        if (is_string($targetDirectory) || ($targetDirectory === null)) {
            $this->targetDirectory = $targetDirectory;
        }
    }

    /**
     * Validates command options for consistency.
     *
     * @return int SUCCESS if validation passes, FAILURE otherwise
     */
    private function validateCommandOptions(): int
    {
        if (
            $this->copyFiles
            && ($this->targetDirectory === null)
        ) {
            $this->io->error('Copying files requires a target directory');

            return self::FAILURE;
        }

        if ($this->skipDuplicates) {
            $effectiveTargetDirectory = $this->targetDirectory ?? $this->sourceDirectory;

            if ($effectiveTargetDirectory === '') {
                $this->io->error('Skipping duplicate file requires a target directory');

                return self::FAILURE;
            }
        }

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
     * Normalizes source and target directory paths.
     */
    private function normalizeDirectoryPaths(): void
    {
        $this->sourceDirectory = $this->canonicalizeDirectoryPath($this->sourceDirectory);

        if (($this->targetDirectory === null) || ($this->targetDirectory === '')) {
            $this->targetDirectory = $this->sourceDirectory;

            return;
        }

        $this->targetDirectory = $this->canonicalizeDirectoryPath(
            $this->targetDirectory,
            $this->sourceDirectory,
        );
    }

    /**
     * Converts a directory path to an absolute canonical form.
     */
    private function canonicalizeDirectoryPath(string $directory, ?string $fallbackBase = null): string
    {
        if ($directory === '') {
            return $directory;
        }

        if (!$this->isAbsolutePath($directory)) {
            $baseDirectory = getcwd();

            if (!is_string($baseDirectory)) {
                $baseDirectory = $fallbackBase;
            }

            if (is_string($baseDirectory) && ($baseDirectory !== '')) {
                $baseDirectory = $this->trimTrailingDirectorySeparator($baseDirectory);

                $directory = $this->combinePath($baseDirectory, $directory);
            }
        }

        $resolved = realpath($directory);

        if ($resolved !== false) {
            return $this->trimTrailingDirectorySeparator($resolved);
        }

        if (str_contains($directory, '..')) {
            throw new RuntimeException(
                sprintf('Directory "%s" does not exist and contains path traversal components', $directory)
            );
        }

        return $this->trimTrailingDirectorySeparator($directory);
    }

    /**
     * Determines if the provided path is absolute (supports Unix, Windows drive letters, and UNC paths).
     */
    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/')) {
            return true;
        }

        if (str_starts_with($path, '\\')) {
            return true;
        }

        if (preg_match('#^[A-Za-z]:[\\/]#', $path) === 1) {
            return true;
        }

        return preg_match('#^[A-Za-z]:$#', $path) === 1;
    }

    /**
     * Removes trailing directory separators while keeping root semantics intact.
     */
    private function trimTrailingDirectorySeparator(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        if ($this->isWindowsDriveRoot($path)) {
            if (str_contains($path, '\\')) {
                $separator = '\\';
            } elseif (str_contains($path, '/')) {
                $separator = '/';
            } else {
                $separator = DIRECTORY_SEPARATOR;
            }

            return rtrim($path, '/\\') . $separator;
        }

        $trimmed = rtrim($path, '/\\');

        return $trimmed === '' ? DIRECTORY_SEPARATOR : $trimmed;
    }

    /**
     * Combines a base directory with a relative path segment.
     */
    private function combinePath(string $baseDirectory, string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/\\');

        if ($relativePath === '') {
            return $baseDirectory;
        }

        if (str_ends_with($baseDirectory, '/') || str_ends_with($baseDirectory, '\\')) {
            return $baseDirectory . $relativePath;
        }

        $separator = str_contains($baseDirectory, '\\') ? '\\' : DIRECTORY_SEPARATOR;

        return $baseDirectory . $separator . $relativePath;
    }

    /**
     * Detects whether the given path points to a Windows drive root (e.g. "C:\").
     */
    private function isWindowsDriveRoot(string $path): bool
    {
        return preg_match('#^[A-Za-z]:[\\/]?$#', $path) === 1;
    }

    /**
     * Configures the duplicate detection service with source and target directories.
     */
    private function configureDuplicateDetectionService(): void
    {
        assert(is_string($this->targetDirectory));

        $this->duplicateDetectionService
            ->setSourceDirectory($this->sourceDirectory)
            ->setTargetDirectory($this->targetDirectory);
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
            return $this->handleExecutionError($exception);
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
        // Process list of all files
        $iterator = $this->createFileIterator();

        $duplicates = $this->groupFilesByDuplicateIdentifier($iterator);

        $fileDuplicateCollection = $this->createDuplicateFilenames($duplicates);

        $this->fileSystemService
            ->renameFiles(
                $fileDuplicateCollection,
                new RenameOptions(
                    dryRun: $this->dryRun,
                    skipDuplicates: $this->skipDuplicates,
                    copyFiles: $this->copyFiles,
                    listAll: $this->listAll,
                    sourceBaseDirectory: $this->sourceDirectory,
                    targetBaseDirectory: $this->targetDirectory,
                    scannedFiles: $this->duplicateDetectionService->getLastScannedFileCount(),
                    namingCollisions: $this->duplicateDetectionService->getNamingCollisions(),
                ),
            );
    }

    /**
     * Handles execution errors by displaying error message.
     *
     * @param RuntimeException $exception The exception that occurred
     *
     * @return int Always returns FAILURE
     */
    private function handleExecutionError(RuntimeException $exception): int
    {
        $this->io->error($exception->getMessage());

        return self::FAILURE;
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
                duplicateIdentifierStrategy: $this->getDuplicateIdentifierStrategy()
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
            ->setUseFileExtensionFromSource($this->useFileExtensionFromSource)
            ->createDuplicateFilenames($fileDuplicateCollection, $this->skipHashSubGrouping());
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
     * Creates the file iterator for scanning the source directory. Subclasses may
     * override to apply file type filters (e.g. EXIF command filters by image/video extensions).
     *
     * @return RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>>
     */
    protected function createFileIterator(): RecursiveIteratorIterator
    {
        return $this->fileSystemService
            ->createFileIterator($this->sourceDirectory);
    }
}
