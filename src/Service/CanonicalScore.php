<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

/**
 * Immutable value object that carries the weighted canonical-selection score
 * and the reasoning fragments that explain how that score was composed.
 *
 * The canonical scorer uses this DTO instead of a positional tuple so the
 * semantic pairing of numeric score and human-readable reasoning remains
 * explicit across internal scoring steps.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class CanonicalScore
{
    /**
     * @param int          $totalScore Weighted total used for canonical selection
     * @param list<string> $reasoning  Ordered reasoning fragments explaining the score composition
     */
    public function __construct(
        public int $totalScore,
        public array $reasoning,
    ) {
    }
}
