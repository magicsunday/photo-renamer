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
 * Defines the structural type of an output entry in the rename phase.
 * Determines which fields are populated and how the entry is rendered.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
enum OutputEntryType: string
{
    /** A file operation candidate (move, skipped move, or no-op). */
    case Rename = 'rename';

    /** A file excluded during the initial scan phase (no metadata, read error). */
    case Skip = 'skip';

    /** An informational notice (e.g. "Duplicate of..."). */
    case Info = 'info';

    /**
     * Sort order: rename entries appear first, then skip, then info.
     * Info entries sort last so they appear after their parent entry.
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::Rename => 0,
            self::Skip   => 1,
            self::Info   => 2,
        };
    }
}
