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
 * Separates content-hash evidence from visual similarity and unavailable
 * analysis. These classification outcomes never authorize permanent deletion.
 */
enum MergeDecisionKind: string
{
    /** Matching content hashes within the current classification pass. */
    case Exact = 'exact';

    /** Accepted visual similarity between files with different content hashes. */
    case Perceptual = 'perceptual';

    /** Available analysis rejected the pair; later heuristics cannot override it. */
    case Different = 'different';

    /** Missing evidence prevents a positive decision without proving dissimilarity. */
    case Uncertain = 'uncertain';
}
