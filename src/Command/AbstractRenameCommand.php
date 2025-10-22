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
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function getcwd;
use function is_string;
use function ltrim;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
abstract class AbstractRenameCommand extends Command
{
    /**
     * @var InputInterface
     */
    protected InputInterface $input;

    /**
     * @var SymfonyStyle
     */
    protected SymfonyStyle $io;

    /**
     * The iterator used to search for the files.
     *
     * @var RecursiveIteratorIterator
     */
    protected RecursiveIteratorIterator $iterator;

    /**
     * @var FileSystemServiceInterface
     */
    protected FileSystemServiceInterface $fileSystemService;

    /**
     * @var DuplicateDetectionServiceInterface
     */
    protected DuplicateDetectionServiceInterface $duplicateDetectionService;

    /**
     * Set to TRUE to use the file extension from the current processed source file.
     *
     * @var bool
     */
    protected bool $useFileExtensionFromSource = false;

    /**
     * The source directory where the processing should take place.
     *
     * @var string
     */
    protected string $sourceDirectory = '';

    /**
     * The target directory in which the changed files should be stored.
     *
     * @var string|null
     */
    protected ?string $targetDirectory = null;

    /**
     * Set to TRUE to perform a test run without actually changing anything.
     *
     * @var bool
     */
    protected bool $dryRun = false;

    /**
     * Set to TRUE to copy the files to the destination directory instead of moving them.
     *
     * @var bool
     */
    protected bool $copyFiles = false;

    /**
     * Set to TRUE to skip duplicate files when copying/moving.
     *
     * @var bool
     */
    protected bool $skipDuplicates = false;

    /**
     * Set to TRUE to emit a full listing of originals and duplicates.
     *
     * @var bool
     */
    protected bool $listAll = false;

    /**
     * Constructor.
     *
     * @param FileSystemServiceInterface         $fileSystemService
     * @param DuplicateDetectionServiceInterface $duplicateDetectionService
     */
    public function __construct(
        FileSystemServiceInterface $fileSystemService,
        DuplicateDetectionServiceInterface $duplicateDetectionService,
    ) {
        parent::__construct();

        $this->fileSystemService         = $fileSystemService;
        $this->duplicateDetectionService = $duplicateDetectionService;
    }

    /**
     * Configures the current command.
     *
     * @return void
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
     * Executes the current command.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    #[Override]
    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title($this->getName() ?? '');

        $this->input = $input;

        $this->initializeCommandParameters($input, $output);

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
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return void
     */
    private function initializeCommandParameters(InputInterface $input, OutputInterface $output): void
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
            $this->io->info('Performing dry run. No files will be changed.');

            return self::SUCCESS;
        }

        if (!$this->io->confirm('This will rename all files in the selected directory. Are you sure?', false)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Normalizes source and target directory paths.
     *
     * @return void
     */
    private function normalizeDirectoryPaths(): void
    {
        $this->sourceDirectory = $this->canonicalizeDirectoryPath($this->sourceDirectory);

        if ($this->targetDirectory === null || $this->targetDirectory === '') {
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

            if (!is_string($baseDirectory) || $baseDirectory === '') {
                $baseDirectory = $fallbackBase;
            }

            if (is_string($baseDirectory) && $baseDirectory !== '') {
                $baseDirectory = $this->trimTrailingDirectorySeparator($baseDirectory);

                $directory = $this->combinePath($baseDirectory, $directory);
            }
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
            $separator = str_contains($path, '\\') ? '\\' : (str_contains($path, '/') ? '/' : DIRECTORY_SEPARATOR);

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
     *
     * @return void
     */
    private function configureDuplicateDetectionService(): void
    {
        // PHPStan detects $this->targetDirectory as null, even though it is no longer null here.
        $this->duplicateDetectionService
            ->setSourceDirectory($this->sourceDirectory)
            ->setTargetDirectory($this->targetDirectory)
            ->setListAll($this->listAll);
    }

    /**
     * Method that allows a child command to customize the execution.
     *
     * @return int
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
     * Processes files and performs rename/copy operations.
     *
     * @return void
     */
    private function processAndRenameFiles(): void
    {
        // Process list of all files
        $duplicates = $this->groupFilesByDuplicateIdentifier($this->createFileIterator());

        $fileDuplicateCollection = $this->createDuplicateFilenames($duplicates);

        $this->fileSystemService
            ->renameFiles(
                $fileDuplicateCollection,
                $this->dryRun,
                $this->skipDuplicates,
                $this->copyFiles,
                $this->listAll
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
     * Creates a collection of duplicates. Files with the same unique identifier are grouped together.
     *
     * @param RecursiveIteratorIterator $iterator
     *
     * @return FileDuplicateCollection
     */
    protected function groupFilesByDuplicateIdentifier(RecursiveIteratorIterator $iterator): FileDuplicateCollection
    {
        $this->io->text(sprintf('Process files in: %s', $this->sourceDirectory));

        // Process list of all files
        return $this->duplicateDetectionService
            ->groupFilesByDuplicateIdentifier(
                iterator: $iterator,
                renameStrategy: $this->getTargetFilenameProcessor(),
                duplicateIdentifierStrategy: $this->getDuplicateIdentifierStrategy()
            );
    }

    /**
     * Creates a consecutive new filename for all duplicate files. The order of the duplicate files
     * is the same as in the input "files" array.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection
     *
     * @return FileDuplicateCollection
     */
    private function createDuplicateFilenames(FileDuplicateCollection $fileDuplicateCollection): FileDuplicateCollection
    {
        $this->io->text('Create list of duplicate filenames');

        return $this->duplicateDetectionService
            ->setUseFileExtensionFromSource($this->useFileExtensionFromSource)
            ->createDuplicateFilenames($fileDuplicateCollection);
    }

    /**
     * Returns the target filename processor.
     *
     * @return RenameStrategyInterface
     */
    abstract protected function getTargetFilenameProcessor(): RenameStrategyInterface;

    /**
     * Returns the duplicate identifier strategy.
     *
     * @return DuplicateIdentifierStrategyInterface
     */
    abstract protected function getDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface;

    /**
     * Creates and returns a RecursiveIteratorIterator that is used to find the files for the given command.
     *
     * @return RecursiveIteratorIterator
     */
    protected function createFileIterator(): RecursiveIteratorIterator
    {
        return $this->fileSystemService
            ->createFileIterator($this->sourceDirectory);
    }
}
