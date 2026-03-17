<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\DatePattern;

use MagicSunday\Renamer\Regex\SafeRegex;

/**
 * Ordered set of placeholder names extracted from a date pattern template.
 * Used to map regex capture groups back to their PHP date() format characters.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class PatternMatchSet
{
    /**
     * @param list<string> $placeholders Bare placeholder names (e.g. ["Y", "m", "d"])
     */
    private function __construct(
        private array $placeholders,
    ) {
    }

    /**
     * Creates a set of placeholder names for every placeholder token in the given pattern string.
     *
     * @param string    $pattern   Pattern string containing placeholder tokens (e.g. `{placeholder}`)
     * @param SafeRegex $safeRegex Safe wrapper around preg_* functions with error handling
     *
     * @return PatternMatchSet Set populated with placeholder names for each discovered token
     */
    public static function fromPattern(string $pattern, SafeRegex $safeRegex = new SafeRegex()): self
    {
        $result = $safeRegex->matchAll(
            '/\{(\w+)\}/',
            $pattern,
            'extracting placeholders from pattern',
        );

        /** @var list<string> $matches */
        $matches = $result->matches()[1] ?? [];

        return new self($matches);
    }

    /**
     * Returns all bare placeholder names (e.g. ["Y", "m", "d"]) in the order
     * they appear in the original pattern. Used to map regex capture groups
     * back to their date format characters.
     *
     * @return list<string>
     */
    public function placeholders(): array
    {
        return $this->placeholders;
    }
}
