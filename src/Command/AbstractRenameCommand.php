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
use MagicSunday\Renamer\Service\DuplicateDetectionService;
use MagicSunday\Renamer\Service\FileSystemService;
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

use function sprintf;

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
     * @var FileSystemService
     */
    protected FileSystemService $fileSystemService;

    /**
     * @var DuplicateDetectionService
     */
    protected DuplicateDetectionService $duplicateDetectionService;

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
    protected string $sourceDirectory;

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
     * Constructor.
     *
     * @param FileSystemService         $fileSystemService
     * @param DuplicateDetectionService $duplicateDetectionService
     */
    public function __construct(
        FileSystemService $fileSystemService,
        DuplicateDetectionService $duplicateDetectionService,
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
        $this->copyFiles       = $input->getOption('copy');
        $this->dryRun          = $input->getOption('dry-run');
        $this->skipDuplicates  = $input->getOption('skip-duplicates');
        $this->sourceDirectory = $input->getArgument('source-directory');
        $this->targetDirectory = $input->getArgument('target-directory');
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

        if (
            $this->skipDuplicates
            && ($this->targetDirectory === null)
        ) {
            $this->io->error('Skipping duplicate file requires a target directory');

            return self::FAILURE;
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
        // Remove the trailing directory separator
        $this->sourceDirectory = rtrim($this->sourceDirectory, DIRECTORY_SEPARATOR);

        // If the target directory is empty, use source directory as target
        $this->targetDirectory = $this->targetDirectory !== null
            ? rtrim($this->targetDirectory, DIRECTORY_SEPARATOR)
            : $this->sourceDirectory;
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
            ->setTargetDirectory($this->targetDirectory);
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
        $fileDuplicateCollection = $this->createDuplicateFilenames(
            $this->groupFilesByDuplicateIdentifier($this->createFileIterator())
        );

        $this->fileSystemService
            ->renameFiles(
                $fileDuplicateCollection,
                $this->dryRun,
                $this->skipDuplicates,
                $this->copyFiles
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
        $this->io->newLine();

        // Process list of all files
        return $this->duplicateDetectionService
            ->groupFilesByDuplicateIdentifier(
                iterator: $this->createFileIterator(),
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
        $this->io->newLine();

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
