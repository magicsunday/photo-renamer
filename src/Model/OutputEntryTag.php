<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model;

use function sprintf;

/**
 * Defines the five output entry types displayed during the rename phase.
 * Acts as single source of truth for the tag letter, formatted Symfony
 * Console tag string, and color used in both rendering and filtering.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
enum OutputEntryTag: string
{
    case Rename    = 'R';
    case Duplicate = 'D';
    case Original  = 'O';
    case Skipped   = 'S';
    case Error     = 'E';

    /**
     * Returns the single-character tag letter used for --show filtering.
     */
    public function letter(): string
    {
        return $this->value;
    }

    /**
     * Returns the Symfony Console formatted tag string (e.g. "<fg=green>[R]</>").
     */
    public function formattedTag(): string
    {
        return sprintf('<fg=%s>[%s]</>', $this->color(), $this->value);
    }

    /**
     * Returns the Symfony Console color name for this tag.
     */
    public function color(): string
    {
        return match ($this) {
            self::Rename    => 'green',
            self::Duplicate => 'red',
            self::Original  => 'blue',
            self::Skipped   => 'gray',
            self::Error     => 'red',
        };
    }
}
