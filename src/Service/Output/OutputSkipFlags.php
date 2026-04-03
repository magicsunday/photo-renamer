<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Output;

/**
 * Immutable execution flags for one rendered output entry.
 *
 * Rendering needs to distinguish between entries that are merely displayed and
 * entries that would lead to a real filesystem operation. This DTO keeps that
 * decision readable at collaborator boundaries and replaces positional boolean
 * tuples in the output module.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OutputSkipFlags
{
    /**
     * @param bool $shouldSkip             Whether the entry is blocked from execution
     * @param bool $shouldPerformOperation Whether a concrete rename/move would be performed
     */
    public function __construct(
        public bool $shouldSkip,
        public bool $shouldPerformOperation,
    ) {
    }
}
