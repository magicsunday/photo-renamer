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
use Exception;
use FilesystemIterator;
use MagicSunday\Renamer\Command\FilterIterator\RegExFilterIterator;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function strlen;

/**
 * Recursivly renames all files matching a given date/time pattern.
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
     * Configures the current command.
     *
     * @return void
     */
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

        if ($input->getOption('replacement') === null) {
            $this->io->error('A valid replacement value is required');

            return self::FAILURE;
        }

        try {
            $this->processDirectory(
                $input->getOption('dry-run'),
                $input->getOption('copy-files'),
                $input->getOption('skip-duplicates'),
                $input->getOption('pattern'),
                $input->getOption('replacement'),
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
     * @param string      $pattern
     * @param string      $replacement
     * @param string      $sourceDirectory
     * @param string|null $targetDirectory
     *
     * @return void
     *
     * @throws RuntimeException
     */
    private function processDirectory(
        bool $dryRun,
        bool $copyFiles,
        bool $skipDuplicates,
        string $pattern,
        string $replacement,
        string $sourceDirectory,
        ?string $targetDirectory = null,
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
        $filenameFilterIterator = new RegExFilterIterator($directoryIterator, $datePattern);
        $iterator               = new RecursiveIteratorIterator($filenameFilterIterator, RecursiveIteratorIterator::LEAVES_ONLY);

        $sourceDirectory = rtrim($sourceDirectory, '/');

        // If target directory is empty, use source directory as target
        $targetDirectory = $targetDirectory !== null
            ? rtrim($targetDirectory, '/')
            : $sourceDirectory;

        // Process list of all files matching the given pattern
        $fileDuplicateCollection = $this->groupFilesByTargetPathname(
            $iterator,
            $datePattern,
            $patternMatches,
            $replacement,
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
     * @param string                    $pattern
     * @param string[][]                $patternMatches
     * @param string                    $replacement
     * @param string                    $sourceDirectory
     * @param string                    $targetDirectory
     *
     * @return FileDuplicateCollection
     *
     * @throws Exception
     */
    private function groupFilesByTargetPathname(
        RecursiveIteratorIterator $iterator,
        string $pattern,
        array $patternMatches,
        string $replacement,
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
                $pattern,
                $patternMatches,
                $replacement,
                $targetBasename,
                $splFileInfo
            );

            if ($targetFilename === null) {
                $this->io->error(preg_last_error_msg());
                continue;
            }

            $targetPathname = $this->getTargetPathname(
                $splFileInfo,
                $targetFilename,
                $sourceDirectory,
                $targetDirectory
            );

            // Create a new target file object
            $targetFileInfo = new SplFileInfo($targetPathname);
            $collectionKey  = $targetFileInfo->getPathname();

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
     * @param string      $pattern
     * @param string[][]  $patternMatches
     * @param string      $replacement
     * @param string      $targetBasename
     * @param SplFileInfo $splFileInfo
     *
     * @return string|null
     *
     * @throws Exception
     */
    private function getTargetFilename(
        string $pattern,
        array $patternMatches,
        string $replacement,
        string $targetBasename,
        SplFileInfo $splFileInfo,
    ): ?string {
        $filePartMatches    = [];
        $replacementMatches = [];

        // Perform the regular expression replacement
        preg_match(
            $pattern,
            $targetBasename . '.' . $splFileInfo->getExtension(),
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
        return preg_replace_callback(
            $pattern,
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
            $targetBasename . '.' . $splFileInfo->getExtension()
        );
    }
}
