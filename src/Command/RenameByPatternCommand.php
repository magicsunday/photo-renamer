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
use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetPathnameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\PatternFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Filesystem;

use function is_string;

/**
 * Applies a PCRE regex search/replace to all filenames matching the pattern.
 * The --pattern option defines which files are selected and how capture groups
 * are extracted; --replacement defines the substitution string. Groups by
 * full target pathname to handle per-directory duplicate detection.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class RenameByPatternCommand extends AbstractRenameCommand
{
    private ?RenameStrategyInterface $renameStrategy = null;

    private string $pattern = '';

    private string $replacement = '';

    /**
     * @param FileSystemServiceInterface         $fileSystemService           Service to handle file system operations
     * @param DuplicateDetectionServiceInterface $duplicateDetectionService   Service to handle grouping and duplicate resolution
     * @param SafeRegex                          $safeRegex                   Safe regex wrapper used by the shared legacy file iterator path
     * @param Filesystem                         $filesystem                  Command-facing filesystem boundary reused by metadata-cache helpers
     * @param TargetPathnameStrategy             $duplicateIdentifierStrategy Fixed target-path grouping strategy for this command
     */
    public function __construct(
        FileSystemServiceInterface $fileSystemService,
        DuplicateDetectionServiceInterface $duplicateDetectionService,
        SafeRegex $safeRegex,
        Filesystem $filesystem,
        private readonly TargetPathnameStrategy $duplicateIdentifierStrategy,
    ) {
        parent::__construct($fileSystemService, $duplicateDetectionService, $safeRegex, $filesystem);
    }

    /**
     * Configures the current command.
     */
    #[Override]
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('rename:pattern')
            ->setDescription('Renames files using a regular expression pattern.')
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
                '${1}jpg'
            );
    }

    /**
     * Executes the command logic.
     *
     * Validates the replacement and pattern options and initializes
     * internal state before calling the parent execution.
     *
     * @return int The exit code
     */
    #[Override]
    protected function executeCommand(): int
    {
        if ($this->input->getOption('replacement') === null) {
            $this->io->error('A valid replacement value is required');

            return self::FAILURE;
        }

        $pattern     = $this->input->getOption('pattern');
        $replacement = $this->input->getOption('replacement');

        if (is_string($pattern)) {
            $this->pattern = $pattern;
        }

        if (is_string($replacement)) {
            $this->replacement = $replacement;
        }

        return parent::executeCommand();
    }

    /**
     * Creates the file iterator.
     *
     * Uses a regex filter based on the provided pattern to select files.
     *
     * @return RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>>
     */
    #[Override]
    protected function createFileIterator(): RecursiveIteratorIterator
    {
        return $this->fileSystemService
            ->createFileIterator(
                $this->sourceDirectory,
                new RecursiveRegexFileFilterIterator(
                    new RecursiveDirectoryIterator(
                        $this->sourceDirectory,
                        FilesystemIterator::SKIP_DOTS
                    ),
                    $this->pattern,
                    $this->safeRegex,
                )
            );
    }

    /**
     * Returns the target filename strategy.
     *
     * Uses the PatternFilenameStrategy to perform regex-based renames.
     *
     * @return RenameStrategyInterface The rename strategy
     */
    #[Override]
    protected function getTargetFilenameStrategy(): RenameStrategyInterface
    {
        return $this->renameStrategy ??= new PatternFilenameStrategy(
            $this->pattern,
            $this->replacement,
            $this->safeRegex,
        );
    }

    /**
     * Returns the duplicate identifier strategy.
     *
     * Uses the TargetPathnameStrategy to group files by their full target path.
     *
     * @return DuplicateIdentifierStrategyInterface The duplicate identifier strategy
     */
    #[Override]
    protected function getDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface
    {
        return $this->duplicateIdentifierStrategy;
    }
}
