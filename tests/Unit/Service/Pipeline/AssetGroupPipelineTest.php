<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Pipeline;

use LogicException;
use MagicSunday\Renamer\Helper\AnalysisMemoryBudget;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Service\CanonicalScorerInterface;
use MagicSunday\Renamer\Service\HashSubGroupingServiceInterface;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use MagicSunday\Renamer\Service\Pipeline\AssetGroupPipeline;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuilderInterface;
use MagicSunday\Renamer\Service\Pipeline\CollisionResolverInterface;
use MagicSunday\Renamer\Service\Pipeline\CrossGroupVideoDuplicateReconcilerInterface;
use MagicSunday\Renamer\Service\Pipeline\ExifRenamePipelineResult;
use MagicSunday\Renamer\Service\Pipeline\RoleAssignerInterface;
use MagicSunday\Renamer\Service\Pipeline\SubgroupClassifierInterface;
use MagicSunday\Renamer\Service\Pipeline\TargetNameResolverInterface;
use MagicSunday\Renamer\Service\RenamePlanValidator;
use MagicSunday\Renamer\Service\ValidationResult;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Verifies the orchestration boundaries inside AssetGroupPipeline.
 *
 * Feature Track A introduced a new regrouping phase between capture-group build
 * and subgroup classification. This test locks that order down so later refactors
 * do not accidentally push the behavior into the wrong phase.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[UsesClass(AnalysisMemoryBudget::class)]
#[CoversClass(AssetGroupPipeline::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(PipelineContext::class)]
#[UsesClass(ExifRenamePipelineResult::class)]
#[UsesClass(RenamePlanValidator::class)]
#[UsesClass(ValidationResult::class)]
final class AssetGroupPipelineTest extends TestCase
{
    /**
     * A builder failure propagates as a logic error while the workflow still
     * detaches its cache and restores grouping defaults. Setup uses explicit
     * format/source values, and cleanup performs no classification/source I/O.
     *
     * @return void
     */
    #[Test]
    public function builderFailureStillClosesAnalysisConfigurationScope(): void
    {
        $builder = self::createStub(CaptureGroupBuilderInterface::class);
        $builder->method('build')->willThrowException(new LogicException('synthetic builder failure'));
        $grouping = self::createMock(HashSubGroupingServiceInterface::class);
        $selected = [];
        $grouping->expects(self::exactly(2))->method('setMaxMergeRmse')->willReturnCallback(static function (?float $threshold) use (&$selected): void {
            $selected[] = $threshold;
        });
        $grouping->expects(self::never())->method('clearCache');
        $visual = self::createMock(PerceptualHashCalculatorInterface::class);
        $visual->expects(self::exactly(2))->method('setSignalCache')->with(null);
        $scorer = self::createMock(CanonicalScorerInterface::class);
        $scorer->expects(self::once())->method('setFormatPriority')->with(['jpg', 'heic']);
        $scorer->expects(self::once())->method('setSourceDirectory')->with('/synthetic');
        $pipeline = new AssetGroupPipeline($builder, self::createStub(SubgroupClassifierInterface::class), self::createStub(RoleAssignerInterface::class), self::createStub(TargetNameResolverInterface::class), self::createStub(CollisionResolverInterface::class), new RenamePlanValidator(), $grouping, $visual, $scorer);
        $this->expectException(LogicException::class);

        try {
            $pipeline->run(new RecursiveIteratorIterator(new RecursiveArrayIterator([])), $this->createRenameStrategyDouble(), $this->createDuplicateIdentifierStrategyDouble(), '/synthetic', mergeThreshold: 0.03, formatPriority: ['jpg', 'heic']);
        } finally {
            self::assertSame([0.03, null], $selected);
        }
    }

    /**
     * Verifies that the cross-group video reconciler runs after group building and
     * before subgroup classification on the same mutable group collection/context.
     *
     * The feature only works at this exact boundary. Running later would miss
     * cross-group pairs entirely, and running earlier would overload the builder.
     */
    #[Test]
    public function runInvokesCrossGroupVideoReconcilerBeforeSubgroupClassification(): void
    {
        $groups = new AssetGroupCollection();

        /** @var CaptureGroupBuilderInterface&MockObject $captureGroupBuilder */
        $captureGroupBuilder = $this->createMock(CaptureGroupBuilderInterface::class);
        /** @var SubgroupClassifierInterface&MockObject $subgroupClassifier */
        $subgroupClassifier = $this->createMock(SubgroupClassifierInterface::class);
        /** @var CrossGroupVideoDuplicateReconcilerInterface&MockObject $crossGroupVideoDuplicateReconciler */
        $crossGroupVideoDuplicateReconciler = $this->createMock(CrossGroupVideoDuplicateReconcilerInterface::class);
        /** @var RoleAssignerInterface&MockObject $roleAssigner */
        $roleAssigner = $this->createMock(RoleAssignerInterface::class);
        /** @var TargetNameResolverInterface&MockObject $targetNameResolver */
        $targetNameResolver = $this->createMock(TargetNameResolverInterface::class);
        /** @var CollisionResolverInterface&MockObject $collisionResolver */
        $collisionResolver           = $this->createMock(CollisionResolverInterface::class);
        $renameStrategy              = $this->createRenameStrategyDouble();
        $duplicateIdentifierStrategy = $this->createDuplicateIdentifierStrategyDouble();

        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator([]));
        $sequence = [];

        $captureGroupBuilder
            ->expects(self::once())
            ->method('build')
            ->willReturnCallback(static function () use ($groups, &$sequence): AssetGroupCollection {
                $sequence[] = 'build';

                return $groups;
            });

        $crossGroupVideoDuplicateReconciler
            ->expects(self::once())
            ->method('reconcile')
            ->with(
                self::identicalTo($groups),
                self::isInstanceOf(PipelineContext::class),
            )
            ->willReturnCallback(static function () use (&$sequence): void {
                $sequence[] = 'reconcile';
            });

        $subgroupClassifier
            ->expects(self::once())
            ->method('classify')
            ->with(self::identicalTo($groups))
            ->willReturnCallback(static function () use (&$sequence): void {
                $sequence[] = 'classify';
            });

        $roleAssigner
            ->expects(self::once())
            ->method('assign')
            ->with(self::identicalTo($groups), self::isInstanceOf(PipelineContext::class));

        $targetNameResolver
            ->expects(self::once())
            ->method('resolve')
            ->with(self::identicalTo($groups), false);

        $collisionResolver
            ->expects(self::once())
            ->method('resolve')
            ->with(self::identicalTo($groups), self::isInstanceOf(PipelineContext::class));

        $pipeline = new AssetGroupPipeline(
            $captureGroupBuilder,
            $subgroupClassifier,
            $roleAssigner,
            $targetNameResolver,
            $collisionResolver,
            new RenamePlanValidator(),
            self::createStub(HashSubGroupingServiceInterface::class),
            self::createStub(PerceptualHashCalculatorInterface::class),
            self::createStub(CanonicalScorerInterface::class),
            $crossGroupVideoDuplicateReconciler,
        );

        $pipeline->run(
            $iterator,
            $renameStrategy,
            $duplicateIdentifierStrategy,
            '/photos',
        );

        self::assertSame(['build', 'reconcile', 'classify'], $sequence);
    }

    /**
     * Provides a minimal rename strategy test double for pipeline orchestration tests.
     *
     * The strategy never participates in the asserted interaction, but the pipeline
     * requires a concrete implementation to satisfy its public contract.
     *
     * @return RenameStrategyInterface Stable dummy implementation for orchestration-only tests
     */
    private function createRenameStrategyDouble(): RenameStrategyInterface
    {
        return new class implements RenameStrategyInterface {
            /**
             * Returns a deterministic placeholder filename because the pipeline test only
             * verifies orchestration order and does not inspect rename output.
             *
             * @param SplFileInfo $splFileInfo Ignored source file from the pipeline contract
             *
             * @return string Stable placeholder filename for the unused strategy call path
             */
            public function generateFilename(SplFileInfo $splFileInfo): string
            {
                return 'unused.jpg';
            }
        };
    }

    /**
     * Provides a minimal duplicate identifier strategy test double for pipeline orchestration tests.
     *
     * The classifier ordering test does not care about the generated identifier value,
     * only that the pipeline can be invoked with a valid strategy instance.
     *
     * @return DuplicateIdentifierStrategyInterface Stable dummy implementation for orchestration-only tests
     */
    private function createDuplicateIdentifierStrategyDouble(): DuplicateIdentifierStrategyInterface
    {
        return new class implements DuplicateIdentifierStrategyInterface {
            /**
             * Returns a deterministic placeholder identifier because the pipeline test only
             * verifies service ordering and never asserts grouping semantics.
             *
             * @param SplFileInfo $sourceFileInfo Ignored source file from the pipeline contract
             * @param SplFileInfo $targetFileInfo Ignored target file from the pipeline contract
             *
             * @return string|false Stable placeholder grouping key for the unused strategy call path
             */
            public function generateIdentifier(SplFileInfo $sourceFileInfo, SplFileInfo $targetFileInfo): string|false
            {
                if (($sourceFileInfo->getPathname() === '') || ($targetFileInfo->getPathname() === '')) {
                    return false;
                }

                return 'unused';
            }
        };
    }
}
