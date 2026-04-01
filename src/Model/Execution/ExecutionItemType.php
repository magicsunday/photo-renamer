<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Execution;

/**
 * Classifies each item in the execution plan by its role within the group.
 * Maps to the domain ItemRole but lives in the execution/output layer
 * with an additional Skipped case for non-executable entries.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
enum ExecutionItemType: string
{
    /** The primary file that determines the group's base name. */
    case Canonical = 'canonical';

    /** A byte-identical or content-identical copy. */
    case Duplicate = 'duplicate';

    /** A related file (Live Photo video, sidecar). */
    case Companion = 'companion';

    /** Probably the same capture, but not certain enough. */
    case Ambiguous = 'ambiguous';

    /** Runtime-projected non-executable entry within a projected group. */
    case Skipped = 'skipped';
}
