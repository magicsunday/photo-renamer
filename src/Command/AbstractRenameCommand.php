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
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\Rename;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;
use function strlen;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
abstract class AbstractRenameCommand extends Command
{
    /**
     * @var string
     */
    private const string DUPLICATE_IDENTIFIER = '-duplicate-';

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

        $this->input           = $input;
        $this->copyFiles       = $input->getOption('copy');
        $this->dryRun          = $input->getOption('dry-run');
        $this->skipDuplicates  = $input->getOption('skip-duplicates');
        $this->sourceDirectory = $input->getArgument('source-directory');
        $this->targetDirectory = $input->getArgument('target-directory');

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

        if ($this->dryRun) {
            $this->io->info('Performing dry run');
        } elseif (
            !$this->io->confirm('This will rename all files in the selected directory. Are you sure?', false)
        ) {
            return self::FAILURE;
        }

        // Remove the trailing directory separator
        $this->sourceDirectory = rtrim($this->sourceDirectory, DIRECTORY_SEPARATOR);

        // If the target directory is empty, use source directory as target
        $this->targetDirectory = $this->targetDirectory !== null
            ? rtrim($this->targetDirectory, DIRECTORY_SEPARATOR)
            : $this->sourceDirectory;

        return $this->executeCommand();
    }

    /**
     * Creates and returns a RecursiveIteratorIterator that is used to find the file for the given command.
     *
     * @return RecursiveIteratorIterator
     */
    protected function createFileIterator(): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->sourceDirectory,
                FilesystemIterator::SKIP_DOTS
            )
        );
    }

    /**
     * Method that allows a child command to customize the execution.
     *
     * @return int
     */
    protected function executeCommand(): int
    {
        try {
            // Process list of all files
            $fileDuplicateCollection = $this->groupFilesByDuplicateIdentifier(
                $this->createFileIterator()
            );

            $this->createDuplicateFilenames($fileDuplicateCollection);
            $this->renameFiles($fileDuplicateCollection);
        } catch (RuntimeException $exception) {
            $this->io->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->io->success('done');

        return self::SUCCESS;
    }

    /**
     * Returns the number of files the iterator matches.
     *
     * @param RecursiveIteratorIterator $iterator
     *
     * @return int
     */
    protected function countFiles(RecursiveIteratorIterator $iterator): int
    {
        $fileCount = 0;

        foreach ($iterator as $ignored) {
            ++$fileCount;
        }

        return $fileCount;
    }

    /**
     * Remove any existing "-duplicate-000" identifier.
     *
     * @param string $filename
     *
     * @return string
     */
    protected function removeDuplicateFileIdentifier(string $filename): string
    {
        return preg_replace(
            '/' . self::DUPLICATE_IDENTIFIER . '\d{3}$/',
            '',
            $filename
        ) ?? $filename;
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
        $this->io->progressStart($this->countFiles($iterator));

        $fileDuplicateCollection = new FileDuplicateCollection();

        /** @var SplFileInfo $sourceFileInfo */
        foreach ($iterator as $sourceFileInfo) {
            // The resulting file object
            $targetFileInfo = $this->getTargetFileInfo($sourceFileInfo);

            if (!($targetFileInfo instanceof SplFileInfo)) {
                continue;
            }

            $duplicateIdentifier = $this->getUniqueDuplicateIdentifier(
                $sourceFileInfo,
                $targetFileInfo
            );

            if ($duplicateIdentifier === false) {
                continue;
            }

            // Create duplicate object storing relevant data
            $fileDuplicate = new FileDuplicate();
            $fileDuplicate
                ->addFile($sourceFileInfo)
                ->setTarget($targetFileInfo);

            if ($fileDuplicateCollection->offsetExists($duplicateIdentifier)) {
                /** @var FileDuplicate $fileDuplicate */
                $fileDuplicate = $fileDuplicateCollection->offsetGet($duplicateIdentifier);
                $fileDuplicate->addFile($sourceFileInfo);
            } else {
                $fileDuplicateCollection->offsetSet($duplicateIdentifier, $fileDuplicate);
            }

            $this->io->progressAdvance();
        }

        $this->io->progressFinish();
        $this->io->newLine();

        return $fileDuplicateCollection;
    }

    /**
     * Returns a new target file object for the given source file object.
     *
     * @param SplFileInfo $sourceFileInfo
     *
     * @return SplFileInfo|null
     */
    protected function getTargetFileInfo(SplFileInfo $sourceFileInfo): ?SplFileInfo
    {
        $targetFilename = $this->getTargetFilename($sourceFileInfo);

        if ($targetFilename === null) {
            return null;
        }

        // Create a new target file object
        return new SplFileInfo(
            $this->getTargetPathname(
                $sourceFileInfo,
                $targetFilename
            )
        );
    }

    /**
     * Returns the new target filename.
     *
     * @param SplFileInfo $sourceFileInfo
     *
     * @return string|null
     */
    abstract protected function getTargetFilename(SplFileInfo $sourceFileInfo): ?string;

    /**
     * Returns a unique identifier for a file. Returns FALSE if no valid identifier could be generated.
     *
     * @param SplFileInfo $sourceFileInfo The source file info
     * @param SplFileInfo $targetFileInfo The target file infos
     *
     * @return string|false
     */
    abstract protected function getUniqueDuplicateIdentifier(
        SplFileInfo $sourceFileInfo,
        SplFileInfo $targetFileInfo,
    ): string|false;

    /**
     * Returns, for the given file object and file name, the name and path of the
     * file in the new destination directory.
     *
     * @param SplFileInfo $sourceFileInfo
     * @param string      $targetFilename
     *
     * @return string
     */
    protected function getTargetPathname(SplFileInfo $sourceFileInfo, string $targetFilename): string
    {
        $targetPathname = $this->targetDirectory . DIRECTORY_SEPARATOR
            . trim(
                // Remove the source directory part from the current file path
                str_replace(
                    $this->sourceDirectory,
                    '',
                    $sourceFileInfo->getPath()
                ),
                DIRECTORY_SEPARATOR
            );

        return rtrim($targetPathname, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $targetFilename;
    }

    /**
     * Creates a consecutive new filename for all duplicate files. The order of the duplicate files
     * is the same as in the input "files" array.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection
     *
     * @return FileDuplicateCollection
     */
    protected function createDuplicateFilenames(FileDuplicateCollection $fileDuplicateCollection): FileDuplicateCollection
    {
        $this->io->text('Create list of duplicate filenames');
        $this->io->newLine();
        $this->io->progressStart($fileDuplicateCollection->count());

        /** @var FileDuplicate $fileDuplicate */
        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getFiles() as $renameSourceFileInfo) {
                $renameTargetFileExtension = $fileDuplicate->getTarget()->getExtension();

                // Modify the target file extension if the file extension from the source should be used.
                // This allows us to rename different file types but with the same name.
                if ($this->useFileExtensionFromSource) {
                    $renameTargetFileExtension = $renameSourceFileInfo->getExtension();
                }

                $targetPathname = $this->getTargetPathname(
                    $renameSourceFileInfo,
                    $fileDuplicate->getTarget()->getBasename('.' . $fileDuplicate->getTarget()->getExtension())
                        . '.' . $renameTargetFileExtension
                );

                $renameTargetFileInfo = new SplFileInfo($targetPathname);

                $fileDuplicate->addRename(
                    new Rename(
                        $renameSourceFileInfo,
                        $renameTargetFileInfo
                    )
                );
            }

            $renames = $fileDuplicate->getRenames();

            // Remove elements where the source already equals the target (these don't need to be copied or moved)
            foreach ($renames as $key => $rename) {
                if ($rename->getSource()->getPathname() === $rename->getTarget()->getPathname()) {
                    unset($renames[$key]);
                    break;
                }
            }

            $fileDuplicate->setRenames(array_values($renames));

            $duplicateCount = 1;

            // Check if the target file already exists in the file system, so we need to adjust
            // the new target name again.
            foreach ($fileDuplicate->getRenames() as $index => $rename) {
                $rename->setTarget(
                    $this->createDuplicateTargetFileInfo(
                        $rename->getSource(),
                        $rename->getTarget(),
                        $duplicateCount,
                        $index === 0
                    )
                );
            }

            $this->io->progressAdvance();
        }

        $this->io->progressFinish();
        $this->io->newLine();

        return $fileDuplicateCollection;
    }

    /**
     * @param SplFileInfo $source
     * @param SplFileInfo $target
     * @param int         $duplicateCount
     * @param bool        $isFirst
     *
     * @return SplFileInfo
     */
    private function createDuplicateTargetFileInfo(
        SplFileInfo $source,
        SplFileInfo $target,
        int &$duplicateCount,
        bool $isFirst = false,
    ): SplFileInfo {
        $duplicateBasename = $target->getBasename('.' . $target->getExtension());

        if ($target->isFile()) {
            if ($source->getPathname() !== $target->getPathname()) {
                return $this->getNewUniqueDuplicateTargetFileInfo(
                    $source,
                    $target,
                    $duplicateBasename,
                    $duplicateCount
                );
            }

            return $this->getNewUniqueDuplicateTargetFileInfo(
                $source,
                $target,
                $duplicateBasename,
                $duplicateCount
            );
        }

        if (!$isFirst) {
            return $this->getNewDuplicateTargetFileInfo(
                $source,
                $target,
                $duplicateBasename,
                $duplicateCount
            );
        }

        return $target;
    }

    /**
     * @param SplFileInfo $source
     * @param SplFileInfo $target
     * @param string      $targetBasename
     * @param int         $duplicateCount
     *
     * @return SplFileInfo
     */
    private function getNewUniqueDuplicateTargetFileInfo(
        SplFileInfo $source,
        SplFileInfo $target,
        string $targetBasename,
        int &$duplicateCount,
    ): SplFileInfo {
        $duplicateFileInfo = $target;

        while ($duplicateFileInfo->isFile()) {
            $duplicateFileInfo = $this->getNewDuplicateTargetFileInfo(
                $source,
                $target,
                $targetBasename,
                $duplicateCount
            );
        }

        return $duplicateFileInfo;
    }

    /**
     * Returns a new file info object with a unique filename.
     *
     * @param SplFileInfo $source
     * @param SplFileInfo $target
     * @param string      $targetBasename
     * @param int         $duplicateCount
     *
     * @return SplFileInfo
     */
    private function getNewDuplicateTargetFileInfo(
        SplFileInfo $source,
        SplFileInfo $target,
        string $targetBasename,
        int &$duplicateCount,
    ): SplFileInfo {
        $newTargetBasename = sprintf(
            '%s' . self::DUPLICATE_IDENTIFIER . '%003d',
            $targetBasename,
            $duplicateCount
        );

        $targetPathname = $this->getTargetPathname(
            $source,
            $newTargetBasename . '.' . $target->getExtension()
        );

        ++$duplicateCount;

        return new SplFileInfo($targetPathname);
    }

    /**
     * Renames all the files.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection
     *
     * @return void
     *
     * @throws RuntimeException
     */
    protected function renameFiles(FileDuplicateCollection $fileDuplicateCollection): void
    {
        $this->io->text(($this->copyFiles ? 'Copying' : 'Renaming') . ' files');
        $this->io->newLine();

        $maxFilenameLength = 0;
        $fileCount         = 0;
        $duplicateCount    = 0;

        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getRenames() as $rename) {
                if (strlen($rename->getSource()->getPathname()) > $maxFilenameLength) {
                    $maxFilenameLength = strlen($rename->getSource()->getPathname());
                }
            }
        }

        /** @var FileDuplicate $fileDuplicate */
        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getRenames() as $rename) {
                $this->io->text(
                    sprintf(
                        '%-' . $maxFilenameLength . 's → %s',
                        $rename->getSource()->getPathname(),
                        $rename->getTarget()->getPathname()
                    )
                );

                if (str_contains($rename->getTarget()->getFilename(), self::DUPLICATE_IDENTIFIER)) {
                    ++$duplicateCount;
                }

                if (
                    $this->skipDuplicates
                    && str_contains($rename->getTarget()->getFilename(), self::DUPLICATE_IDENTIFIER)
                ) {
                    $this->io->text('=> Duplicate! Skip "' . $rename->getSource()->getPathname() . '"');
                    continue;
                }

                ++$fileCount;

                if ($this->dryRun === false) {
                    $this->copyOrMoveFile(
                        $rename->getSource(),
                        $rename->getTarget()
                    );
                }
            }
        }

        $this->io->block($duplicateCount . ' possible duplicates found', 'INFO', 'fg=green');
        $this->io->block($fileCount . ' files renamed', 'INFO', 'fg=green');
    }

    /**
     * Copies or moves a file with its new filename.
     *
     * @param SplFileInfo $sourceFileInfo
     * @param SplFileInfo $targetFileInfo
     *
     * @return void
     */
    private function copyOrMoveFile(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): void
    {
        $copyTargetDirectory = $targetFileInfo->getPath();

        if (
            !file_exists($copyTargetDirectory)
            && !mkdir($copyTargetDirectory, 0755, true)
            && !is_dir($copyTargetDirectory)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Directory "%s" was not created',
                    $copyTargetDirectory
                )
            );
        }

        if (
            $sourceFileInfo->isFile()
            && (!$targetFileInfo->isFile() || $targetFileInfo->isWritable())
        ) {
            if ($this->copyFiles) {
                // Copies a file from source to target with renaming
                copy($sourceFileInfo->getPathname(), $targetFileInfo->getPathname());
            } else {
                // Moves a file from source to target (removes a file at the source)
                rename($sourceFileInfo->getPathname(), $targetFileInfo->getPathname());
            }
        } else {
            throw new RuntimeException(
                sprintf(
                    'Target file "%s" is not writeable',
                    $targetFileInfo->getPathname()
                )
            );
        }
    }
}
