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
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recursivly detects duplicates in the specified directory matching the same file hash.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class RenameByHashCommand extends AbstractRenameCommand
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
            ->setName('rename:hash')
            ->setDescription(
                'Detects duplicate files matching the same file hash.'
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
        $parentResult = parent::execute($input, $output);

        if ($parentResult === self::FAILURE) {
            return self::FAILURE;
        }

        try {
            $this->processDirectory(
                $input->getOption('dry-run'),
                $input->getOption('copy-files'),
                $input->getOption('skip-duplicates'),
                $input->getArgument('source-directory'),
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
     * @param string      $sourceDirectory
     * @param string|null $targetDirectory
     *
     * @return void
     */
    private function processDirectory(
        bool $dryRun,
        bool $copyFiles,
        bool $skipDuplicates,
        string $sourceDirectory,
        ?string $targetDirectory = null,
    ): void {
        $directoryIterator = new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS);
        $iterator          = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::LEAVES_ONLY);

        $sourceDirectory = rtrim($sourceDirectory, '/');

        // If target directory is empty, use source directory as target
        $targetDirectory = $targetDirectory !== null
            ? rtrim($targetDirectory, '/')
            : $sourceDirectory;

        // Process list of all files
        $fileDuplicateCollection = $this->groupFilesByTargetPathname(
            $iterator,
            $sourceDirectory,
            $targetDirectory
        );

        $this->createDuplicateFilenames(
            $fileDuplicateCollection,
            $sourceDirectory,
            $targetDirectory
        );

        $this->renameFiles(
            $fileDuplicateCollection,
            $dryRun,
            $copyFiles,
            $skipDuplicates
        );
    }

    /**
     * Groups all the files matching the given pattern together by the resulting target file pathname.
     *
     * @param RecursiveIteratorIterator $iterator
     * @param string                    $sourceDirectory
     * @param string                    $targetDirectory
     *
     * @return FileDuplicateCollection
     */
    private function groupFilesByTargetPathname(
        RecursiveIteratorIterator $iterator,
        string $sourceDirectory,
        string $targetDirectory,
    ): FileDuplicateCollection {
        $this->io->text(sprintf('Process files in: %s', $sourceDirectory));
        $this->io->newLine();
        $this->io->progressStart($this->countFiles($iterator));

        $fileDuplicateCollection = new FileDuplicateCollection();

        /** @var SplFileInfo $splFileInfo */
        foreach ($iterator as $splFileInfo) {
            $targetBasename = $this->removeDuplicateIdentifier(
                $splFileInfo->getBasename('.' . $splFileInfo->getExtension())
            );

            $targetFilename = $this->getTargetFilename(
                $targetBasename,
                $splFileInfo
            );

            $targetPathname = $this->getTargetPathname(
                $splFileInfo,
                $targetFilename,
                $sourceDirectory,
                $targetDirectory
            );

            $collectionKey = hash_file('xxh128', $splFileInfo->getPathname());

            if ($collectionKey === false) {
                continue;
            }

            // Create a new target file object
            $targetFileInfo = new SplFileInfo($targetPathname);

            // Create duplicate object storing relevant data
            $fileDuplicate = new FileDuplicate();
            $fileDuplicate
                ->addFile($splFileInfo)
                ->setTarget($targetFileInfo);

            if ($fileDuplicateCollection->offsetExists($collectionKey)) {
                /** @var FileDuplicate $fileDuplicate */
                $fileDuplicate = $fileDuplicateCollection->offsetGet($collectionKey);
                $fileDuplicate->addFile($splFileInfo);
            } else {
                $fileDuplicateCollection->offsetSet($collectionKey, $fileDuplicate);
            }

            $this->io->progressAdvance();
        }

        $this->io->progressFinish();
        $this->io->newLine();

        return $fileDuplicateCollection;
    }

    /**
     * Returns the new target filename.
     *
     * @param string      $targetBasename
     * @param SplFileInfo $splFileInfo
     *
     * @return string
     */
    private function getTargetFilename(
        string $targetBasename,
        SplFileInfo $splFileInfo,
    ): string {
        return $targetBasename . '.' . $splFileInfo->getExtension();
    }
}
