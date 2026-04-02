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
 * Defines the role a file plays within an asset group.
 *
 * This role determines how a file is handled during the renaming process,
 * influencing its final filename and its relationship to other files in the
 * same group (e.g., still image vs. companion video).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
enum ItemRole: string
{
    /**
     * The primary representative of an asset group. This file determines the
     * base filename for the entire group.
     */
    case Canonical = 'canonical';

    /**
     * A file that is identified as a duplicate of the canonical file. It receives
     * the same base filename but with a numbered duplicate identifier suffix.
     */
    case Duplicate = 'duplicate';

    /**
     * A file that belongs to the same capture but has a different type, such as
     * the video part of an Apple Live Photo or a RAW/JPEG pair. It receives the
     * same base name as the canonical file but keeps its own extension.
     */
    case Companion = 'companion';

    /**
     * A file whose role within the group could not be determined with absolute
     * certainty. It is treated as a duplicate for naming purposes to avoid
     * accidental data loss or incorrect primary file selection.
     */
    case Ambiguous = 'ambiguous';
}
