<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Service\FileSystemService;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use Override;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function file_exists;
use function is_dir;
use function is_string;
use function mkdir;
use function realpath;
use function rtrim;
use function sprintf;
use function str_contains;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Finds files with "-duplicate-" in their name and either moves them to
 * a configurable target directory or deletes them. Purely filename-based,
 * no metadata pipeline needed.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class DedupCommand extends Command
{
    /**
     * @param FileSystemServiceInterface $fileSystemService Provides file iteration
     * @param RenameOutputRenderer       $renderer          Shared output rendering utilities
     */
    public function __construct(
        private readonly FileSystemServiceInterface $fileSystemService,
        private readonly RenameOutputRenderer $renderer,
    ) {
        parent::__construct();
    }

    /**
     * Configures the dedup command with its name, description, arguments and options.
     */
    #[Override]
    protected function configure(): void
    {
        $this
            ->setName('rename:dedup')
            ->setDescription('Finds and removes files with "-duplicate-" in their name.')
            ->addArgument(
                'source-directory',
                InputArgument::REQUIRED,
                'Source directory to scan for duplicate files.',
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Preview changes without modifying any files.',
            )
            ->addOption(
                'delete',
                null,
                InputOption::VALUE_NONE,
                'Delete duplicate files instead of moving them.',
            )
            ->addOption(
                'target',
                null,
                InputOption::VALUE_REQUIRED,
                'Target folder name for moved duplicates (relative to source directory).',
                '_duplicates',
            );
    }

    /**
     * Executes the dedup command: scans for duplicate files and moves or deletes them.
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

        $dryRun = (bool) $input->getOption('dry-run');
        $delete = (bool) $input->getOption('delete');
        $target = $input->getOption('target');

        if (!is_string($target)) {
            $target = '_duplicates';
        }

        $files = $this->collectFiles($sourceDirectory);

        $io->text(sprintf('<fg=cyan>Scanning:</> %s', $sourceDirectory));

        $progressBar = $files !== [] ? $io->createProgressBar(count($files)) : null;
        $progressBar?->setFormat(Constants::PROGRESS_BAR_FORMAT);
        $progressBar?->start();

        /** @var list<array{file: SplFileInfo, originalExists: bool, relativePath: string}> $duplicates */
        $duplicates = [];

        foreach ($files as $file) {
            $progressBar?->advance();

            $basename = FileHelper::basenameWithoutExtension($file);

            if (!str_contains($basename, Constants::DUPLICATE_IDENTIFIER)) {
                continue;
            }

            $originalBasename = FileHelper::stripDuplicateSuffix($basename);
            $originalPath     = $file->getPath() . DIRECTORY_SEPARATOR . $originalBasename . '.' . $file->getExtension();
            $relativePath     = FileSystemService::relativizePath($file->getPathname(), $sourceDirectory);

            $duplicates[] = [
                'file'           => $file,
                'originalExists' => file_exists($originalPath),
                'relativePath'   => $relativePath,
            ];
        }

        $progressBar?->finish();
        $io->newLine(2);

        $duplicatesFound  = 0;
        $orphanedCount    = 0;
        $spaceReclaimable = 0;

        foreach ($duplicates as $entry) {
            $file         = $entry['file'];
            $relativePath = $entry['relativePath'];

            if (!$entry['originalExists']) {
                ++$orphanedCount;

                $io->text(sprintf(
                    '<fg=yellow>[!]</> %s <fg=cyan>-></> Original not found (skipped)',
                    $relativePath,
                ));

                continue;
            }

            ++$duplicatesFound;
            $spaceReclaimable += $file->getSize();

            if ($dryRun) {
                if ($delete) {
                    $io->text(sprintf(
                        '<fg=cyan>[D]</> %s <fg=cyan>-></> Would delete',
                        $relativePath,
                    ));
                } else {
                    $targetRelativePath = $target . DIRECTORY_SEPARATOR . $relativePath;

                    $io->text(sprintf(
                        '<fg=cyan>[D]</> %s <fg=cyan>-></> Would move to %s',
                        $relativePath,
                        $targetRelativePath,
                    ));
                }
            } elseif ($delete) {
                @unlink($file->getPathname());

                $io->text(sprintf(
                    '<fg=green>[D]</> %s (deleted)',
                    $relativePath,
                ));
            } else {
                $relativeDir = FileSystemService::relativizePath($file->getPath(), $sourceDirectory);

                // When the file is at the root of the source directory, relativizePath
                // returns the absolute path unchanged. In that case use the target
                // folder directly without appending a subdirectory.
                if ($relativeDir === $file->getPath()) {
                    $targetDir = $sourceDirectory . DIRECTORY_SEPARATOR . $target;
                } else {
                    $targetDir = $sourceDirectory . DIRECTORY_SEPARATOR . $target . DIRECTORY_SEPARATOR . $relativeDir;
                }

                $targetPath = $targetDir . DIRECTORY_SEPARATOR . $file->getBasename();

                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }

                @rename($file->getPathname(), $targetPath);

                $targetRelativePath = $target . DIRECTORY_SEPARATOR . $relativePath;

                $io->text(sprintf(
                    '<fg=green>[D]</> %s <fg=cyan>-></> %s',
                    $relativePath,
                    $targetRelativePath,
                ));
            }
        }

        // Render summary
        $io->newLine();
        $this->renderSummary($io, count($files), $duplicatesFound, $orphanedCount, $spaceReclaimable);

        return self::SUCCESS;
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
     * Formats a byte count as a human-readable string.
     *
     * @param int $bytes Number of bytes
     *
     * @return string Formatted size string (e.g. "1.5 MB")
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $kb = $bytes / 1024;

        if ($kb < 1024) {
            return sprintf('%.1f KB', $kb);
        }

        $mb = $kb / 1024;

        if ($mb < 1024) {
            return sprintf('%.1f MB', $mb);
        }

        $gb = $mb / 1024;

        return sprintf('%.1f GB', $gb);
    }

    /**
     * Renders the summary table with dedup statistics.
     */
    private function renderSummary(
        SymfonyStyle $io,
        int $scannedFiles,
        int $duplicatesFound,
        int $orphanedCount,
        int $spaceReclaimable,
    ): void {
        $io->text('<fg=cyan>Summary</>');
        $io->newLine();

        /** @var list<array{string, string}> $rows */
        $rows = [
            ['Scanned files', (string) $scannedFiles],
            ['Duplicates found', (string) $duplicatesFound],
        ];

        if ($orphanedCount > 0) {
            $rows[] = ['Orphaned (skipped)', (string) $orphanedCount];
        }

        $rows[] = ['Space reclaimable', $this->formatSize($spaceReclaimable)];

        $this->renderer->renderAlignedTable($rows, $io);

        $io->newLine();
    }
}
