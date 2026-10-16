<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use Closure;
use FilesystemIterator;
use MagicSunday\Renamer\Command\Concern\ConfiguresMetadataProvider;
use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Model\LinkConfig;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\Filesystem\FileCollector;
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
use Symfony\Component\Filesystem\Filesystem;

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
 * Shared Console lifecycle for EXIF and the simple rename workflows. Owns
 * options, source normalization, confirmation, iteration and scan reporting,
 * without duplicate grouping or knowledge of either execution model.
 * Centralizing stable CLI rules preserves the same semantics across commands.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
abstract class AbstractMediaRenameCommand extends Command
{
    use ConfiguresMetadataProvider;

    protected InputInterface $input;

    protected SymfonyStyle $io;

    /** Whether the enclosing application invocation already obtained confirmation. */
    private bool $prepared = false;

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
    protected bool $dryRun = true;

    /**
     * When true, the output lists all files including unchanged originals.
     */
    protected bool $listAll = false;

    /**
     * Immutable terminal link settings selected afresh for each command run.
     */
    protected ?LinkConfig $linkConfig = null;

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
     * Constructor.
     *
     * @param FileCollector $fileCollector Collects files for the command scan
     * @param SafeRegex     $safeRegex     Safe regex wrapper shared by legacy iterator filters and pattern strategies
     * @param Filesystem    $filesystem    Command-facing filesystem boundary reused by metadata-cache helpers
     */
    public function __construct(
        protected FileCollector $fileCollector,
        protected readonly SafeRegex $safeRegex,
        protected readonly Filesystem $filesystem,
    ) {
        parent::__construct();
    }

    /**
     * Exposes the command-level filesystem boundary to shared metadata provider configuration.
     */
    protected function getCommandFilesystem(): Filesystem
    {
        return $this->filesystem;
    }

    /**
     * Configures the command.
     *
     * Registers the shared CLI arguments (source) and options
     * (--apply, --list-all, --show, --timezone, --max-date-drift) common to all rename commands.
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
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Apply the planned changes after confirmation; preview is the default.'
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
     * Obtains confirmation before the application opens its expensive mutation
     * scope. Preparation lasts only for the supplied run, including failures;
     * direct CommandTester/service callers retain the normal Console lifecycle.
     *
     * @param Closure(): int $run Application execution with lock, identity and journal protection
     *
     * @return int Command status, or failure when confirmation is declined
     */
    final public function runAfterConfirmation(InputInterface $input, OutputInterface $output, Closure $run): int
    {
        $input->validate();

        if ($this->prepareExecution($input, $output) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->prepared = true;

        try {
            return $run();
        } finally {
            $this->prepared = false;
        }
    }

    /**
     * Initializes command IO/options and obtains consent without reading sources.
     *
     * @return int SUCCESS for a dry run or confirmed mutation, FAILURE otherwise
     */
    private function prepareExecution(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title($this->getName() ?? '');

        $this->input = $input;
        $this->initializeCommandParameters($input);

        return $this->handleDryRunConfirmation();
    }

    /**
     * Runs the selected workflow after confirmation and source normalization.
     * The application can prepare confirmation before capturing source identity;
     * standalone command calls prepare here instead.
     *
     * @return int Workflow exit status
     */
    #[Override]
    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->prepared && ($this->prepareExecution($input, $output) !== self::SUCCESS)) {
            return self::FAILURE;
        }

        $this->normalizeDirectoryPaths();

        return $this->executeCommand();
    }

    /**
     * Initializes command parameters from the provided input.
     *
     * Sets internal state for dryRun, listAll, maxDateDrift, and showFilter
     * based on the CLI options.
     *
     * @param InputInterface $input The input interface
     */
    private function initializeCommandParameters(InputInterface $input): void
    {
        $this->dryRun  = $input->getOption('apply') !== true;
        $this->listAll = (bool) $input->getOption('list-all');

        $this->maxDateDrift = $this->resolveMaxDateDrift($input);
        $this->linkConfig   = $this->resolveLinkConfig();

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

        $this->isSingleFile = false;
        $source             = $input->getArgument('source');

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
     * Handles dry run confirmation or user confirmation for file operations.
     *
     * @return int SUCCESS if confirmed, FAILURE otherwise
     */
    private function handleDryRunConfirmation(): int
    {
        if ($this->dryRun) {
            $this->io->text('<fg=cyan>Preview mode. No media files will be changed. Use --apply to apply changes.</>');
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
     *
     * This ensures the source directory is an absolute canonical path.
     */
    private function normalizeDirectoryPaths(): void
    {
        $this->sourceDirectory = $this->canonicalizeDirectoryPath($this->sourceDirectory);
    }

    /**
     * Converts a directory path to an absolute canonical form.
     *
     * If the path is relative, it is resolved against the current working
     * directory. Trailing slashes are removed.
     *
     * This method handles path normalization for consistent display and
     * file system matching, ensuring that differences in separators or
     * trailing slashes do not affect grouping or mapping.
     *
     * @param string      $directory    The directory path to canonicalize (can be relative or absolute).
     * @param string|null $fallbackBase Fallback base directory if getcwd() fails or returns an empty string.
     *
     * @return string The canonical absolute path, with no trailing slash (unless it is the root).
     *
     * @throws RuntimeException If the directory contains path traversal components ("..") and
     *                          cannot be resolved via realpath() (e.g., it doesn't exist).
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
     * Runs the concrete workflow after shared input, paths and confirmation.
     *
     * @return int Symfony exit status reported by the command-specific workflow
     */
    abstract protected function executeCommand(): int;

    /**
     * Renders a short post-scan summary showing what the pipeline found.
     *
     * Displays counts for issues like ambiguous timezones, fallback dates,
     * skipped files, and Live Photo conflicts.
     *
     * @param RenameResult $result The result object containing scan statistics
     */
    protected function renderPostScanSummary(RenameResult $result): void
    {
        $skippedCount  = count($result->skippedFiles);
        $warningCount  = count($result->ambiguousTimezoneFiles);
        $fallbackCount = count($result->fallbackDateFiles);
        $conflictCount = count($result->livePhotoConflictFiles);
        $crossDirCount = count($result->crossDirectoryCompanions);
        $findingCount  = $skippedCount + $warningCount + $fallbackCount + $conflictCount;
        $affectedFiles = $result->fallbackDateFiles + $result->ambiguousTimezoneFiles + $result->livePhotoConflictFiles;

        foreach ($result->skippedFiles as $skippedFile) {
            $affectedFiles[$skippedFile->getFile()->getPathname()] = true;
        }

        $issueCount = count($affectedFiles);
        $infoCount  = $crossDirCount;

        if (($issueCount > 0) || ($infoCount > 0)) {
            $this->io->newLine(2);
            $this->io->text('<fg=cyan>Scan results</>');
            $this->io->newLine();

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
                    ' <fg=yellow>%d affected file(s), %d finding(s):</> %s',
                    $issueCount,
                    $findingCount,
                    implode(', ', $parts),
                ));
            }

            if ($crossDirCount > 0) {
                $this->io->text(sprintf(
                    ' <fg=blue>%d notice(s):</> %d cross-directory LP pair(s)',
                    $crossDirCount,
                    $crossDirCount,
                ));
            }
        }

        $this->io->newLine(2);
    }

    /**
     * Creates the file iterator for scanning the source directory or single file.
     *
     * Subclasses may override to apply file type filters (e.g. EXIF command
     * filters by image/video extensions).
     *
     * In single file mode, a regex filter is applied to a directory iterator
     * to isolate the specific file while maintaining compatibility with the
     * pipeline's recursive scanning architecture.
     *
     * @return RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>>
     *
     * @throws RuntimeException If the source directory or file does not exist.
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
            return $this->fileCollector->createFileIterator(
                $this->sourceDirectory,
                new RecursiveRegexFileFilterIterator(
                    new RecursiveDirectoryIterator(
                        $this->sourceDirectory,
                        FilesystemIterator::SKIP_DOTS,
                    ),
                    '/^' . preg_quote($basename, '/') . '$/',
                    $this->safeRegex,
                ),
            );
        }

        if (!is_dir($this->sourceDirectory)) {
            throw new RuntimeException(
                sprintf('Source directory "%s" does not exist', $this->sourceDirectory)
            );
        }

        return $this->fileCollector
            ->createFileIterator($this->sourceDirectory);
    }
}
