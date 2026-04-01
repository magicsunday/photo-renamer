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
 * Immutable result of rename plan validation. Contains lists of detected
 * issues: duplicate targets, case-insensitive conflicts, and circular swaps.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ValidationResult
{
    /**
     * @param list<string>       $duplicateTargets Target paths claimed by multiple source files
     * @param list<list<string>> $caseConflicts    Groups of targets differing only in letter casing
     * @param list<list<string>> $circularSwaps    Cycles of paths forming rename loops (2-cycles, 3-cycles, etc.)
     */
    public function __construct(
        public array $duplicateTargets = [],
        public array $caseConflicts = [],
        public array $circularSwaps = [],
    ) {
    }

    /**
     * Returns true when no validation issues were found.
     */
    public function isValid(): bool
    {
        return ($this->duplicateTargets === [])
            && ($this->caseConflicts === [])
            && ($this->circularSwaps === []);
    }
}
