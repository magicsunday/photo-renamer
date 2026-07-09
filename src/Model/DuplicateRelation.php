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
 * Describes the relationship between a non-canonical file and its canonical counterpart.
 *
 * This enumeration provides a classification for duplicates based on how they
 * differ from the primary 'canonical' file. These classifications are used
 * primarily for the decision log to provide transparency on why a certain file
 * was marked as a duplicate.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
enum DuplicateRelation: string
{
    /**
     * The file is a byte-identical copy of the canonical file (same content hash).
     */
    case Exact = 'exact';

    /**
     * The file represents the same logical asset (e.g., sharing the same Apple
     * Content Identifier) but is not byte-identical (e.g., different metadata).
     */
    case SameAsset = 'same-asset';

    /**
     * The file is a re-encoded or format-converted variant of the canonical
     * file (e.g., HEIC converted to JPEG) that remains visually identical.
     */
    case Transcoded = 'transcoded';

    /**
     * The file is likely the same asset based on heuristics (matching capture
     * date and similar basename) but has no definitive content-based proof.
     */
    case Probable = 'probable';
}
