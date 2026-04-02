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
use MagicSunday\Renamer\Strategy\RenameStrategy\DatePattern\DatePlaceholderExpressionMap;
use MagicSunday\Renamer\Strategy\RenameStrategy\DatePattern\PatternMatchSet;
use MagicSunday\Renamer\Strategy\RenameStrategy\DatePatternFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\InputOption;

use function is_string;

/**
 * Extracts date components from existing filenames using a placeholder-based
 * search pattern (e.g. "/^{y}-{m}-{d}.{H}-{i}-{s}(.+)$/"), reconstructs a
 * DateTime, and reformats it according to the replacement template. Groups
 * by full target pathname so files in different subdirectories remain separate.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class RenameByDatePatternCommand extends AbstractRenameCommand
{
    private ?RenameStrategyInterface $renameStrategy = null;

    private ?DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy = null;

    /**
     * Constructor.
     *
     * @param FileSystemServiceInterface         $fileSystemService         Service to handle file system operations
     * @param DuplicateDetectionServiceInterface $duplicateDetectionService Service to handle grouping and duplicate resolution
     * @param SafeRegex                          $safeRegex                 Service to execute regular expressions safely
     */
    public function __construct(
        FileSystemServiceInterface $fileSystemService,
        DuplicateDetectionServiceInterface $duplicateDetectionService,
        private readonly SafeRegex $safeRegex,
    ) {
        parent::__construct($fileSystemService, $duplicateDetectionService);
    }

    private ?string $patternRegex = null;

    private ?PatternMatchSet $patternMatchSet = null;

    private string $replacement = '';

    /**
     * Configures the current command.
     */
    #[Override]
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('rename:date')
            ->setDescription('Renames files by extracting date components from filenames.')
            ->addOption(
                'pattern',
                'p',
                InputOption::VALUE_REQUIRED,
                'The pattern used to search for files',
                '/^{y}-{m}-{d}.{H}-{i}-{s}(.+)$/'
            )
            ->addOption(
                'replacement',
                'r',
                InputOption::VALUE_REQUIRED,
                'The pattern used to replace the matches results',
                '{Y}-{m}-{d}_{H}-{i}-{s}'
            );
    }

    /**
     * Executes the command logic.
     *
     * Initializes the pattern regex and replacement template from CLI options
     * before delegating to the parent rename pipeline.
     *
     * @return int The exit code (0 for success, non-zero for failure).
     */
    #[Override]
    protected function executeCommand(): int
    {
        $replacementOption = $this->input->getOption('replacement');

        if (!is_string($replacementOption)) {
            $this->io->error('A valid replacement value is required');

            return self::FAILURE;
        }

        $patternOption = $this->input->getOption('pattern');

        if (!is_string($patternOption)) {
            $this->io->error('A valid pattern value is required');

            return self::FAILURE;
        }

        $this->patternRegex = DatePlaceholderExpressionMap::default()
            ->replacePlaceholders($patternOption);

        $this->patternMatchSet = PatternMatchSet::fromPattern($patternOption);
        $this->replacement     = $replacementOption;

        return parent::executeCommand();
    }

    /**
     * Creates the file iterator.
     *
     * Uses a regex filter based on the date-placeholder pattern to select files.
     *
     * @return RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>>
     */
    #[Override]
    protected function createFileIterator(): RecursiveIteratorIterator
    {
        if ($this->patternRegex === null) {
            throw new RuntimeException('Pattern regex has not been initialised.');
        }

        return $this->fileSystemService
            ->createFileIterator(
                $this->sourceDirectory,
                new RecursiveRegexFileFilterIterator(
                    new RecursiveDirectoryIterator(
                        $this->sourceDirectory,
                        FilesystemIterator::SKIP_DOTS
                    ),
                    $this->patternRegex
                )
            );
    }

    /**
     * Returns the target filename strategy.
     *
     * Uses the DatePatternFilenameStrategy to extract date components
     * from filenames and reformat them.
     *
     * @return RenameStrategyInterface The rename strategy
     */
    #[Override]
    protected function getTargetFilenameStrategy(): RenameStrategyInterface
    {
        if (($this->patternRegex === null) || !($this->patternMatchSet instanceof PatternMatchSet)) {
            throw new RuntimeException('Pattern configuration has not been initialised.');
        }

        return $this->renameStrategy ??= new DatePatternFilenameStrategy(
            $this->patternRegex,
            $this->replacement,
            $this->patternMatchSet,
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
        return $this->duplicateIdentifierStrategy ??= new TargetPathnameStrategy();
    }
}
