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
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Service\CanonicalScorerInterface;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\Execution\ExecutionPlanBuilderInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\HashSubGroupingService;
use MagicSunday\Renamer\Service\HashSubGroupingServiceInterface;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculator;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use MagicSunday\Renamer\Service\Pipeline\AssetGroupPipeline;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\ValidationResult;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\InputOption;

use function array_filter;
use function array_map;
use function array_unique;
use function count;
use function implode;
use function is_dir;
use function is_string;
use function preg_quote;
use function sprintf;

/**
 * Renames photos and videos using their EXIF DateTimeOriginal value as the target
 * filename (e.g. "2023-01-15_14-30-00-123.jpg"). Supports Apple Live Photo
 * companion pairing via content identifiers: a second scan pass matches MOV files
 * to their corresponding HEIC/JPG group even when the MOV has no EXIF date.
 * Groups by target basename so files with different extensions but the same
 * capture time share one group.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class RenameByExifDateCommand extends AbstractRenameCommand
{
    public function __construct(
        FileSystemServiceInterface $fileSystemService,
        DuplicateDetectionServiceInterface $duplicateDetectionService,
        private readonly ExifMetadataProvider $exifMetadataProvider,
        private readonly PerceptualHashCalculatorInterface $perceptualHashCalculator,
        private readonly HashSubGroupingServiceInterface $hashSubGroupingService,
        private readonly AssetGroupPipeline $pipeline,
        private readonly CanonicalScorerInterface $canonicalScorer,
        private readonly ExecutionPlanBuilderInterface $executionPlanBuilder,
        private readonly RenameOutputRenderer $renameOutputRenderer,
    ) {
        parent::__construct($fileSystemService, $duplicateDetectionService);
    }

    /**
     * PHP date() format string defining the target basename pattern.
     */
    private string $targetFilenamePattern = '';

    /**
     * Lazily created EXIF date filename strategy, reset when the pattern changes.
     */
    private ?ExifDateFilenameStrategy $exifDateFilenameStrategy = null;

    /**
     * Lazily created duplicate identifier strategy.
     */
    private ?DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy = null;

    /**
     * Configures the EXIF date rename command with its name, description, and options.
     */
    #[Override]
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('rename:exif')
            ->setDescription(
                'Renames files by EXIF date (incl. Apple Live Photos).'
            )
            ->addOption(
                'target-filename-pattern',
                'fp',
                InputOption::VALUE_REQUIRED,
                'The date pattern used to create the target filename.',
                'Y-m-d_H-i-s-v'
            )
            ->addOption(
                'merge-threshold',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum RMSE (0.0–1.0) for merging perceptually similar files. Overrides MERGE_THRESHOLD env var. Default: 0.05.',
            );
    }

    /**
     * Executes the command and resets cached strategies when the filename pattern changes.
     * Sets up the persistent metadata cache before the pipeline and flushes it afterwards.
     */
    #[Override]
    protected function executeCommand(): int
    {
        $this->useFileExtensionFromSource = true;

        // Existing pattern/provider/cache setup (unchanged)
        $targetFilenamePattern = $this->input->getOption('target-filename-pattern');

        if (is_string($targetFilenamePattern)) {
            $this->targetFilenamePattern       = $targetFilenamePattern;
            $this->exifDateFilenameStrategy    = null;
            $this->duplicateIdentifierStrategy = null;
        }

        $this->configureProviderTimezone($this->exifMetadataProvider, $this->input);

        $metadataCache = $this->configureProviderCache($this->exifMetadataProvider);
        $signalCache   = $this->createPerceptualSignalCache();

        if ($this->perceptualHashCalculator instanceof PerceptualHashCalculator) {
            $this->perceptualHashCalculator->setSignalCache($signalCache);
        }

        if ($this->hashSubGroupingService instanceof HashSubGroupingService) {
            $this->hashSubGroupingService->setMaxMergeChangedArea(
                $this->resolveMergeThreshold($this->input),
            );
        }

        // Configure score-based canonical selection
        $this->canonicalScorer->setFormatPriority($this->resolveFormatPriority());
        $this->canonicalScorer->setSourceDirectory($this->sourceDirectory);

        try {
            $this->processWithAssetGroups();

            $this->io->success('done');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->io->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $metadataCache->flush();
            $signalCache->flush();
        }
    }

    /**
     * @return RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>>
     */
    #[Override]
    protected function createFileIterator(): RecursiveIteratorIterator
    {
        $fileExtensionRegex = '/\.(' . implode('|', array_map(
            static fn (string $ext): string => $ext === 'jpg' ? 'jpe?g' : preg_quote($ext, '/'),
            array_unique(array_filter(
                Constants::SUPPORTED_MEDIA_EXTENSIONS,
                static fn (string $ext): bool => $ext !== 'jpeg',
            )),
        )) . ')$/i';

        $recursiveIterator = null;

        if (is_dir($this->sourceDirectory)) {
            $recursiveIterator = new RecursiveRegexFileFilterIterator(
                new RecursiveDirectoryIterator(
                    $this->sourceDirectory,
                    FilesystemIterator::SKIP_DOTS
                ),
                $fileExtensionRegex
            );
        }

        return $this->fileSystemService
            ->createFileIterator(
                $this->sourceDirectory,
                $recursiveIterator
            );
    }

    /**
     * Returns the strategy that builds the target filename based on EXIF dates.
     */
    #[Override]
    protected function getTargetFilenameStrategy(): RenameStrategyInterface
    {
        return $this->getExifDateFilenameStrategy();
    }

    /**
     * Provides the duplicate identifier strategy capable of handling Live Photos.
     */
    #[Override]
    protected function getDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface
    {
        return $this->duplicateIdentifierStrategy ??= new TargetBasenameStrategy();
    }

    /**
     * Executes the new 8-step AssetGroup pipeline.
     */
    private function processWithAssetGroups(): void
    {
        // Steps 1-6: build groups, classify, assign roles, resolve names, resolve collisions, validate
        $pipelineResult = $this->pipeline->run(
            $this->createFileIterator(),
            $this->getTargetFilenameStrategy(),
            $this->getDuplicateIdentifierStrategy(),
            $this->sourceDirectory,
            $this->useFileExtensionFromSource,
        );

        // Release metadata cache after pipeline
        $this->exifMetadataProvider->clearCache();

        // Handle validation
        if (!$pipelineResult->validationResult->isValid()) {
            $this->renderValidationWarnings($pipelineResult->validationResult);
        }

        // Abort on circular swaps (data loss prevention)
        if ($pipelineResult->validationResult->circularSwaps !== []) {
            throw new RuntimeException(sprintf(
                'Aborting: %d circular swap(s) detected. Manual resolution required.',
                count($pipelineResult->validationResult->circularSwaps),
            ));
        }

        // Step 7: Build ExecutionPlan from pipeline output
        $executionPlan = $this->executionPlanBuilder->build(
            $pipelineResult->groups,
            $pipelineResult->context,
        );

        // Build RenameResult from pipeline context
        $result = $pipelineResult->context->toRenameResult();

        $this->renderPostScanSummary($result);

        // Render output entries (render-only, no file moves)
        $options = new RenameOptions(
            dryRun: $this->dryRun,
            listAll: $this->listAll,
            sourceBaseDirectory: $this->sourceDirectory,
            maxDateDrift: $this->maxDateDrift,
        );

        $counters = $this->renameOutputRenderer->renderPlanEntries(
            $executionPlan,
            $options,
            $this->sourceDirectory,
            $this->showFilter,
            $result,
        );

        // Render decision log in --list-all mode
        if ($this->listAll) {
            $this->renameOutputRenderer->renderDecisionLogFromPlan($executionPlan);
        }

        // Execute file operations (may apply runtime fallback for edge cases
        // where CollisionResolver could not predict a conflict at plan time)
        $this->fileSystemService->executePlan($executionPlan, $this->dryRun);

        // Render summary
        $this->renameOutputRenderer->renderPlanSummary(
            $executionPlan,
            $result,
            $counters,
            $this->dryRun,
        );

        // Cleanup
        $this->hashSubGroupingService->clearCache();
    }

    /**
     * Renders validation warnings for the rename plan.
     */
    private function renderValidationWarnings(ValidationResult $validationResult): void
    {
        if ($validationResult->duplicateTargets !== []) {
            $this->io->warning(sprintf(
                '%d duplicate target path(s) — multiple files map to the same destination.',
                count($validationResult->duplicateTargets),
            ));
        }

        if ($validationResult->caseConflicts !== []) {
            $this->io->warning(sprintf(
                '%d case-insensitive conflict(s) — targets differ only in letter case.',
                count($validationResult->caseConflicts),
            ));
        }

        if ($validationResult->circularSwaps !== []) {
            $this->io->warning(sprintf(
                '%d circular swap(s) — rename cycle(s) that would cause data loss.',
                count($validationResult->circularSwaps),
            ));
        }
    }

    /**
     * Creates the EXIF date rename strategy using the configured filename pattern.
     */
    private function getExifDateFilenameStrategy(): ExifDateFilenameStrategy
    {
        return $this->exifDateFilenameStrategy ??= new ExifDateFilenameStrategy(
            $this->targetFilenamePattern,
            $this->exifMetadataProvider,
        );
    }
}
