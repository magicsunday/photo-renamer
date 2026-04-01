<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Execution;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Execution\ExecutionGroup;
use MagicSunday\Renamer\Model\Execution\ExecutionItem;
use MagicSunday\Renamer\Model\Execution\ExecutionItemType;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\ItemRole;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Service\Execution\ExecutionPlanBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies that ExecutionPlanBuilder correctly projects AssetGroupCollection
 * into an ExecutionPlan without re-running detection or making new choices.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(ExecutionPlanBuilder::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(ExecutionGroup::class)]
#[UsesClass(ExecutionItem::class)]
#[UsesClass(ExecutionPlan::class)]
#[UsesClass(PipelineContext::class)]
#[UsesClass(Constants::class)]
final class ExecutionPlanBuilderTest extends TestCase
{
    /**
     * Canonical item is projected with correct type, paths, and flags.
     */
    #[Test]
    public function canonicalItemProjectedCorrectly(): void
    {
        $builder = new ExecutionPlanBuilder();
        $context = new PipelineContext('/photos');

        $item = new AssetItem(new SplFileInfo('/photos/IMG_0001.heic'), ItemRole::Canonical)
            ->withProposedName('/photos/2024-01-01_12-00-00-000.heic');
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $plan = $builder->build($groups, $context);

        self::assertCount(1, $plan->groups);
        self::assertCount(1, $plan->groups[0]->items);

        $executionItem = $plan->groups[0]->items[0];

        self::assertSame('/photos/IMG_0001.heic', $executionItem->sourcePath);
        self::assertSame('/photos/2024-01-01_12-00-00-000.heic', $executionItem->targetPath);
        self::assertSame(ExecutionItemType::Canonical, $executionItem->type);
        self::assertTrue($executionItem->renameRequired);
        self::assertFalse($executionItem->isNoOp);
        self::assertSame('g1', $executionItem->groupKey);
        self::assertFalse($executionItem->isDuplicateTarget);
    }

    /**
     * Companion item is projected correctly and group is flagged as Live Photo.
     */
    #[Test]
    public function companionItemProjectedCorrectly(): void
    {
        $builder = new ExecutionPlanBuilder();
        $context = new PipelineContext('/photos');

        $canonical = new AssetItem(new SplFileInfo('/photos/IMG_0001.heic'), ItemRole::Canonical)
            ->withProposedName('/photos/2024-01-01_12-00-00-000.heic');
        $companion = new AssetItem(new SplFileInfo('/photos/IMG_0001.mov'), ItemRole::Companion)
            ->withProposedName('/photos/2024-01-01_12-00-00-000.mov');
        $group = $this->createGroup('g1', [$canonical, $companion]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $plan = $builder->build($groups, $context);

        self::assertTrue($plan->groups[0]->isLivePhotoGroup);

        $companionItem = $plan->groups[0]->items[1];

        self::assertSame(ExecutionItemType::Companion, $companionItem->type);
        self::assertSame('/photos/IMG_0001.mov', $companionItem->sourcePath);
        self::assertSame('/photos/2024-01-01_12-00-00-000.mov', $companionItem->targetPath);
    }

    /**
     * Duplicate item targeting a path with the duplicate identifier is flagged.
     */
    #[Test]
    public function duplicateItemProjectedCorrectly(): void
    {
        $builder = new ExecutionPlanBuilder();
        $context = new PipelineContext('/photos');

        $item = new AssetItem(new SplFileInfo('/photos/IMG_0002.heic'), ItemRole::Duplicate)
            ->withProposedName('/photos/2024-01-01_12-00-00-000-duplicate-001.heic');
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $plan = $builder->build($groups, $context);

        $executionItem = $plan->groups[0]->items[0];

        self::assertSame(ExecutionItemType::Duplicate, $executionItem->type);
        self::assertTrue($executionItem->isDuplicateTarget);
        self::assertTrue($executionItem->renameRequired);
    }

    /**
     * Ambiguous item is projected with the Ambiguous execution type.
     */
    #[Test]
    public function ambiguousItemProjectedCorrectly(): void
    {
        $builder = new ExecutionPlanBuilder();
        $context = new PipelineContext('/photos');

        $item = new AssetItem(new SplFileInfo('/photos/IMG_0003.heic'), ItemRole::Ambiguous)
            ->withProposedName('/photos/2024-01-01_12-00-00-000-duplicate-002.heic');
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $plan = $builder->build($groups, $context);

        $executionItem = $plan->groups[0]->items[0];

        self::assertSame(ExecutionItemType::Ambiguous, $executionItem->type);
    }

    /**
     * Items are ordered: Canonical, Companion, Duplicate, Ambiguous.
     */
    #[Test]
    public function itemOrderIsCanonicalCompanionDuplicateAmbiguous(): void
    {
        $builder = new ExecutionPlanBuilder();
        $context = new PipelineContext('/photos');

        // Add items in scrambled order
        $ambiguous = new AssetItem(new SplFileInfo('/photos/IMG_0004.heic'), ItemRole::Ambiguous)
            ->withProposedName('/photos/2024-01-01_12-00-00-000-duplicate-003.heic');
        $duplicate = new AssetItem(new SplFileInfo('/photos/IMG_0003.heic'), ItemRole::Duplicate)
            ->withProposedName('/photos/2024-01-01_12-00-00-000-duplicate-001.heic');
        $canonical = new AssetItem(new SplFileInfo('/photos/IMG_0001.heic'), ItemRole::Canonical)
            ->withProposedName('/photos/2024-01-01_12-00-00-000.heic');
        $companion = new AssetItem(new SplFileInfo('/photos/IMG_0001.mov'), ItemRole::Companion)
            ->withProposedName('/photos/2024-01-01_12-00-00-000.mov');

        $group = $this->createGroup('g1', [$ambiguous, $duplicate, $canonical, $companion]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $plan = $builder->build($groups, $context);

        $items = $plan->groups[0]->items;

        self::assertCount(4, $items);
        self::assertSame(ExecutionItemType::Canonical, $items[0]->type);
        self::assertSame(ExecutionItemType::Companion, $items[1]->type);
        self::assertSame(ExecutionItemType::Duplicate, $items[2]->type);
        self::assertSame(ExecutionItemType::Ambiguous, $items[3]->type);
    }

    /**
     * Quality flags (fallbackDate) are projected from PipelineContext.
     */
    #[Test]
    public function qualityFlagsProjectedFromContext(): void
    {
        $builder = new ExecutionPlanBuilder();
        $context = new PipelineContext('/photos');
        $context->addFallbackDateFile('/photos/IMG_0001.heic');

        $item = new AssetItem(new SplFileInfo('/photos/IMG_0001.heic'), ItemRole::Canonical)
            ->withProposedName('/photos/2024-01-01_12-00-00-000.heic');
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $plan = $builder->build($groups, $context);

        $executionItem = $plan->groups[0]->items[0];

        self::assertTrue($executionItem->isFallbackDate);
        self::assertFalse($executionItem->isAmbiguousTimezone);
        self::assertFalse($executionItem->isLivePhotoConflict);
    }

    /**
     * Live Photo conflict flag is projected from PipelineContext.
     */
    #[Test]
    public function livePhotoConflictFlagProjected(): void
    {
        $builder = new ExecutionPlanBuilder();
        $context = new PipelineContext('/photos');
        $context->addLivePhotoConflictFile('/photos/IMG_0001.mov');

        $item = new AssetItem(new SplFileInfo('/photos/IMG_0001.mov'), ItemRole::Companion)
            ->withProposedName('/photos/2024-01-01_12-00-00-000.mov');
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $plan = $builder->build($groups, $context);

        $executionItem = $plan->groups[0]->items[0];

        self::assertTrue($executionItem->isLivePhotoConflict);
    }

    /**
     * No-op detected when source path equals target path.
     */
    #[Test]
    public function noOpDetectedWhenSourceEqualsTarget(): void
    {
        $builder = new ExecutionPlanBuilder();
        $context = new PipelineContext('/photos');

        $item = new AssetItem(new SplFileInfo('/photos/2024-01-01_12-00-00-000.heic'), ItemRole::Canonical)
            ->withProposedName('/photos/2024-01-01_12-00-00-000.heic');
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $plan = $builder->build($groups, $context);

        $executionItem = $plan->groups[0]->items[0];

        self::assertFalse($executionItem->renameRequired);
        self::assertTrue($executionItem->isNoOp);
    }

    /**
     * Decision log from the AssetGroup is carried over to the ExecutionGroup.
     */
    #[Test]
    public function decisionLogCarriedOver(): void
    {
        $builder = new ExecutionPlanBuilder();
        $context = new PipelineContext('/photos');

        $item = new AssetItem(new SplFileInfo('/photos/IMG_0001.heic'), ItemRole::Canonical)
            ->withProposedName('/photos/2024-01-01_12-00-00-000.heic');
        $group = $this->createGroup('g1', [$item]);
        $group->addDecision('Selected IMG_0001.heic as canonical');
        $group->addDecision('Format priority: HEIC > JPG');

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $plan = $builder->build($groups, $context);

        self::assertSame(
            ['Selected IMG_0001.heic as canonical', 'Format priority: HEIC > JPG'],
            $plan->groups[0]->decisionLog,
        );
    }

    /**
     * Empty group collection produces an empty execution plan.
     */
    #[Test]
    public function emptyGroupCollectionProducesEmptyPlan(): void
    {
        $builder = new ExecutionPlanBuilder();
        $context = new PipelineContext('/photos');
        $groups  = new AssetGroupCollection();

        $plan = $builder->build($groups, $context);

        self::assertCount(0, $plan->groups);
        self::assertSame(0, $plan->groupCount());
        self::assertSame(0, $plan->totalItemCount());
    }

    /**
     * Item without proposed name uses source path as target (no-op).
     */
    #[Test]
    public function itemWithoutProposedNameUsesSourceAsTarget(): void
    {
        $builder = new ExecutionPlanBuilder();
        $context = new PipelineContext('/photos');

        $item  = new AssetItem(new SplFileInfo('/photos/IMG_0001.heic'), ItemRole::Canonical);
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $plan = $builder->build($groups, $context);

        $executionItem = $plan->groups[0]->items[0];

        self::assertSame('/photos/IMG_0001.heic', $executionItem->sourcePath);
        self::assertSame('/photos/IMG_0001.heic', $executionItem->targetPath);
        self::assertFalse($executionItem->renameRequired);
        self::assertTrue($executionItem->isNoOp);
    }

    /**
     * Degraded group is still projected, with a warning in the decision log.
     */
    #[Test]
    public function degradedGroupStillProjectedWithWarning(): void
    {
        $builder = new ExecutionPlanBuilder();
        $context = new PipelineContext('/photos');

        $item = new AssetItem(new SplFileInfo('/photos/IMG_0001.heic'), ItemRole::Canonical)
            ->withProposedName('/photos/2024-01-01_12-00-00-000.heic');
        $group = $this->createGroup('g1', [$item]);
        $group->markClassificationFailed('Imagick not available');

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $plan = $builder->build($groups, $context);

        self::assertCount(1, $plan->groups);

        $decisionLog = $plan->groups[0]->decisionLog;

        self::assertNotEmpty($decisionLog);
        self::assertStringContainsString('degraded', $decisionLog[0]);
        self::assertStringContainsString('Imagick not available', $decisionLog[0]);
    }

    /**
     * Ambiguous timezone item is not executable and carries a block reason.
     */
    #[Test]
    public function ambiguousTimezoneItemIsNotExecutable(): void
    {
        $builder = new ExecutionPlanBuilder();
        $context = new PipelineContext('/photos');
        $context->addAmbiguousTimezoneFile('/photos/clip.mp4');

        $item = new AssetItem(new SplFileInfo('/photos/clip.mp4'), ItemRole::Canonical)
            ->withProposedName('/photos/2024-01-01_12-00-00-000.mp4');
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $plan = $builder->build($groups, $context);

        $executionItem = $plan->groups[0]->items[0];

        self::assertFalse($executionItem->isExecutable);
        self::assertNotNull($executionItem->executionBlockReason);
        self::assertStringContainsString('Ambiguous timezone', $executionItem->executionBlockReason);
    }

    /**
     * Fallback date item is not executable and carries a block reason.
     */
    #[Test]
    public function fallbackDateItemIsNotExecutable(): void
    {
        $builder = new ExecutionPlanBuilder();
        $context = new PipelineContext('/photos');
        $context->addFallbackDateFile('/photos/scan.jpg');

        $item = new AssetItem(new SplFileInfo('/photos/scan.jpg'), ItemRole::Canonical)
            ->withProposedName('/photos/2024-01-01_12-00-00-000.jpg');
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $plan = $builder->build($groups, $context);

        $executionItem = $plan->groups[0]->items[0];

        self::assertFalse($executionItem->isExecutable);
        self::assertNotNull($executionItem->executionBlockReason);
        self::assertStringContainsString('Fallback date', $executionItem->executionBlockReason);
    }

    /**
     * Live Photo conflict item is not executable and carries a block reason.
     */
    #[Test]
    public function livePhotoConflictItemIsNotExecutable(): void
    {
        $builder = new ExecutionPlanBuilder();
        $context = new PipelineContext('/photos');
        $context->addLivePhotoConflictFile('/photos/IMG_0001.mov');

        $item = new AssetItem(new SplFileInfo('/photos/IMG_0001.mov'), ItemRole::Companion)
            ->withProposedName('/photos/2024-01-01_12-00-00-000.mov');
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $plan = $builder->build($groups, $context);

        $executionItem = $plan->groups[0]->items[0];

        self::assertFalse($executionItem->isExecutable);
        self::assertNotNull($executionItem->executionBlockReason);
        self::assertStringContainsString('Live Photo conflict', $executionItem->executionBlockReason);
    }

    /**
     * Normal rename item is executable with no block reason.
     */
    #[Test]
    public function normalRenameItemIsExecutable(): void
    {
        $builder = new ExecutionPlanBuilder();
        $context = new PipelineContext('/photos');

        $item = new AssetItem(new SplFileInfo('/photos/IMG_0001.heic'), ItemRole::Canonical)
            ->withProposedName('/photos/2024-01-01_12-00-00-000.heic');
        $group = $this->createGroup('g1', [$item]);

        $groups = new AssetGroupCollection();
        $groups->set('g1', $group);

        $plan = $builder->build($groups, $context);

        $executionItem = $plan->groups[0]->items[0];

        self::assertTrue($executionItem->isExecutable);
        self::assertNull($executionItem->executionBlockReason);
    }

    /**
     * @param list<AssetItem> $items
     */
    private function createGroup(string $groupKey, array $items): AssetGroup
    {
        $group = new AssetGroup($groupKey);

        foreach ($items as $item) {
            $group->addItem($item);
        }

        return $group;
    }
}
