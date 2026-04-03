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
use MagicSunday\Renamer\Helper\PathHelper;
use MagicSunday\Renamer\Service\Dedup\DedupOriginalMatcher;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\Output\SummaryRow;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use Override;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

use function array_filter;
use function count;
use function dirname;
use function is_file;
use function is_string;
use function realpath;
use function sprintf;
use function str_contains;

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
     * Constructor.
     *
     * @param FileSystemServiceInterface $fileSystemService    Service to handle file system operations like iteration
     * @param DedupOriginalMatcher       $dedupOriginalMatcher Service that resolves actionable originals for duplicate files
     * @param RenameOutputRenderer       $renderer             Service to render output in a consistent format
     * @param Filesystem                 $filesystem           Symfony Filesystem component for file operations
     */
    public function __construct(
        private readonly FileSystemServiceInterface $fileSystemService,
        private readonly DedupOriginalMatcher $dedupOriginalMatcher,
        private readonly RenameOutputRenderer $renderer,
        private readonly Filesystem $filesystem,
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
                'source',
                InputArgument::REQUIRED,
                'Source directory or single file to scan for duplicates.',
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
     * Executes the dedup command.
     *
     * Scans for files containing the duplicate suffix in their name and
     * either moves them to a target directory or deletes them based on
     * the provided options.
     *
     * @param InputInterface  $input  The input interface.
     * @param OutputInterface $output The output interface.
     *
     * @return int The exit code (0 for success, non-zero for failure).
     *
     * @throws RuntimeException If the source directory does not exist or target
     *                          directory cannot be created.
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getName() ?? '');

        /** @var string|null $source */
        $source   = $input->getArgument('source');
        $resolved = is_string($source) ? realpath($source) : false;

        if ($resolved === false) {
            $io->error('Source path does not exist.');

            return self::FAILURE;
        }

        $isSingleFile    = is_file($resolved);
        $sourceDirectory = $isSingleFile ? dirname($resolved) : $resolved;

        $dryRun = (bool) $input->getOption('dry-run');
        $delete = (bool) $input->getOption('delete');
        $target = $input->getOption('target');

        if (!is_string($target)) {
            $target = '_duplicates';
        }

        $files = $isSingleFile
            ? [new SplFileInfo($resolved)]
            : $this->fileSystemService->collectFiles($sourceDirectory);

        $io->text(sprintf('<fg=cyan>Scanning:</> %s', $sourceDirectory));

        $progressBar = $files !== [] ? $io->createProgressBar(count($files)) : null;
        $progressBar?->setFormat(Constants::PROGRESS_BAR_FORMAT);
        $progressBar?->start();

        $originalIndex = $this->dedupOriginalMatcher->createIndex($files);

        /** @var list<array{file: SplFileInfo, original: SplFileInfo|null, relativePath: string}> $duplicates */
        $duplicates = [];

        foreach ($files as $file) {
            $progressBar?->advance();

            $basename = FileHelper::basenameWithoutExtension($file);

            if (!str_contains($basename, Constants::DUPLICATE_IDENTIFIER)) {
                continue;
            }

            $relativePath = PathHelper::relativizePath($file->getPathname(), $sourceDirectory);

            $duplicates[] = [
                'file'         => $file,
                'original'     => $this->dedupOriginalMatcher->match($file, $originalIndex),
                'relativePath' => $relativePath,
            ];
        }

        $progressBar?->finish();
        $io->newLine(2);

        // Post-scan summary
        $action          = $delete ? 'delete' : 'move';
        $actionableCount = count(array_filter(
            $duplicates,
            static fn (array $duplicateEntry): bool => $duplicateEntry['original'] instanceof SplFileInfo,
        ));
        $orphanCount = count($duplicates) - $actionableCount;

        if ($duplicates !== []) {
            $io->text(sprintf(
                '<fg=cyan>Found %d duplicate file(s) (%d actionable, %d orphaned).</>',
                count($duplicates),
                $actionableCount,
                $orphanCount,
            ));

            if ($actionableCount > 0) {
                $io->text(sprintf('  Action: <fg=yellow>%s</> duplicates whose original still exists.', $action));
            }

            $io->newLine();
        } elseif ($files !== []) {
            $io->newLine();
            $io->text('<fg=green>No duplicate files found — nothing to do.</>');
            $io->newLine();
        }

        // Safety confirmation for non-dry-run (Principle 9)
        if (!$dryRun && ($actionableCount > 0) && !$io->confirm('This will ' . $action . ' ' . $actionableCount . ' duplicate file(s). Are you sure?', false)) {
            $io->text('<fg=yellow>Aborted.</>');

            return self::SUCCESS;
        }

        $duplicatesFound  = 0;
        $orphanedCount    = 0;
        $spaceReclaimable = 0;

        foreach ($duplicates as $entry) {
            $file         = $entry['file'];
            $relativePath = $entry['relativePath'];

            if (!$entry['original'] instanceof SplFileInfo) {
                ++$orphanedCount;

                $io->text(sprintf(
                    '<fg=yellow>[!]</> %s <fg=cyan>→</> Original not found (skipped)',
                    $relativePath,
                ));

                continue;
            }

            ++$duplicatesFound;
            $spaceReclaimable += $file->getSize();

            if ($dryRun) {
                if ($delete) {
                    $this->renderIndentedAction(
                        $io,
                        'cyan',
                        $relativePath,
                        'Would delete',
                    );
                } else {
                    $targetRelativePath = $target . DIRECTORY_SEPARATOR . $relativePath;

                    $this->renderIndentedAction(
                        $io,
                        'cyan',
                        $relativePath,
                        sprintf(
                            'Would move to %s',
                            $targetRelativePath,
                        ),
                    );
                }
            } elseif ($delete) {
                $this->filesystem->remove($file->getPathname());

                $this->renderIndentedAction(
                    $io,
                    'green',
                    $relativePath,
                    'Deleted',
                );
            } else {
                $relativeDir = PathHelper::relativizePath($file->getPath(), $sourceDirectory);

                // When the file is at the root of the source directory, relativizePath
                // returns the absolute path unchanged. In that case use the target
                // folder directly without appending a subdirectory.
                if ($relativeDir === $file->getPath()) {
                    $targetDir = $sourceDirectory . DIRECTORY_SEPARATOR . $target;
                } else {
                    $targetDir = $sourceDirectory . DIRECTORY_SEPARATOR . $target . DIRECTORY_SEPARATOR . $relativeDir;
                }

                $targetPath = $targetDir . DIRECTORY_SEPARATOR . $file->getBasename();

                $this->filesystem->mkdir($targetDir);
                $this->filesystem->rename($file->getPathname(), $targetPath);

                $targetRelativePath = $target . DIRECTORY_SEPARATOR . $relativePath;

                $this->renderIndentedAction(
                    $io,
                    'green',
                    $relativePath,
                    $targetRelativePath,
                );
            }
        }

        // Render summary
        $io->newLine();
        $this->renderSummary($io, count($files), $duplicatesFound, $orphanedCount, $spaceReclaimable);

        return self::SUCCESS;
    }

    /**
     * Renders one duplicate action as a two-line block for better readability.
     *
     * Long relative paths stay on the first line, while the actual action is
     * indented on the second line so lists of dedup operations remain easy to scan.
     *
     * @param SymfonyStyle $io           The SymfonyStyle IO instance for output.
     * @param string       $tagColor     Console color name used for the `[D]` tag.
     * @param string       $relativePath Duplicate file path relative to the source root.
     * @param string       $actionText   Action description shown after the arrow.
     */
    private function renderIndentedAction(
        SymfonyStyle $io,
        string $tagColor,
        string $relativePath,
        string $actionText,
    ): void {
        $io->text(sprintf(
            '<fg=%s>[D]</> %s' . "\n" . '     <fg=cyan>→</> %s',
            $tagColor,
            $relativePath,
            $actionText,
        ));
    }

    /**
     * Renders a final summary of the de-duplication operation.
     *
     * Displays the total number of files scanned, duplicates found,
     * orphaned files identified, and the amount of disk space that can be reclaimed.
     *
     * @param SymfonyStyle $io               The SymfonyStyle IO instance for output.
     * @param int          $scannedFiles     Total number of files scanned.
     * @param int          $duplicatesFound  Number of duplicate files identified.
     * @param int          $orphanedCount    Number of files that could not be processed.
     * @param int          $spaceReclaimable Total size of duplicate files in bytes.
     */
    private function renderSummary(
        SymfonyStyle $io,
        int $scannedFiles,
        int $duplicatesFound,
        int $orphanedCount,
        int $spaceReclaimable,
    ): void {
        $rows = [
            new SummaryRow('Scanned files', (string) $scannedFiles),
            new SummaryRow('Duplicates found', (string) $duplicatesFound),
        ];

        if ($orphanedCount > 0) {
            $rows[] = new SummaryRow('Orphaned (skipped)', (string) $orphanedCount);
        }

        $rows[] = new SummaryRow('Space reclaimable', FileHelper::formatSize($spaceReclaimable));

        $this->renderer->renderSummarySection($rows, $io);
    }
}
