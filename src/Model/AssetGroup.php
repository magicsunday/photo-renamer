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
 * Mutable model representing one logical capture / media asset.
 * Contains AssetItem members, a decision log, and provides role-filtered views.
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
     * @param string $groupKey Stable logical key for the capture group
     */
    public function __construct(
        public readonly string $groupKey,
    ) {
    }

    /**
     * Adds an item to this group.
     *
     * @param AssetItem $item Item to append
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
     * @return list<AssetItem>
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
     * @return list<AssetItem>
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
     * @return list<AssetItem>
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
     * Finds an item by its file pathname.
     *
     * @param string $pathname Absolute file path to search for
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
     * Returns the number of items in this group.
     *
     * @return int<0, max>
     */
    public function itemCount(): int
    {
        return count($this->items);
    }

    /**
     * Appends an entry to the decision log.
     *
     * @param string $entry Human-readable decision description
     */
    public function addDecision(string $entry): void
    {
        $this->decisionLog[] = $entry;
    }

    /**
     * Returns the full decision log.
     *
     * @return list<string>
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
     * Marks subgroup classification as failed with the given reason.
     *
     * @param string $reason Human-readable description of the failure
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
     * Returns the reason for classification failure, or null if classification
     * succeeded or was not attempted.
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
