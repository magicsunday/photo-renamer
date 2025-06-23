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
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\TargetPathnameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\DatePatternFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Input\InputOption;

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
    /**
     * Date/Time placeholders to regular expression mapping.
     *
     * @var string[]
     */
    private array $dateExpression = [
        'Y' => '(\d{4})',
        'y' => '(\d{2})',
        'm' => '(\d{2})',
        'd' => '(\d{2})',
        'H' => '(\d{2})',
        'i' => '(\d{2})',
        's' => '(\d{2})',
    ];

    /**
     * @var string
     */
    private string $pattern = '';

    /**
     * @var string[][]
     */
    private array $patternMatches = [];

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
            ->setName('rename:date-pattern')
            ->setDescription('Renames files by matching a date pattern.')
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
        if ($this->input->getOption('replacement') === null) {
            $this->io->error('A valid replacement value is required');

            return self::FAILURE;
        }

        $pattern     = (string) $this->input->getOption('pattern');
        $replacement = (string) $this->input->getOption('replacement');

        // Create a regular date expression
        $datePattern = preg_replace_callback(
            '/{(\w+)}/',
            fn ($matches): string => $this->dateExpression[$matches[1]] ?? $matches[0],
            $pattern
        );

        if ($datePattern === null) {
            throw new RuntimeException('Failed to extract the date pattern from given pattern');
        }

        // Extract the used date parts in the pattern
        preg_match_all(
            '/{(\w+)}/',
            $pattern,
            $patternMatches
        );

        $this->pattern        = $datePattern;
        $this->patternMatches = $patternMatches;
        $this->replacement    = $replacement;

        return parent::executeCommand();
    }

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
                    $this->pattern
                )
            );
    }

    #[Override]
    protected function getTargetFilenameProcessor(): RenameStrategyInterface
    {
        return new DatePatternFilenameStrategy(
            $this->pattern,
            $this->replacement,
            $this->patternMatches
        );
    }

    #[Override]
    protected function getDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface
    {
        return new TargetPathnameStrategy();
    }
}
