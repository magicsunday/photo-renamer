<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Exception\AnalysisMemoryBudgetExceededException;
use MagicSunday\Renamer\Helper\AnalysisMemoryBudget;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Service\CanonicalScorerInterface;
use MagicSunday\Renamer\Service\HashSubGroupingServiceInterface;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use MagicSunday\Renamer\Service\RenamePlanValidator;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use RecursiveIterator;
use RecursiveIteratorIterator;

/**
 * Orchestrates the AssetGroup pipeline: build groups, classify subgroups,
 * assign roles, resolve names, resolve collisions, and validate the rename plan.
 *
 * Encapsulates the 5-step core pipeline plus validation so that command classes
 * only need the workflow boundary. It also owns explicit run setup/cleanup
 * for scoring and classification, so CLI callers do not configure internals.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class AssetGroupPipeline
{
    /**
     * @param CaptureGroupBuilderInterface                     $captureGroupBuilder                Build capture groups from file iterator
     * @param SubgroupClassifierInterface                      $subgroupClassifier                 Classify items into content-identity clusters
     * @param RoleAssignerInterface                            $roleAssigner                       Assign Canonical/Duplicate/Companion roles
     * @param TargetNameResolverInterface                      $targetNameResolver                 Compute proposed target names
     * @param CollisionResolverInterface                       $collisionResolver                  Deduplicate proposed names against disk index
     * @param RenamePlanValidator                              $renamePlanValidator                Validate the rename plan for conflicts
     * @param HashSubGroupingServiceInterface                  $hashSubGroupingService             Content classification and scoped merge preference
     * @param PerceptualHashCalculatorInterface                $perceptualHashCalculator           Scoped persistent signals and native cleanup
     * @param CanonicalScorerInterface                         $canonicalScorer                    Scoring configured from explicit run values
     * @param CrossGroupVideoDuplicateReconcilerInterface|null $crossGroupVideoDuplicateReconciler Reconciles exact-content videos across groups before subgroup classification
     */
    public function __construct(
        private CaptureGroupBuilderInterface $captureGroupBuilder,
        private SubgroupClassifierInterface $subgroupClassifier,
        private RoleAssignerInterface $roleAssigner,
        private TargetNameResolverInterface $targetNameResolver,
        private CollisionResolverInterface $collisionResolver,
        private RenamePlanValidator $renamePlanValidator,
        private HashSubGroupingServiceInterface $hashSubGroupingService,
        private PerceptualHashCalculatorInterface $perceptualHashCalculator,
        private CanonicalScorerInterface $canonicalScorer,
        private ?CrossGroupVideoDuplicateReconcilerInterface $crossGroupVideoDuplicateReconciler = null,
    ) {
    }

    /**
     * Run the AssetGroup pipeline: build groups, classify subgroups, assign roles,
     * resolve names, resolve collisions, and validate the rename plan.
     *
     * Creates a fresh PipelineContext internally so callers do not need to construct
     * or pass one.
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner>    $iterator                    Iterator yielding candidate files
     * @param RenameStrategyInterface              $renameStrategy              Strategy to compute target filenames
     * @param DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy Strategy to generate grouping keys
     * @param string                               $sourceDirectory             Absolute path to the source directory
     * @param bool                                 $useFileExtensionFromSource  When true, preserve source extension
     * @param float                                $mergeThreshold              Validated maximum RMSE, still capped by conservative policy
     * @param list<string>                         $formatPriority              Explicit canonical format preference for this invocation
     * @param PerceptualSignalCache|null           $signalCache                 Optional infrastructure storage, separate from business values
     *
     * @return ExifRenamePipelineResult The fully processed groups with proposed names, context, and validation result
     *
     * @throws AnalysisMemoryBudgetExceededException When retained analysis reaches its pre-mutation budget
     */
    public function run(
        RecursiveIteratorIterator $iterator,
        RenameStrategyInterface $renameStrategy,
        DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
        string $sourceDirectory,
        bool $useFileExtensionFromSource = false,
        float $mergeThreshold = Constants::DEFAULT_MERGE_THRESHOLD,
        array $formatPriority = Constants::DEFAULT_FORMAT_PRIORITY,
        ?PerceptualSignalCache $signalCache = null,
    ): ExifRenamePipelineResult {
        try {
            $this->perceptualHashCalculator->setSignalCache($signalCache);
            $this->hashSubGroupingService->setMaxMergeRmse($mergeThreshold);
            $this->canonicalScorer->setFormatPriority($formatPriority);
            $this->canonicalScorer->setSourceDirectory($sourceDirectory);

            $context = new PipelineContext($sourceDirectory);

            // Step 1: Build capture groups
            $groups = $this->captureGroupBuilder->build(
                $iterator,
                $renameStrategy,
                $duplicateIdentifierStrategy,
                $context,
            );

            AnalysisMemoryBudget::assertHeadroom('cross-group analysis');
            $this->crossGroupVideoDuplicateReconciler?->reconcile($groups, $context);

            // Step 2: Classify subgroups
            AnalysisMemoryBudget::assertHeadroom('subgroup classification');
            $this->subgroupClassifier->classify($groups);

            // Step 3: Assign roles (scoring + companion detection)
            AnalysisMemoryBudget::assertHeadroom('role assignment');
            $this->roleAssigner->assign($groups, $context);

            // Step 4: Compute target names
            AnalysisMemoryBudget::assertHeadroom('target naming');
            $this->targetNameResolver->resolve($groups, $useFileExtensionFromSource);

            // Step 5: Resolve collisions
            AnalysisMemoryBudget::assertHeadroom('collision resolution');
            $this->collisionResolver->resolve($groups, $context);

            // Step 6: Validate rename plan
            AnalysisMemoryBudget::assertHeadroom('plan validation');
            $validationResult = $this->renamePlanValidator->validate($groups);

            return new ExifRenamePipelineResult($groups, $context, $validationResult);
        } finally {
            // Classification clears each group while sources still exist. This
            // boundary only releases transient state and restores run defaults.
            $this->perceptualHashCalculator->setSignalCache(null);
            $this->hashSubGroupingService->setMaxMergeRmse(null);
        }
    }
}
