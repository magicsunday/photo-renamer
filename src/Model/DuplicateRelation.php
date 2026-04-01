<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model;

/**
 * Describes how a non-canonical file relates to its canonical counterpart.
 * Informational only — used for the decision log, no branching logic depends on it.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
enum DuplicateRelation: string
{
    /**
     * Byte-identical copy of the canonical file.
     */
    case Exact = 'exact';

    /**
     * Same logical asset (e.g. same content identifier) but not byte-identical.
     */
    case SameAsset = 'same-asset';

    /**
     * Re-encoded or format-converted variant of the canonical.
     */
    case Transcoded = 'transcoded';

    /**
     * Likely the same asset based on heuristic (date, basename) but not confirmed.
     */
    case Probable = 'probable';
}
