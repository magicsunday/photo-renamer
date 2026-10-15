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
use MagicSunday\Renamer\Exception\AnalysisMemoryBudgetExceededException;
use MagicSunday\Renamer\Helper\AnalysisMemoryBudget;
use MagicSunday\Renamer\Helper\ConsoleText;
use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Helper\PathHelper;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\Execution\ExecutionPlanBuilderInterface;
use MagicSunday\Renamer\Service\Filesystem\ExecutionPlanExecutor;
use MagicSunday\Renamer\Service\Filesystem\FileCollector;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use MagicSunday\Renamer\Service\Pipeline\AssetGroupPipeline;
use MagicSunday\Renamer\Service\Pipeline\PipelineReviewMapper;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use MagicSunday\Renamer\Service\ValidationResult;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Filesystem;

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
final class RenameByExifDateCommand extends AbstractMediaRenameCommand
{
    /**
     * Constructor.
     *
     * @param ExecutionPlanExecutor         $executionPlanExecutor       Executes the plan through the shared guarded runtime mover
     * @param FileCollector                 $fileCollector               Collects files for the command scan
     * @param SafeRegex                     $safeRegex                   Safe regex wrapper used by the supported-media iterator filter
     * @param Filesystem                    $filesystem                  Command-facing filesystem boundary reused by metadata-cache helpers
     * @param ExifMetadataProvider          $exifMetadataProvider        Provider for EXIF metadata from files
     * @param AssetGroupPipeline            $pipeline                    The main asset processing pipeline
     * @param ExecutionPlanBuilderInterface $executionPlanBuilder        Builder for the final execution plan
     * @param PipelineReviewMapper          $pipelineReviewMapper        Maps structured pipeline review facts to output-ready entries
     * @param RenameOutputRenderer          $renameOutputRenderer        Renderer for the rename operation output
     * @param TargetBasenameStrategy        $duplicateIdentifierStrategy Fixed basename grouping strategy for EXIF rename groups
     */
    public function __construct(
        FileCollector $fileCollector,
        private readonly ExecutionPlanExecutor $executionPlanExecutor,
        SafeRegex $safeRegex,
        Filesystem $filesystem,
        private readonly ExifMetadataProvider $exifMetadataProvider,
        private readonly AssetGroupPipeline $pipeline,
        private readonly ExecutionPlanBuilderInterface $executionPlanBuilder,
        private readonly PipelineReviewMapper $pipelineReviewMapper,
        private readonly RenameOutputRenderer $renameOutputRenderer,
        private readonly TargetBasenameStrategy $duplicateIdentifierStrategy,
    ) {
        parent::__construct($fileCollector, $safeRegex, $filesystem);
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
                'p',
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
     * Resolves CLI values and metadata/cache inputs, then delegates analysis
     * setup, processing and cleanup to the pipeline. It observes the returned
     * plan and actual execution result to preserve truthful exit status.
     *
     * @return int The exit code (0 for success, non-zero for failure).
     */
    #[Override]
    protected function executeCommand(): int
    {
        $this->useFileExtensionFromSource = true;

        // Filename strategy and metadata storage remain command-facing inputs.
        $targetFilenamePattern = $this->input->getOption('target-filename-pattern');

        if (is_string($targetFilenamePattern)) {
            $this->targetFilenamePattern    = $targetFilenamePattern;
            $this->exifDateFilenameStrategy = null;
        }

        // Resolve selected inputs once, before touching caches or shared services.
        $timezone       = $this->resolveTimezone($this->input);
        $mergeThreshold = $this->resolveMergeThreshold($this->input);
        $formatPriority = $this->resolveFormatPriority();
        $cacheDirectory = $this->resolveCacheDir();

        $this->exifMetadataProvider->clearCache();
        $this->exifMetadataProvider->setDefaultTimezone($timezone);

        $metadataCache = $this->configureProviderCache($this->exifMetadataProvider, $cacheDirectory);
        $signalCache   = $this->createPerceptualSignalCache($cacheDirectory);
        $this->reportCacheDiagnostic($metadataCache, $this->io);
        $this->reportCacheDiagnostic($signalCache, $this->io);

        $memoryAborted = false;

        try {
            if (!$this->processWithAssetGroups($mergeThreshold, $formatPriority, $signalCache)) {
                $this->io->error('Completed with errors. See the summary for actual results.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (AnalysisMemoryBudgetExceededException $exception) {
            $memoryAborted = true;
            $this->exifMetadataProvider->clearCache();
            $this->io->error(ConsoleText::neutralize($exception->getMessage()));

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->io->error(ConsoleText::neutralize($exception->getMessage()));

            return self::FAILURE;
        } finally {
            // Serializing an optional full snapshot would reintroduce growth
            // after a planned low-memory stop; keep the previous disk cache.
            if (!$memoryAborted) {
                $this->flushCache($metadataCache, $this->io);
                $this->flushCache($signalCache, $this->io);
            }
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
        if ($this->isSingleFile) {
            return parent::createFileIterator();
        }

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
                $fileExtensionRegex,
                $this->safeRegex,
            );
        }

        return $this->fileCollector
            ->createFileIterator(
                $this->sourceDirectory,
                $recursiveIterator
            );
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
     * @param float                 $mergeThreshold Validated selected maximum RMSE
     * @param list<string>          $formatPriority Selected canonical format preference
     * @param PerceptualSignalCache $signalCache    Current run signal storage, flushed after observed results
     *
     * @return bool Whether scanning, classification and execution completed without errors
     *
     * @throws RuntimeException                      If cross-group analysis exceeds its budget or circular swaps are detected
     * @throws AnalysisMemoryBudgetExceededException When analysis cannot retain sufficient pre-mutation headroom
     */
    private function processWithAssetGroups(float $mergeThreshold, array $formatPriority, PerceptualSignalCache $signalCache): bool
    {
        // Steps 1-6: build groups, classify, assign roles, resolve names, resolve collisions, validate
        $pipelineResult = $this->pipeline->run(
            $this->createFileIterator(),
            $this->getExifDateFilenameStrategy(),
            $this->duplicateIdentifierStrategy,
            $this->sourceDirectory,
            $this->useFileExtensionFromSource,
            $mergeThreshold,
            $formatPriority,
            $signalCache,
        );
        $analysisComplete = true;

        foreach ($pipelineResult->groups as $group) {
            if ($group->isClassificationDegraded()) {
                $analysisComplete = false;

                break;
            }
        }

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
            $this->maxDateDrift,
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
            linkConfig: $this->linkConfig,
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
        AnalysisMemoryBudget::assertHeadroom('pre-mutation execution gate');

        $executionResult = $this->executionPlanExecutor->executePlan($executionPlan, $this->dryRun);

        // Render summary
        $this->renameOutputRenderer->renderPlanSummary(
            $executionPlan,
            $result,
            $preview,
            $this->dryRun,
            $executionResult,
        );

        foreach ($result->skippedFiles as $skippedFile) {
            if ($skippedFile->isError()) {
                return false;
            }
        }

        return $analysisComplete && ($executionResult->runtimeErrors === 0) && ($executionResult->partialLivePhotoGroups === 0);
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
                $this->io->text(sprintf('  <fg=yellow>→</> %s', ConsoleText::escape(PathHelper::relativizePath($target, $this->sourceDirectory))));
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
                $this->io->text(sprintf('  <fg=yellow>→</> %s', ConsoleText::escape(implode(' ↔ ', $relativePaths))));
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
                $this->io->text(sprintf('  <fg=yellow>→</> %s', ConsoleText::escape(implode(' → ', $relativePaths))));
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
