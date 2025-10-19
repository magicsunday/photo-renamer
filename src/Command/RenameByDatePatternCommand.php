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
use MagicSunday\Renamer\Command\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Model\Pattern\DatePlaceholderExpressionMap;
use MagicSunday\Renamer\Model\Pattern\PatternExpression;
use MagicSunday\Renamer\Model\Pattern\PatternMatchSet;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\TargetPathnameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\DatePatternFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Input\InputOption;

use function is_string;

/**
 * Recursively renames all files matching a given date/time pattern.
 * The renaming is defined by the given "replacement" pattern.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class RenameByDatePatternCommand extends AbstractRenameCommand
{
    private ?PatternExpression $patternExpression = null;

    private ?PatternMatchSet $patternMatchSet = null;

    /**
     * @var string
     */
    private string $replacement = '';

    /**
     * Configures the current command.
     *
     * @return void
     */
    #[Override]
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('pattern:date')
            ->setAliases(['rename:date-pattern'])
            ->setDescription('Renames files by matching date placeholders in filenames.')
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
            throw new RuntimeException('Failed to extract the date pattern from given pattern');
        }

        $this->patternExpression = PatternExpression::fromTemplate(
            $patternOption,
            DatePlaceholderExpressionMap::default()
        );

        $this->patternMatchSet = PatternMatchSet::fromPattern($patternOption);
        $this->replacement     = $replacementOption;

        return parent::executeCommand();
    }

    #[Override]
    protected function createFileIterator(): RecursiveIteratorIterator
    {
        if ($this->patternExpression === null) {
            throw new RuntimeException('Pattern expression has not been initialised.');
        }

        return $this->fileSystemService
            ->createFileIterator(
                $this->sourceDirectory,
                new RecursiveRegexFileFilterIterator(
                    new RecursiveDirectoryIterator(
                        $this->sourceDirectory,
                        FilesystemIterator::SKIP_DOTS
                    ),
                    $this->patternExpression->getRegex()
                )
            );
    }

    #[Override]
    protected function getTargetFilenameProcessor(): RenameStrategyInterface
    {
        if ($this->patternExpression === null || $this->patternMatchSet === null) {
            throw new RuntimeException('Pattern configuration has not been initialised.');
        }

        return new DatePatternFilenameStrategy(
            $this->patternExpression->getRegex(),
            $this->replacement,
            $this->patternMatchSet
        );
    }

    #[Override]
    protected function getDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface
    {
        return new TargetPathnameStrategy();
    }
}
