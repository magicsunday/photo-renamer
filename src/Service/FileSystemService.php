<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use FilesystemIterator;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;
use function strlen;

/**
 * Service for file system operations.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class FileSystemService implements FileSystemServiceInterface
{
    /**
     * Duplicate identifier pattern.
     */
    public const string DUPLICATE_IDENTIFIER = '-duplicate-';

    /**
     * @var SymfonyStyle
     */
    private readonly SymfonyStyle $io;

    /**
     * Constructor.
     *
     * @param SymfonyStyle $io
     */
    public function __construct(SymfonyStyle $io)
    {
        $this->io = $io;
    }

    public function createFileIterator(
        string $directory,
        ?RecursiveIterator $recursiveIterator = null,
    ): RecursiveIteratorIterator {
        if (!($recursiveIterator instanceof RecursiveIterator)) {
            $recursiveIterator = new RecursiveDirectoryIterator(
                $directory,
                FilesystemIterator::SKIP_DOTS
            );
        }

        return new RecursiveIteratorIterator(
            $recursiveIterator,
            RecursiveIteratorIterator::LEAVES_ONLY
        );
    }

    public function countFiles(RecursiveIteratorIterator $iterator): int
    {
        $fileCount = 0;

        foreach ($iterator as $ignored) {
            ++$fileCount;
        }

        return $fileCount;
    }

    public function renameFiles(
        FileDuplicateCollection $fileDuplicateCollection,
        bool $dryRun = false,
        bool $skipDuplicates = false,
        bool $copyFiles = false,
    ): void {
        $this->io->text(($copyFiles ? 'Copying' : 'Renaming') . ' files');
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
                    $skipDuplicates
                    && str_contains($rename->getTarget()->getFilename(), self::DUPLICATE_IDENTIFIER)
                ) {
                    $this->io->text('=> Duplicate! Skip "' . $rename->getSource()->getPathname() . '"');
                    continue;
                }

                ++$fileCount;

                if ($dryRun === false) {
                    $this->copyOrMoveFile(
                        $rename->getSource(),
                        $rename->getTarget(),
                        $copyFiles
                    );
                }
            }
        }

        $this->io->block($duplicateCount . ' possible duplicates found', 'INFO', 'fg=green');
        $this->io->block($fileCount . ' files renamed', 'INFO', 'fg=green');
    }

    /**
     * Copies or moves a file from source to target.
     *
     * @param SplFileInfo $sourceFileInfo The source file
     * @param SplFileInfo $targetFileInfo The target file
     * @param bool        $copy           Whether to copy the file instead of moving it
     *
     * @throws RuntimeException If the file could not be copied or moved
     */
    protected function copyOrMoveFile(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo, bool $copy = false): void
    {
        $targetDirectory = $targetFileInfo->getPath();

        if (
            !file_exists($targetDirectory)
            && !mkdir($targetDirectory, 0755, true)
            && !is_dir($targetDirectory)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Directory "%s" was not created',
                    $targetDirectory
                )
            );
        }

        if (
            $sourceFileInfo->isFile()
            && (!$targetFileInfo->isFile() || $targetFileInfo->isWritable())
        ) {
            if ($copy) {
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
