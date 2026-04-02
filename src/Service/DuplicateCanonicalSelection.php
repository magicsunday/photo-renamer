<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Model\Rename;

/**
 * Immutable result of canonical rename selection inside a legacy duplicate group.
 *
 * The legacy duplicate pipeline needs more than "which rename is canonical":
 * it also needs to know whether the chosen canonical already matches the target
 * basename and whether the unsuffixed base name still needs to be promoted.
 * Keeping those decisions together avoids recomputing slightly different
 * heuristics in the caller.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class DuplicateCanonicalSelection
{
    /**
     * @param Rename|null $canonicalRename         Chosen canonical rename, or null when no rename qualified
     * @param bool        $canonicalHasExactName   Whether the canonical source already matches the target basename
     * @param bool        $canonicalNeedsPromotion Whether the canonical must actively reclaim the unsuffixed base name
     */
    public function __construct(
        public ?Rename $canonicalRename,
        public bool $canonicalHasExactName,
        public bool $canonicalNeedsPromotion,
    ) {
    }
}
