<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Execution;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\DateDriftCalculator;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Execution\ExecutionGroup;
use MagicSunday\Renamer\Model\Execution\ExecutionItem;
use MagicSunday\Renamer\Model\Execution\ExecutionItemType;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Model\ItemRole;
use MagicSunday\Renamer\Model\PipelineContext;
use Override;

use function array_map;
use function basename;
use function sprintf;
use function str_contains;
use function usort;

/**
 * Projects classified asset groups into an ExecutionPlan and applies execution
 * eligibility, including the configured date-drift limit. Detection, grouping
 * and collision resolution remain the responsibility of preceding phases.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ExecutionPlanBuilder implements ExecutionPlanBuilderInterface
{
    /**
     * Role ordering for item sort: Canonical → Companion → Duplicate → Ambiguous.
     *
     * @var array<string, int>
     */
    private const array ROLE_ORDER = [
        'canonical' => 0,
        'companion' => 1,
        'duplicate' => 2,
        'ambiguous' => 3,
    ];

    /**
     * Builds the execution-layer projection for the already classified asset groups.
     *
     * @param AssetGroupCollection $groups       Asset groups to project into execution DTOs
     * @param PipelineContext      $context      Pipeline context carrying quality and conflict flags
     * @param int|null             $maxDateDrift Maximum permitted filename-date drift; null or zero disables the limit
     *
     * @return ExecutionPlan Execution-ready projection of the input groups
     */
    #[Override]
    public function build(
        AssetGroupCollection $groups,
        PipelineContext $context,
        ?int $maxDateDrift = null,
    ): ExecutionPlan {
        $executionGroups = [];

        foreach ($groups as $group) {
            $executionGroups[] = $this->projectGroup($group, $context, $maxDateDrift);
        }

        return new ExecutionPlan($executionGroups);
    }

    /**
     * Projects a single asset group into its execution-layer representation.
     *
     * Items are ordered canonically, quality flags are mapped onto execution entries,
     * and the group's decision log is carried over without introducing new decisions.
     *
     * @param AssetGroup      $group        Source asset group to project
     * @param PipelineContext $context      Current pipeline context with quality flags
     * @param int|null        $maxDateDrift Maximum permitted filename-date drift
     *
     * @return ExecutionGroup Execution-layer view of the source group
     */
    private function projectGroup(
        AssetGroup $group,
        PipelineContext $context,
        ?int $maxDateDrift = null,
    ): ExecutionGroup {
        $orderedItems = $this->orderItems($group->getItems());

        $executionItems = array_map(
            fn (AssetItem $item): ExecutionItem => $this->projectItem($item, $group->groupKey, $context, $maxDateDrift),
            $orderedItems,
        );

        $canonical   = $group->getCanonical();
        $companions  = $group->getCompanions();
        $decisionLog = $group->getDecisionLog();

        if ($group->isClassificationDegraded()) {
            $reason      = $group->getClassificationFailureReason() ?? 'unknown';
            $decisionLog = [
                sprintf('Classification degraded: %s', $reason),
                ...$decisionLog,
            ];
        }

        return new ExecutionGroup(
            groupKey: $group->groupKey,
            isLivePhotoGroup: $companions !== [],
            canonicalSourcePath: $canonical?->file->getPathname(),
            items: $executionItems,
            decisionLog: $decisionLog,
        );
    }

    /**
     * Projects one asset item into an execution item and applies execution gating.
     *
     * This is the final step where pipeline state flags are translated into runtime
     * execution decisions. Items can be blocked here due to Live Photo conflicts,
     * ambiguous timezones, fallback dates, or because the rename is a no-op.
     *
     * @param AssetItem       $item         Source asset item
     * @param string          $groupKey     Group key the item belongs to
     * @param PipelineContext $context      Current pipeline context with quality flags
     * @param int|null        $maxDateDrift Maximum permitted filename-date drift
     *
     * @return ExecutionItem Execution-layer representation of the item
     */
    private function projectItem(
        AssetItem $item,
        string $groupKey,
        PipelineContext $context,
        ?int $maxDateDrift = null,
    ): ExecutionItem {
        $sourcePath     = $item->file->getPathname();
        $targetPath     = $item->proposedName ?? $sourcePath;
        $renameRequired = ($item->proposedName !== null) && $item->renameRequired;
        $isNoOp         = !$renameRequired;

        $fallbackDateFiles      = $context->getFallbackDateFiles();
        $ambiguousTimezoneFiles = $context->getAmbiguousTimezoneFiles();
        $livePhotoConflictFiles = $context->getLivePhotoConflictFiles();

        $isLivePhotoConflict = isset($livePhotoConflictFiles[$sourcePath]);
        $isAmbiguousTimezone = isset($ambiguousTimezoneFiles[$sourcePath]);
        $isFallbackDate      = isset($fallbackDateFiles[$sourcePath]);

        // Execution decision: state flags → execution policy
        $isExecutable         = true;
        $executionBlockReason = null;

        if ($isNoOp) {
            $isExecutable = false;
        // No reason needed — it's a no-op, not a block
        } elseif ($isLivePhotoConflict) {
            $isExecutable         = false;
            $executionBlockReason = 'Live Photo conflict: conflicting content identifiers across groups';
        } elseif ($isAmbiguousTimezone) {
            $isExecutable         = false;
            $executionBlockReason = 'Ambiguous timezone: QuickTime UTC without offset — use --timezone or rename:write-date --reason=timezone';
        } elseif ($isFallbackDate) {
            $isExecutable         = false;
            $executionBlockReason = 'Fallback date: DateTime (0x0132) used instead of DateTimeOriginal — use rename:write-date --reason=fallback';
        }

        if ($isExecutable && !$this->isDuplicateTarget($targetPath)) {
            $executionBlockReason = DateDriftCalculator::excessiveDriftReason($sourcePath, $targetPath, $maxDateDrift);
            $isExecutable         = $executionBlockReason === null;
        }

        return new ExecutionItem(
            sourcePath: $sourcePath,
            targetPath: $targetPath,
            type: $this->mapItemType($item->role),
            renameRequired: $renameRequired,
            isNoOp: $isNoOp,
            groupKey: $groupKey,
            clusterId: $item->clusterId,
            isDuplicateTarget: $this->isDuplicateTarget($targetPath),
            isLivePhotoConflict: $isLivePhotoConflict,
            isFallbackDate: $isFallbackDate,
            isAmbiguousTimezone: $isAmbiguousTimezone,
            isExecutable: $isExecutable,
            executionBlockReason: $executionBlockReason,
        );
    }

    /**
     * Orders items by role: Canonical → Companion → Duplicate → Ambiguous.
     *
     * @param list<AssetItem> $items Items to sort
     *
     * @return list<AssetItem> Sorted items
     */
    private function orderItems(array $items): array
    {
        usort(
            $items,
            static fn (AssetItem $itemA, AssetItem $itemB): int => self::ROLE_ORDER[$itemA->role->value] <=> self::ROLE_ORDER[$itemB->role->value],
        );

        return $items;
    }

    /**
     * Maps a domain ItemRole to the execution-layer ExecutionItemType.
     *
     * @param ItemRole $role Domain item role
     */
    private function mapItemType(ItemRole $role): ExecutionItemType
    {
        return match ($role) {
            ItemRole::Canonical => ExecutionItemType::Canonical,
            ItemRole::Companion => ExecutionItemType::Companion,
            ItemRole::Duplicate => ExecutionItemType::Duplicate,
            ItemRole::Ambiguous => ExecutionItemType::Ambiguous,
        };
    }

    /**
     * Checks whether the target basename contains the duplicate identifier string.
     *
     * @param string $targetPath Absolute target file path
     */
    private function isDuplicateTarget(string $targetPath): bool
    {
        return str_contains(basename($targetPath), Constants::DUPLICATE_IDENTIFIER);
    }
}
