<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Metadata;

use function strtolower;
use function trim;

/**
 * Normalized representation of an Apple Live Photo content identifier extracted
 * from EXIF maker notes. Trims whitespace and lowercases the raw value so that
 * companion detection (HEIC/JPG + MOV sharing the same ID) works reliably
 * regardless of casing differences between camera vendors.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ContentIdentifier
{
    /**
     * Lowercased, trimmed content identifier string.
     */
    private string $value;

    /**
     * @param string $value Raw content identifier from EXIF metadata
     */
    public function __construct(string $value)
    {
        $canonicalValue = trim($value);

        $this->value = strtolower($canonicalValue);
    }

    /**
     * Returns the canonical (lowercased, trimmed) content identifier string.
     */
    public function getValue(): string
    {
        return $this->value;
    }
}
