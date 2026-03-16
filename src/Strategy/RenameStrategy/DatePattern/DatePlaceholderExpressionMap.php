<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\DatePattern;

use RuntimeException;

use function preg_replace_callback;

/**
 * Maps date placeholder tokens (Y, m, d, H, i, s) to their corresponding
 * regex capture groups (e.g. Y -> (\d{4})). Used to convert a user-supplied
 * date pattern template into a PCRE regex for filename matching.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class DatePlaceholderExpressionMap
{
    /**
     * @param array<string, string> $expressions Mapping of placeholder name to regex capture group
     */
    public function __construct(private array $expressions)
    {
    }

    /**
     * Creates the standard map covering year (4-digit and 2-digit), month, day,
     * hour, minute and second placeholders.
     */
    public static function default(): self
    {
        return new self([
            'Y' => '(\\d{4})',
            'y' => '(\\d{2})',
            'm' => '(\\d{2})',
            'd' => '(\\d{2})',
            'H' => '(\\d{2})',
            'i' => '(\\d{2})',
            's' => '(\\d{2})',
        ]);
    }

    /**
     * Replaces {placeholder} tokens in the pattern with their regex capture groups.
     * Unknown placeholders are left unchanged.
     *
     * @param string $pattern Template string containing {Y}, {m}, {d}, etc. tokens
     *
     * @return string PCRE-compatible regex with capture groups in place of tokens
     *
     * @throws RuntimeException When preg_replace_callback fails
     */
    public function replacePlaceholders(string $pattern): string
    {
        $result = preg_replace_callback(
            '/{(\\w+)}/',
            function (array $matches): string {
                $placeholder = $matches[1];

                return $this->expressions[$placeholder] ?? $matches[0];
            },
            $pattern
        );

        if ($result === null) {
            throw new RuntimeException('Failed to replace placeholders in the given pattern.');
        }

        return $result;
    }
}
