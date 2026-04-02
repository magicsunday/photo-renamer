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
 * Defines the output entry types displayed during the rename phase.
 * Acts as single source of truth for the tag letter, formatted Symfony
 * Console tag string, and color used in both rendering and filtering.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
enum OutputEntryTag: string
{
    /**
     * Likely Live Photo pair with conflicting content IDs, needs review.
     */
    case Candidate = 'C';

    /**
     * Regular file rename operation.
     */
    case Rename = 'R';

    /**
     * File date sourced from fallback metadata (e.g. 0x0132 tag).
     */
    case Fallback = 'F';

    /**
     * Genuine duplicate (byte-identical or same asset) being suffixed.
     */
    case Duplicate = 'D';

    /**
     * File is already at the correct target path, or canonical item.
     */
    case Original = 'O';

    /**
     * Potential issue detected (e.g. large date drift, ambiguous TZ).
     */
    case Warning = 'W';

    /**
     * Scanning skip: file skipped during scanning (missing metadata).
     */
    case Skipped = 'S';

    /**
     * Scanning error: file system error or exception during metadata read.
     */
    case Error = 'E';

    /**
     * Informational notice: supplemental info attached to another entry.
     */
    case Info = 'I';

    /**
     * Returns true when this tag belongs to a "scanning skip" entry.
     * These entries represent files that were excluded from the pipeline
     * during the initial scanning phase due to missing metadata or I/O errors.
     *
     * @return bool True if this tag represents a scanning skip (S) or error (E).
     */
    public function isScanningSkip(): bool
    {
        return ($this === self::Skipped) || ($this === self::Error);
    }

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
            self::Candidate => 'cyan',
            self::Rename    => 'green',
            self::Fallback  => 'yellow',
            self::Duplicate => 'red',
            self::Original  => 'blue',
            self::Warning   => 'magenta',
            self::Skipped   => 'gray',
            self::Error     => 'red',
            self::Info      => 'blue',
        };
    }
}
