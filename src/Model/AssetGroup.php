<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;

/**
 * Groups several {@see AssetItem} instances that logically belong to the same
 * capture (e.g. a still photo, its video companion for Live Photos, and
 * any exact or perceptual duplicates).
 *
 * This class serves as the central hub for classification decisions:
 * - Identifying the "Canonical" item (best original copy)
 * - Mapping "Companions" (Live Photo videos) to their stills
 * - Tagging "Duplicates" (perceptual or exact file copies)
 * - Handling "Ambiguous" items that need further analysis
 *
 * All decisions and state changes are tracked in a decision log for audit purposes.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class AssetGroup
{
    /**
     * @var list<AssetItem>
     */
    private array $items = [];

    /**
     * @var list<string>
     */
    private array $decisionLog = [];

    /**
     * Whether subgroup classification completed (true), failed (false), or was not attempted (null).
     */
    private ?bool $classificationSucceeded = null;

    /**
     * Reason for classification failure (null if succeeded or not attempted).
     */
    private ?string $classificationFailureReason = null;

    /**
     * @param string $groupKey Stable logical key for the capture group (e.g. the target basename)
     */
    public function __construct(
        public readonly string $groupKey,
    ) {
    }

    /**
     * Adds an item to this group.
     *
     * @param AssetItem $item The AssetItem member to add to the group
     */
    public function addItem(AssetItem $item): void
    {
        $this->items[] = $item;
    }

    /**
     * Replaces an item in-place using identity comparison.
     * Silently no-ops if the old item is not found.
     *
     * @param AssetItem $old Existing item to replace (matched by identity)
     * @param AssetItem $new Replacement item
     */
    public function replaceItem(AssetItem $old, AssetItem $new): void
    {
        foreach ($this->items as $index => $item) {
            if ($item === $old) {
                $this->items[$index] = $new;

                return;
            }
        }
    }

    /**
     * Returns all items in the group.
     *
     * @return list<AssetItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Returns the first item with the Canonical role, or null if none exists.
     *
     * @return AssetItem|null The canonical item or null
     */
    public function getCanonical(): ?AssetItem
    {
        foreach ($this->items as $item) {
            if ($item->role === ItemRole::Canonical) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Returns all items with the Duplicate role.
     *
     * @return list<AssetItem> List of items with the Duplicate role
     */
    public function getDuplicates(): array
    {
        return array_values(
            array_filter(
                $this->items,
                static fn (AssetItem $item): bool => $item->role === ItemRole::Duplicate,
            ),
        );
    }

    /**
     * Returns all items with the Companion role.
     *
     * @return list<AssetItem> List of items with the Companion role
     */
    public function getCompanions(): array
    {
        return array_values(
            array_filter(
                $this->items,
                static fn (AssetItem $item): bool => $item->role === ItemRole::Companion,
            ),
        );
    }

    /**
     * Returns all items with the Ambiguous role.
     *
     * @return list<AssetItem> List of items with the Ambiguous role
     */
    public function getAmbiguous(): array
    {
        return array_values(
            array_filter(
                $this->items,
                static fn (AssetItem $item): bool => $item->role === ItemRole::Ambiguous,
            ),
        );
    }

    /**
     * Finds and returns an item by its absolute source pathname.
     *
     * @param string $pathname Absolute file path to search for.
     *
     * @return AssetItem|null The matching item or null if not found.
     */
    public function getItemByPath(string $pathname): ?AssetItem
    {
        foreach ($this->items as $item) {
            if ($item->file->getPathname() === $pathname) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Returns the total number of items in this group.
     *
     * @return int The total count of items.
     */
    public function itemCount(): int
    {
        return count($this->items);
    }

    /**
     * Appends a human-readable entry to the decision log for this group.
     * Used for auditing and debugging pipeline decisions.
     *
     * @param string $entry Human-readable description of a pipeline decision.
     */
    public function addDecision(string $entry): void
    {
        $this->decisionLog[] = $entry;
    }

    /**
     * Returns the full history of classification decisions for this group.
     *
     * @return list<string> The list of decision log entries.
     */
    public function getDecisionLog(): array
    {
        return $this->decisionLog;
    }

    /**
     * Returns true when items have more than one unique lowercase extension.
     */
    public function hasMultipleDistinctFormats(): bool
    {
        $extensions = array_unique(
            array_map(
                static fn (AssetItem $item): string => $item->extension(),
                $this->items,
            ),
        );

        return count($extensions) > 1;
    }

    /**
     * Marks subgroup classification as having completed successfully.
     */
    public function markClassificationSucceeded(): void
    {
        $this->classificationSucceeded     = true;
        $this->classificationFailureReason = null;
    }

    /**
     * Marks the subgroup classification as failed with a specific reason.
     * This typically leads to a degraded state where items might not be perfectly grouped.
     *
     * @param string $reason Human-readable explanation of why classification failed.
     */
    public function markClassificationFailed(string $reason): void
    {
        $this->classificationSucceeded     = false;
        $this->classificationFailureReason = $reason;
    }

    /**
     * Returns true when subgroup classification failed (degraded state).
     */
    public function isClassificationDegraded(): bool
    {
        return $this->classificationSucceeded === false;
    }

    /**
     * Returns the reason why subgroup classification failed, if any.
     *
     * @return string|null The failure reason or null if classification succeeded or was not attempted.
     */
    public function getClassificationFailureReason(): ?string
    {
        return $this->classificationFailureReason;
    }

    /**
     * Returns true when subgroup classification was attempted (regardless of outcome).
     */
    public function wasClassified(): bool
    {
        return $this->classificationSucceeded !== null;
    }
}
