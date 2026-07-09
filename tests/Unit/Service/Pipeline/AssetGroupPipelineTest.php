<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Pipeline;

use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\PipelineContext;
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
#[CoversClass(AssetGroupPipeline::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(PipelineContext::class)]
#[UsesClass(ExifRenamePipelineResult::class)]
#[UsesClass(RenamePlanValidator::class)]
#[UsesClass(ValidationResult::class)]
final class AssetGroupPipelineTest extends TestCase
{
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
