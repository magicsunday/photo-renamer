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
 * Stable, reviewable reasons at the classification boundary. A finite reason
 * vocabulary also lets the pipeline summarize decisions without retaining an
 * unbounded history of pairwise comparisons.
 */
enum MergeDecisionReason: string
{
    /** The current content-hash pass found identical hashes. */
    case ContentHashMatch = 'matching content hashes';

    /** File contents could not be hashed and remain an independent candidate. */
    case HashReadFailed = 'content hashing failed';

    /** A decode or signal calculation failed; no positive visual evidence exists. */
    case SignalUnavailable = 'perceptual signals unavailable';

    /** Available Stage A signals classified the pair as different. */
    case PerceptualRejected = 'perceptual similarity rejected';

    /** Stage A identified a potentially intentional edit that must stay separate. */
    case EditedVariant = 'edited variant';

    /** Stage B could not compare pixels even though Stage A looked similar. */
    case LocalAnalysisFailed = 'local pixel analysis failed';

    /** A color change exceeds the independent chroma safety limit. */
    case ChromaVeto = 'chroma safety limit exceeded';

    /** Pixel difference exceeds the effective safety/operator threshold. */
    case RmseVeto = 'pixel difference exceeds merge threshold';

    /** Stage A and Stage B accepted visual similarity within the effective limit. */
    case VisualMatch = 'visual similarity within merge threshold';

    /** Video similarity passed the existing perceptual/duration policy, not byte identity. */
    case VideoMatch = 'perceptual video similarity accepted';
}
