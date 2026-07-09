<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\PerceptualHash;

/**
 * Classification result from the multi-signal similarity scoring.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
enum SimilarityClassification: string
{
    /**
     * Highly similar files that are likely identical in content.
     * Differences are usually limited to metadata or very minor noise.
     */
    case DuplicateLikely = 'duplicate_likely';

    /**
     * Files that share the same visual/audible origin but have been
     * modified (e.g., resized, re-encoded, or slight color changes).
     */
    case EditedVariant = 'edited_variant';

    /**
     * Files that have no significant perceptual overlap and represent
     * different media items.
     */
    case Different = 'different';
}
