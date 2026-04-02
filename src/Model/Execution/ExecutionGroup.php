<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Execution;

use function array_filter;
use function array_values;
use function count;

/**
 * Groups execution items that belong to the same capture (e.g. a still,
 * its Live Photo companion, and any duplicates). Carries metadata about
 * the group for rendering and decision auditing.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ExecutionGroup
{
    /**
     * @param string              $groupKey            The capture group key
     * @param bool                $isLivePhotoGroup    True when group contains at least one Companion
     * @param string|null         $canonicalSourcePath Source path of the canonical item
     * @param list<ExecutionItem> $items               Items within this group
     * @param list<string>        $decisionLog         Human-readable decision audit entries
     */
    public function __construct(
        public string $groupKey,
        public bool $isLivePhotoGroup,
        public ?string $canonicalSourcePath = null,
        public array $items = [],
        public array $decisionLog = [],
    ) {
    }

    /**
     * Returns the number of individual items contained in this group.
     *
     * @return int<0, max>
     */
    public function itemCount(): int
    {
        return count($this->items);
    }

    /**
     * Returns items filtered by their execution role. Useful for rendering
     * groups with specific layout requirements (e.g., showing the canonical
     * item first, then companions).
     *
     * @param ExecutionItemType $type The role to filter for.
     *
     * @return list<ExecutionItem> A filtered list of execution items.
     */
    public function getItemsByType(ExecutionItemType $type): array
    {
        return array_values(
            array_filter(
                $this->items,
                static fn (ExecutionItem $item): bool => $item->type === $type,
            ),
        );
    }
}
