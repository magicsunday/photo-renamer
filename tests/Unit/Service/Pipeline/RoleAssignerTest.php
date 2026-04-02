<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Pipeline;

use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\ItemRole;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Service\CanonicalScorerInterface;
use MagicSunday\Renamer\Service\MediaCompatibilityPolicy;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\Pipeline\CompanionDetectorInterface;
use MagicSunday\Renamer\Service\Pipeline\RoleAssigner;
use MagicSunday\Renamer\Service\Pipeline\RoleAssignerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the RoleAssigner orchestrator correctly assigns roles (Canonical,
 * Duplicate, Companion) to items within each AssetGroup by delegating to
 * CanonicalScorer and CompanionDetector.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(RoleAssigner::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(PipelineContext::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(MediaCompatibilityPolicy::class)]
final class RoleAssignerTest extends TestCase
{
    /**
     * A single-item group should keep its default Canonical role
     * without calling the scorer.
     */
    #[Test]
    public function singleItemGroupKeepsCanonicalRole(): void
    {
        $item = new AssetItem(new SplFileInfo('/photos/IMG_0001.heic'));

        $group = new AssetGroup('group-1');
        $group->addItem($item);

        $scorer = $this->createMock(CanonicalScorerInterface::class);
        $scorer->expects(self::never())->method('scoreItems');
        $scorer->expects(self::never())->method('selectCanonical');

        $companionDetector = $this->createMock(CompanionDetectorInterface::class);
        $companionDetector->expects(self::never())->method('detect');

        $assigner = $this->createAssigner($scorer, $companionDetector);

        $groups = new AssetGroupCollection();
        $groups->set('group-1', $group);

        $context = new PipelineContext('/photos');

        $assigner->assign($groups, $context);

        self::assertSame(ItemRole::Canonical, $group->getItems()[0]->role);
    }

    /**
     * The item with the highest score from the scorer becomes canonical;
     * the other becomes a duplicate.
     */
    #[Test]
    public function highestScoredItemBecomesCanonical(): void
    {
        $heic = new AssetItem(new SplFileInfo('/photos/IMG_0001.heic'));
        $jpg  = new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg'));

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($jpg);

        // After scoring, heic gets score 100, jpg gets score 50
        $scoredHeic = $heic->withScore(100, ['format priority']);
        $scoredJpg  = $jpg->withScore(50, ['lower priority']);

        $scorer = $this->createMock(CanonicalScorerInterface::class);
        $scorer->expects(self::once())
            ->method('scoreItems')
            ->willReturnCallback(static function (AssetGroup $group) use ($heic, $scoredHeic, $jpg, $scoredJpg): void {
                $group->replaceItem($heic, $scoredHeic);
                $group->replaceItem($jpg, $scoredJpg);
            });

        $scorer->expects(self::once())
            ->method('selectCanonical')
            ->willReturn($scoredHeic);

        $companionDetector = $this->createMock(CompanionDetectorInterface::class);
        $companionDetector->expects(self::once())
            ->method('detect')
            ->willReturn([]);

        $assigner = $this->createAssigner($scorer, $companionDetector);

        $groups = new AssetGroupCollection();
        $groups->set('group-1', $group);

        $context = new PipelineContext('/photos');

        $assigner->assign($groups, $context);

        // Find the items by pathname after assignment
        $canonicalItem = $group->getItemByPath('/photos/IMG_0001.heic');
        $duplicateItem = $group->getItemByPath('/photos/IMG_0001.jpg');

        self::assertNotNull($canonicalItem);
        self::assertSame(ItemRole::Canonical, $canonicalItem->role);

        self::assertNotNull($duplicateItem);
        self::assertSame(ItemRole::Duplicate, $duplicateItem->role);
    }

    /**
     * Verifies that companions are correctly identified and assigned the
     * appropriate role.
     */
    #[Test]
    public function companionDetectedAndRoleAssigned(): void
    {
        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: 'abc',
        );

        $mov = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            contentIdentifier: 'abc',
        );

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($mov);

        $scoredHeic = $heic->withScore(100, ['format priority']);
        $scoredMov  = $mov->withScore(50, ['video format']);

        $scorer = $this->createScorerMock($heic, $scoredHeic, $mov, $scoredMov);

        $companionDetector = $this->createMock(CompanionDetectorInterface::class);
        $companionDetector->expects(self::once())
            ->method('detect')
            ->willReturn(['/photos/IMG_0001.mov' => true]);

        $assigner = $this->createAssigner($scorer, $companionDetector);

        $groups = new AssetGroupCollection();
        $groups->set('group-1', $group);

        $context = new PipelineContext('/photos');

        $assigner->assign($groups, $context);

        $companionItem = $group->getItemByPath('/photos/IMG_0001.mov');

        self::assertNotNull($companionItem);
        self::assertSame(ItemRole::Companion, $companionItem->role);
    }

    /**
     * Ensures that items that are neither main nor companion files receive
     * the role "Duplicate".
     */
    #[Test]
    public function nonCanonicalNonCompanionBecomesDuplicate(): void
    {
        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: 'abc',
        );

        $mov = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            contentIdentifier: 'abc',
        );

        $jpg = new AssetItem(
            new SplFileInfo('/other/IMG_0001.jpg'),
        );

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($mov);
        $group->addItem($jpg);

        $scoredHeic = $heic->withScore(100, ['format priority']);
        $scoredMov  = $mov->withScore(50, ['video format']);
        $scoredJpg  = $jpg->withScore(40, ['lower priority']);

        $scorer = $this->createMock(CanonicalScorerInterface::class);
        $scorer->expects(self::once())
            ->method('scoreItems')
            ->willReturnCallback(static function (AssetGroup $group) use ($heic, $scoredHeic, $mov, $scoredMov, $jpg, $scoredJpg): void {
                $group->replaceItem($heic, $scoredHeic);
                $group->replaceItem($mov, $scoredMov);
                $group->replaceItem($jpg, $scoredJpg);
            });

        $scorer->expects(self::once())
            ->method('selectCanonical')
            ->willReturn($scoredHeic);

        $companionDetector = $this->createMock(CompanionDetectorInterface::class);
        $companionDetector->expects(self::once())
            ->method('detect')
            ->willReturn(['/photos/IMG_0001.mov' => true]);

        $assigner = $this->createAssigner($scorer, $companionDetector);

        $groups = new AssetGroupCollection();
        $groups->set('group-1', $group);

        $context = new PipelineContext('/photos');

        $assigner->assign($groups, $context);

        $canonicalItem = $group->getItemByPath('/photos/IMG_0001.heic');
        $companionItem = $group->getItemByPath('/photos/IMG_0001.mov');
        $duplicateItem = $group->getItemByPath('/other/IMG_0001.jpg');

        self::assertNotNull($canonicalItem);
        self::assertSame(ItemRole::Canonical, $canonicalItem->role);

        self::assertNotNull($companionItem);
        self::assertSame(ItemRole::Companion, $companionItem->role);

        self::assertNotNull($duplicateItem);
        self::assertSame(ItemRole::Duplicate, $duplicateItem->role);
    }

    /**
     * Verifies that the canonical choice is documented in the asset group's
     * decision log.
     */
    #[Test]
    public function decisionLogDocumentsCanonicalChoice(): void
    {
        $heic = new AssetItem(new SplFileInfo('/photos/IMG_0001.heic'));
        $jpg  = new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg'));

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($jpg);

        $scoredHeic = $heic->withScore(100, ['format priority']);
        $scoredJpg  = $jpg->withScore(50, ['lower priority']);

        $scorer = $this->createScorerMock($heic, $scoredHeic, $jpg, $scoredJpg);

        $companionDetector = self::createStub(CompanionDetectorInterface::class);
        $companionDetector->method('detect')->willReturn([]);

        $assigner = $this->createAssigner($scorer, $companionDetector);

        $groups = new AssetGroupCollection();
        $groups->set('group-1', $group);

        $context = new PipelineContext('/photos');

        $assigner->assign($groups, $context);

        $log = $group->getDecisionLog();

        self::assertNotEmpty($log);
        self::assertStringContainsString('Canonical: IMG_0001.heic', $log[0]);
        self::assertStringContainsString('score 100', $log[0]);
        self::assertStringContainsString('format priority', $log[0]);
    }

    /**
     * Checks if the assignment of a companion file is correctly documented
     * in the decision log.
     */
    #[Test]
    public function decisionLogDocumentsCompanion(): void
    {
        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: 'abc',
        );

        $mov = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            contentIdentifier: 'abc',
        );

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($mov);

        $scoredHeic = $heic->withScore(100, ['format priority']);
        $scoredMov  = $mov->withScore(50, ['video format']);

        $scorer = $this->createScorerMock($heic, $scoredHeic, $mov, $scoredMov);

        $companionDetector = self::createStub(CompanionDetectorInterface::class);
        $companionDetector->method('detect')
            ->willReturn(['/photos/IMG_0001.mov' => true]);

        $assigner = $this->createAssigner($scorer, $companionDetector);

        $groups = new AssetGroupCollection();
        $groups->set('group-1', $group);

        $context = new PipelineContext('/photos');

        $assigner->assign($groups, $context);

        $log = $group->getDecisionLog();

        // Should contain both canonical and companion entries
        self::assertCount(2, $log);
        self::assertStringContainsString('Companion: IMG_0001.mov', $log[1]);
        self::assertStringContainsString('Live Photo pair', $log[1]);
    }

    /**
     * Ensures that quality flags (e.g., fallback date) are transferred from
     * the main file to the companion file to guarantee consistency during
     * renaming.
     */
    #[Test]
    public function qualityFlagsPropagateFromStillToCompanion(): void
    {
        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: 'abc',
        );

        $mov = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            contentIdentifier: 'abc',
        );

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($mov);

        $scoredHeic = $heic->withScore(100, ['format priority']);
        $scoredMov  = $mov->withScore(50, ['video format']);

        $scorer = $this->createScorerMock($heic, $scoredHeic, $mov, $scoredMov);

        $companionDetector = self::createStub(CompanionDetectorInterface::class);
        $companionDetector->method('detect')
            ->willReturn(['/photos/IMG_0001.mov' => true]);

        $assigner = $this->createAssigner($scorer, $companionDetector);

        $groups = new AssetGroupCollection();
        $groups->set('group-1', $group);

        $context = new PipelineContext('/photos');

        // Canonical has fallback date flag
        $context->addFallbackDateFile('/photos/IMG_0001.heic');
        // Canonical has ambiguous timezone flag
        $context->addAmbiguousTimezoneFile('/photos/IMG_0001.heic');

        $assigner->assign($groups, $context);

        // Companion should inherit both flags
        self::assertArrayHasKey('/photos/IMG_0001.mov', $context->getFallbackDateFiles());
        self::assertArrayHasKey('/photos/IMG_0001.mov', $context->getAmbiguousTimezoneFiles());
    }

    /**
     * Verifies that fallback date information from video main files is NOT
     * transferred to still images (special case of consistency rules).
     */
    #[Test]
    public function videoCanonicalDoesNotPropagateFallbackDateToCompanion(): void
    {
        $mov = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.mov'),
            contentIdentifier: 'abc',
        );

        $heic = new AssetItem(
            new SplFileInfo('/photos/IMG_0001.heic'),
            contentIdentifier: 'abc',
        );

        $group = new AssetGroup('group-1');
        $group->addItem($mov);
        $group->addItem($heic);

        // MOV is canonical (highest score), HEIC is companion
        $scoredMov  = $mov->withScore(100, ['video canonical']);
        $scoredHeic = $heic->withScore(50, ['still companion']);

        $scorer = $this->createScorerMock($mov, $scoredMov, $heic, $scoredHeic);

        $companionDetector = self::createStub(CompanionDetectorInterface::class);
        $companionDetector->method('detect')
            ->willReturn(['/photos/IMG_0001.heic' => true]);

        $assigner = $this->createAssigner($scorer, $companionDetector);

        $groups = new AssetGroupCollection();
        $groups->set('group-1', $group);

        $context = new PipelineContext('/photos');

        // Canonical (MOV) has both fallback date and ambiguous timezone flags
        $context->addFallbackDateFile('/photos/IMG_0001.mov');
        $context->addAmbiguousTimezoneFile('/photos/IMG_0001.mov');

        $assigner->assign($groups, $context);

        // Companion should NOT inherit fallback date (video canonicals do not propagate it)
        self::assertArrayNotHasKey('/photos/IMG_0001.heic', $context->getFallbackDateFiles());

        // Companion SHOULD inherit ambiguous timezone (propagated unconditionally)
        self::assertArrayHasKey('/photos/IMG_0001.heic', $context->getAmbiguousTimezoneFiles());
    }

    /**
     * An empty group (0 items) should be safely skipped.
     */
    #[Test]
    public function emptyGroupIsSkipped(): void
    {
        $group = new AssetGroup('group-1');

        $scorer = $this->createMock(CanonicalScorerInterface::class);
        $scorer->expects(self::never())->method('scoreItems');

        $companionDetector = $this->createMock(CompanionDetectorInterface::class);
        $companionDetector->expects(self::never())->method('detect');

        $assigner = $this->createAssigner($scorer, $companionDetector);

        $groups = new AssetGroupCollection();
        $groups->set('group-1', $group);

        $context = new PipelineContext('/photos');

        $assigner->assign($groups, $context);

        self::assertEmpty($group->getItems());
        self::assertEmpty($group->getDecisionLog());
    }

    /**
     * RoleAssigner should NOT set a DuplicateRelation on assigned Duplicates.
     * That is the SubgroupClassifier's responsibility.
     */
    #[Test]
    public function duplicateRelationNotSetByRoleAssigner(): void
    {
        $heic = new AssetItem(new SplFileInfo('/photos/IMG_0001.heic'));
        $jpg  = new AssetItem(new SplFileInfo('/photos/IMG_0001.jpg'));

        $group = new AssetGroup('group-1');
        $group->addItem($heic);
        $group->addItem($jpg);

        $scoredHeic = $heic->withScore(100, ['format priority']);
        $scoredJpg  = $jpg->withScore(50, ['lower priority']);

        $scorer = $this->createScorerMock($heic, $scoredHeic, $jpg, $scoredJpg);

        $companionDetector = self::createStub(CompanionDetectorInterface::class);
        $companionDetector->method('detect')->willReturn([]);

        $assigner = $this->createAssigner($scorer, $companionDetector);

        $groups = new AssetGroupCollection();
        $groups->set('group-1', $group);

        $context = new PipelineContext('/photos');

        $assigner->assign($groups, $context);

        $duplicateItem = $group->getItemByPath('/photos/IMG_0001.jpg');

        self::assertNotNull($duplicateItem);
        self::assertSame(ItemRole::Duplicate, $duplicateItem->role);
        self::assertNull($duplicateItem->duplicateRelation);
    }

    /**
     * Creates a RoleAssigner with the given mock dependencies.
     */
    private function createAssigner(
        CanonicalScorerInterface $scorer,
        CompanionDetectorInterface $companionDetector,
    ): RoleAssignerInterface {
        return new RoleAssigner(
            $scorer,
            $companionDetector,
            new MediaCompatibilityPolicy(new MediaTypeClassifier()),
        );
    }

    /**
     * Creates a scorer mock that replaces two items with scored versions
     * and returns the first scored item as canonical.
     */
    private function createScorerMock(
        AssetItem $original1,
        AssetItem $scored1,
        AssetItem $original2,
        AssetItem $scored2,
    ): CanonicalScorerInterface {
        $scorer = $this->createMock(CanonicalScorerInterface::class);

        $scorer->expects(self::once())
            ->method('scoreItems')
            ->willReturnCallback(static function (AssetGroup $group) use ($original1, $scored1, $original2, $scored2): void {
                $group->replaceItem($original1, $scored1);
                $group->replaceItem($original2, $scored2);
            });

        $scorer->expects(self::once())
            ->method('selectCanonical')
            ->willReturn($scored1);

        return $scorer;
    }
}
