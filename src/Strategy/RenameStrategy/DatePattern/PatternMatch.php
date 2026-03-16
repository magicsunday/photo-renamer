<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\DatePattern;

/**
 * Represents a single placeholder occurrence discovered in a date pattern template.
 * Maps the full token syntax (e.g. "{Y}") to the bare placeholder name (e.g. "Y"),
 * which corresponds to a PHP date() format character.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class PatternMatch
{
    /**
     * @param string $token       Full token including braces, e.g. "{Y}"
     * @param string $placeholder Bare placeholder name, e.g. "Y"
     */
    public function __construct(
        private string $token,
        private string $placeholder,
    ) {
    }

    /**
     * Returns the full token string including braces, as found in the template.
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * Returns the bare placeholder name (the content between braces), which
     * corresponds to a PHP date() format character.
     */
    public function getPlaceholder(): string
    {
        return $this->placeholder;
    }
}
