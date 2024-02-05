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
use MagicSunday\Renamer\Command\FilterIterator\FilenameFilterIterator;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function in_array;
use function strlen;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class RenameByPatternCommand extends BaseRenameCommand
{
    /**
     * Configures the current command.
     *
     * @return void
     */
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('rename:pattern')
            ->setDescription('Renames files by pattern.');

        $this
            ->addOption(
                'pattern',
                'p',
                InputOption::VALUE_REQUIRED,
                'The pattern used to search for files',
                '/^(.+)(jpeg)$/'
            )
            ->addOption(
                'replacement',
                'r',
                InputOption::VALUE_REQUIRED,
                'The pattern used to replace the matches results',
                '\$1jpg'
            )
            ->addOption(
                'skip-duplicates',
                null,
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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        if ($input->getOption('replacement') === null) {
            $this->io->error('A valid replacement value is required');

            return self::FAILURE;
        }

        /** @var string $sourceDirectory */
        $sourceDirectory = $input->getArgument('source-directory');

        $this->io->note(
            sprintf('Process files in: %s', $sourceDirectory)
        );

        try {
            $this->processDirectory(
                $input->getOption('dry-run'),
                $input->getOption('copy-files'),
                $input->getOption('skip-duplicates'),
                $input->getOption('pattern'),
                $input->getOption('replacement'),
                $sourceDirectory,
                $input->getArgument('target-directory')
            );
        } catch (RuntimeException $exception) {
            $this->io->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->io->success('done');

        return self::SUCCESS;
    }

    /**
     * @param bool        $dryRun
     * @param bool        $copyFiles
     * @param bool        $skipDuplicates
     * @param string      $pattern
     * @param string      $replacement
     * @param string      $sourceDirectory
     * @param string|null $targetDirectory
     *
     * @return void
     */
    public function processDirectory(
        bool $dryRun,
        bool $copyFiles,
        bool $skipDuplicates,
        string $pattern,
        string $replacement,
        string $sourceDirectory,
        ?string $targetDirectory = null
    ): void {
        $directoryIterator      = new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS);
        $filenameFilterIterator = new FilenameFilterIterator($directoryIterator, $pattern);
        $iterator               = new RecursiveIteratorIterator($filenameFilterIterator, RecursiveIteratorIterator::LEAVES_ONLY);

        $fileDuplicateCollection = new FileDuplicateCollection();

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            $this->io->text('Process: ' . $fileInfo->getPathname());

            $targetBasename = $this->removeDuplicateIdentifier(
                $fileInfo->getBasename('.' . $fileInfo->getExtension())
            );

            // Perform regular expression replacement
            $targetFilename = preg_replace(
                $pattern,
                $replacement,
                $targetBasename . '.' . $fileInfo->getExtension()
            );

            // Create a new target file object
            $targetFileInfo = new SplFileInfo($fileInfo->getPath() . '/' . $targetFilename);

            if ($fileDuplicateCollection->offsetExists($targetFileInfo->getPathname())) {
                /** @var FileDuplicate $file */
                $file = $fileDuplicateCollection->offsetGet($targetFileInfo->getPathname());
            } else {
                $file = new FileDuplicate();
                $file
                    ->setPath($fileInfo->getPath())
                    ->setBasename($targetFileInfo->getBasename('.' . $targetFileInfo->getExtension()))
                    ->setExtension($fileInfo->getExtension());

                $fileDuplicateCollection->offsetSet($targetFileInfo->getPathname(), $file);
            }

            // Append all files with the same target filename into one array
            $file->addFile($fileInfo);
        }

        $this->createDuplicateBasename($fileDuplicateCollection);

        $targetDirectory = $targetDirectory !== null
            ? trim($targetDirectory, '/')
            : $targetDirectory;

        $this->renameFiles(
            $fileDuplicateCollection,
            $dryRun,
            $copyFiles,
            $skipDuplicates,
            $targetDirectory
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
    protected function createDuplicateBasename(
        FileDuplicateCollection $fileDuplicateCollection
    ): FileDuplicateCollection {
        $this->io->note('Create list of duplicate files');

        /** @var FileDuplicate $fileGroupData */
        foreach ($fileDuplicateCollection as $fileGroupData) {
            $fileCount = count($fileGroupData->getFiles());

            // Handle possible duplicate files, after renaming
            for ($file = 0; $file < $fileCount; ++$file) {
                $duplicateFileName = $fileGroupData->getBasename();
                $duplicateCount    = 0;

                while (in_array($duplicateFileName, $fileGroupData->getNew(), true)) {
                    ++$duplicateCount;

                    $duplicateFileName = sprintf(
                        '%s-duplicate-%003d',
                        $fileGroupData->getBasename(),
                        $duplicateCount
                    );
                }

                $fileGroupData->addNew($duplicateFileName);
            }
        }

        return $fileDuplicateCollection;
    }

    /**
     * Renames all the files.
     *
     * @param FileDuplicateCollection $fileDuplicateCollection
     * @param bool                    $dryRun
     * @param bool                    $copyFiles
     * @param bool                    $skipDuplicates
     * @param string|null             $targetDirectory
     *
     * @return void
     *
     * @throws RuntimeException
     */
    protected function renameFiles(
        FileDuplicateCollection $fileDuplicateCollection,
        bool $dryRun,
        bool $copyFiles,
        bool $skipDuplicates,
        ?string $targetDirectory = null
    ): void {
        $this->io->note('Start renaming files to new filenames');

        $maxFilenameLength = 0;
        $fileCount         = 0;
        $duplicateCount    = 0;

        foreach ($fileDuplicateCollection as $fileGroupData) {
            foreach ($fileGroupData->getFiles() as $fileInfo) {
                if (strlen($fileInfo->getFilename()) > $maxFilenameLength) {
                    $maxFilenameLength = strlen($fileInfo->getFilename());
                }
            }
        }

        /** @var FileDuplicate $fileGroupData */
        foreach ($fileDuplicateCollection as $fileGroupData) {
            foreach ($fileGroupData->getFiles() as $key => $fileInfo) {
                $newFilename = $fileGroupData->getNew()[$key] . '.' . $fileGroupData->getExtension();

                if ($targetDirectory !== null) {
                    $newPathname = $targetDirectory . '/' . $fileGroupData->getPath() . '/' . $newFilename;
                } else {
                    $newPathname = $fileInfo->getPath() . '/' . $newFilename;
                }

                if (file_exists($newPathname)) {
                    continue;
                }

                $this->io->text(
                    sprintf(
                        'Rename %-' . $maxFilenameLength . 's to %s',
                        $fileInfo->getFilename(),
                        $newPathname
                    )
                );

                if (str_contains($newFilename, '-duplicate-')) {
                    ++$duplicateCount;
                }

                if (
                    $skipDuplicates
                    && str_contains($newFilename, '-duplicate-')
                ) {
                    $this->io->text('=> Duplicate! Skip "' . $fileInfo->getPathname() . '"');
                    continue;
                }

                ++$fileCount;

                if ($dryRun === false) {
                    $this->renameFile($newPathname, $fileInfo, $copyFiles);
                }
            }
        }

        $this->io->info($duplicateCount . ' possible duplicates found');
        $this->io->info($fileCount . ' files renamed');
    }
}
