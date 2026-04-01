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
     * Returns the number of items in this group.
     */
    public function itemCount(): int
    {
        return count($this->items);
    }

    /**
     * Returns items filtered by the given execution item type.
     *
     * @return list<ExecutionItem>
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
