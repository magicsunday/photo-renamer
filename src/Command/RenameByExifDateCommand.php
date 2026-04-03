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
use MagicSunday\Renamer\Helper\PathHelper;
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
use MagicSunday\Renamer\Service\Pipeline\PipelineReviewMapper;
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
    /**
     * Constructor.
     *
     * @param FileSystemServiceInterface         $fileSystemService         Service to handle file system operations
     * @param DuplicateDetectionServiceInterface $duplicateDetectionService Service to handle grouping and duplicate resolution
     * @param ExifMetadataProvider               $exifMetadataProvider      Provider for EXIF metadata from files
     * @param PerceptualHashCalculatorInterface  $perceptualHashCalculator  Calculator for perceptual image hashes (visual similarity)
     * @param HashSubGroupingServiceInterface    $hashSubGroupingService    Service to further group files by perceptual hash
     * @param AssetGroupPipeline                 $pipeline                  The main asset processing pipeline
     * @param CanonicalScorerInterface           $canonicalScorer           Scorer to determine the "best" file in a group
     * @param ExecutionPlanBuilderInterface      $executionPlanBuilder      Builder for the final execution plan
     * @param PipelineReviewMapper               $pipelineReviewMapper      Maps structured pipeline review facts to output-ready entries
     * @param RenameOutputRenderer               $renameOutputRenderer      Renderer for the rename operation output
     */
    public function __construct(
        FileSystemServiceInterface $fileSystemService,
        DuplicateDetectionServiceInterface $duplicateDetectionService,
        private readonly ExifMetadataProvider $exifMetadataProvider,
        private readonly PerceptualHashCalculatorInterface $perceptualHashCalculator,
        private readonly HashSubGroupingServiceInterface $hashSubGroupingService,
        private readonly AssetGroupPipeline $pipeline,
        private readonly CanonicalScorerInterface $canonicalScorer,
        private readonly ExecutionPlanBuilderInterface $executionPlanBuilder,
        private readonly PipelineReviewMapper $pipelineReviewMapper,
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
                'Maximum RMSE (0.0–1.0) for merging visually similar files. Internal safe limits still cap the effective threshold, so lower values only make the policy stricter. Overrides MERGE_THRESHOLD env var. Default: 0.06.',
            );
    }

    /**
     * Executes the command logic.
     *
     * Initializes the filename pattern, configures metadata providers,
     * perceptual hash calculation, and canonical scoring before running
     * the asset group pipeline.
     *
     * @return int The exit code (0 for success, non-zero for failure).
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
            $this->hashSubGroupingService->setMaxMergeRmse(
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
     * Creates the file iterator.
     *
     * Filters files by supported media extensions (JPG, HEIC, MOV, etc.).
     *
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
     * Returns the target filename strategy for EXIF-based renames.
     *
     * This strategy extracts the capture date from EXIF/QuickTime metadata
     * and formats it into the target basename.
     *
     * @return RenameStrategyInterface The EXIF date rename strategy.
     */
    #[Override]
    protected function getTargetFilenameStrategy(): RenameStrategyInterface
    {
        return $this->getExifDateFilenameStrategy();
    }

    /**
     * Returns the duplicate identifier strategy.
     *
     * Uses the TargetBasenameStrategy to group files. This ensures that
     * files with different extensions (e.g. .JPG and .MOV) that share the
     * same capture time are placed in the same duplicate group.
     *
     * @return DuplicateIdentifierStrategyInterface The duplicate identifier strategy.
     */
    #[Override]
    protected function getDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface
    {
        return $this->duplicateIdentifierStrategy ??= new TargetBasenameStrategy();
    }

    /**
     * Executes the asset group processing pipeline.
     *
     * This follows an 8-step process:
     * 1. Building groups
     * 2. Classification
     * 3. Role assignment
     * 4. Target name resolution
     * 5. Collision resolution
     * 6. Validation
     * 7. Execution plan building
     * 8. Output rendering and optional execution
     *
     * @throws RuntimeException If circular swaps are detected
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
        $reviewEntries = $this->pipelineReviewMapper->mapVideoDuplicateCandidates(
            $pipelineResult->context->getVideoDuplicateCandidates(),
            $pipelineResult->context->sourceDirectory,
        );
        $result = $pipelineResult->context->toRenameResult(
            $reviewEntries,
            count($reviewEntries),
        );

        $this->renderPostScanSummary($result);

        // Render output entries (render-only, no file moves)
        $options = new RenameOptions(
            dryRun: $this->dryRun,
            listAll: $this->listAll,
            sourceBaseDirectory: $this->sourceDirectory,
            maxDateDrift: $this->maxDateDrift,
        );

        $this->io->text('<fg=cyan>Renaming files</>');
        $this->io->newLine();

        $preview = $this->renameOutputRenderer->renderPlanEntries(
            $executionPlan,
            $options,
            $this->sourceDirectory,
            $this->showFilter,
            $result,
        );

        $hasSkippedFiles = $result->skippedFiles !== [];

        if (($preview->plannedMoves === 0) && ($preview->plannedSkips === 0) && !$hasSkippedFiles) {
            if ($this->listAll) {
                $this->io->newLine(2);
            }

            $this->io->text('<fg=green> All files already have the correct name. Nothing to do.</>');
        }

        $this->io->newLine();

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
            $preview,
            $this->dryRun,
        );

        // Cleanup
        $this->hashSubGroupingService->clearCache();
    }

    /**
     * Renders validation warnings if issues were detected during the pipeline.
     *
     * @param ValidationResult $validationResult The validation result containing warnings
     */
    private function renderValidationWarnings(ValidationResult $validationResult): void
    {
        if ($validationResult->duplicateTargets !== []) {
            $this->io->warning(sprintf(
                '%d duplicate target path(s) — multiple files map to the same destination.',
                count($validationResult->duplicateTargets),
            ));

            foreach ($validationResult->duplicateTargets as $target) {
                $this->io->text(sprintf('  <fg=yellow>→</> %s', PathHelper::relativizePath($target, $this->sourceDirectory)));
            }

            $this->io->newLine();
        }

        if ($validationResult->caseConflicts !== []) {
            $this->io->warning(sprintf(
                '%d case-insensitive conflict(s) — targets differ only in letter case.',
                count($validationResult->caseConflicts),
            ));

            foreach ($validationResult->caseConflicts as $group) {
                $relativePaths = array_map(
                    fn (string $path): string => PathHelper::relativizePath($path, $this->sourceDirectory),
                    $group,
                );
                $this->io->text(sprintf('  <fg=yellow>→</> %s', implode(' ↔ ', $relativePaths)));
            }

            $this->io->newLine();
        }

        if ($validationResult->circularSwaps !== []) {
            $this->io->warning(sprintf(
                '%d circular swap(s) — rename cycle(s) that would cause data loss.',
                count($validationResult->circularSwaps),
            ));

            foreach ($validationResult->circularSwaps as $cycle) {
                $relativePaths = array_map(
                    fn (string $path): string => PathHelper::relativizePath($path, $this->sourceDirectory),
                    $cycle,
                );
                $this->io->text(sprintf('  <fg=yellow>→</> %s', implode(' → ', $relativePaths)));
            }

            $this->io->newLine();
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
