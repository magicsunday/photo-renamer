<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Command;

use DateTime;
use FilesystemIterator;
use MagicSunday\Renamer\Command\FilterIterator\FilenameFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function strlen;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class RenameByDatePatternCommand extends BaseRenameCommand
{
    /**
     * Date/Time placeholders to regular expression mapping.
     *
     * @var string[]
     */
    protected array $dateExpression = [
        'Y' => '(\d{4})',
        'y' => '(\d{2})',
        'm' => '(\d{2})',
        'd' => '(\d{2})',
        'H' => '(\d{2})',
        'i' => '(\d{2})',
        's' => '(\d{2})',
    ];

    /**
     * Configures the current command.
     *
     * @return void
     */
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('rename:date-pattern')
            ->setDescription('Renames files by pattern.');

        $this
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
     * @param string      $pattern
     * @param string      $replacement
     * @param string      $sourceDirectory
     * @param string|null $targetDirectory
     *
     * @return void
     *
     * @throws RuntimeException
     */
    public function processDirectory(
        bool $dryRun,
        bool $copyFiles,
        string $pattern,
        string $replacement,
        string $sourceDirectory,
        ?string $targetDirectory = null
    ): void {
        // Create regular date expression
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

        $directoryIterator      = new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS);
        $filenameFilterIterator = new FilenameFilterIterator($directoryIterator, $datePattern);
        $iterator               = new RecursiveIteratorIterator($filenameFilterIterator, RecursiveIteratorIterator::LEAVES_ONLY);
        $fileCount              = 0;

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            $filePartMatches    = [];
            $replacementMatches = [];

            preg_match(
                $datePattern,
                $fileInfo->getFilename(),
                $filePartMatches
            );

            preg_match_all(
                '/{(\w+)}/',
                $replacement . '$1',
                $replacementMatches
            );

            $dateFormatCharacters  = $patternMatches[1];
            $targetFilenamePattern = str_replace($replacementMatches[0], $replacementMatches[1], $replacement);

            // Create new filename
            $newFilename = preg_replace_callback(
                $datePattern,
                static function (array $replacementMatches) use ($dateFormatCharacters, $targetFilenamePattern, $filePartMatches): string {
                    $dateParts = [];

                    foreach ($dateFormatCharacters as $key => $dateFormatCharacter) {
                        if ($dateFormatCharacter === 'y') {
                            $dateFormatCharacter = 'Y';
                        }

                        // Convert 2-digit year to 4-digit
                        if (($dateFormatCharacter === 'Y')
                            && (strlen($replacementMatches[$key + 1]) === 2)
                        ) {
                            $fourDigitYearDate = DateTime::createFromFormat('y', $replacementMatches[$key + 1]);

                            if ($fourDigitYearDate !== false) {
                                $replacementMatches[$key + 1] = $fourDigitYearDate->format('Y');
                            }
                        }

                        $dateParts[$dateFormatCharacter] = (int) $replacementMatches[$key + 1];
                    }

                    $dateTimeCreated = new DateTime();
                    $dateTimeCreated
                        ->setDate($dateParts['Y'] ?? 0, $dateParts['m'] ?? 1, $dateParts['d'] ?? 1)
                        ->setTime($dateParts['H'] ?? 0, $dateParts['i'] ?? 0, $dateParts['s'] ?? 0);

                    return $dateTimeCreated->format($targetFilenamePattern) . $replacementMatches[count($filePartMatches) - 1];
                },
                $fileInfo->getFilename(),
            );

            if ($targetDirectory !== null) {
                $newPathname = $targetDirectory . '/' . $fileInfo->getPath() . '/' . $newFilename;
            } else {
                $newPathname = $fileInfo->getPath() . '/' . $newFilename;
            }

            if (file_exists($newPathname)) {
                continue;
            }

            $this->io->text('Rename ' . $fileInfo->getPathname() . ' to ' . $newPathname);

            ++$fileCount;

            if ($dryRun === false) {
                $this->renameFile($newPathname, $fileInfo, $copyFiles);
            }
        }

        $this->io->info($fileCount . ' files renamed');
    }
}
