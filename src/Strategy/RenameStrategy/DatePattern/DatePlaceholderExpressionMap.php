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

final readonly class DatePlaceholderExpressionMap
{
    /**
     * @param array<string, string> $expressions
     */
    public function __construct(private array $expressions)
    {
    }

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
