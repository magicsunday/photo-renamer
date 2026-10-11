<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Helper;

use Symfony\Component\Console\Formatter\OutputFormatter;

use function json_encode;
use function ord;
use function preg_replace_callback;
use function sprintf;
use function str_replace;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * Escapes untrusted visible text at the Console boundary. Keep filesystem and
 * domain values unchanged; only their display becomes safe. C0/C1 characters
 * become visible escapes before Symfony markup is escaped, so filenames and
 * parser diagnostics cannot inject terminal actions or counterfeit log lines.
 * Trusted colors, hyperlinks and layout are added by callers afterward.
 */
final readonly class ConsoleText
{
    /**
     * Prepares one untrusted string for Symfony's normal output formatter.
     * Valid Unicode remains readable. Invalid UTF-8 uses visible byte escapes
     * for controls/non-ASCII bytes instead of silently dropping the filename.
     * The result follows Symfony's escaping contract, including its internal
     * trailing-backslash sentinel, and must be passed through the formatter.
     *
     * @param string $text Original filename, metadata value or diagnostic text
     *
     * @return string Neutralized text with literal Symfony formatting tags
     */
    public static function escape(string $text): string
    {
        return OutputFormatter::escape(self::neutralize($text));
    }

    /**
     * Converts active controls into visible text without introducing Console
     * markup escapes. Diff matching and column widths use this representation
     * before individual segments are escaped and trusted styling is applied.
     * SymfonyStyle error/warning blocks already escape markup internally;
     * pass them this plain representation to avoid double escaping.
     *
     * @param string $text Original visible text, possibly containing invalid UTF-8
     *
     * @return string Readable text without active C0/C1 characters
     */
    public static function neutralize(string $text): string
    {
        $neutral = preg_replace_callback(
            '/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u',
            // JSON escapes C0/C1, but leaves DEL literal; expose that too.
            static fn (array $matches): string => str_replace("\x7F", '\\u007f', substr(json_encode($matches[0], JSON_THROW_ON_ERROR), 1, -1)),
            $text,
        );

        if ($neutral === null) {
            return preg_replace_callback(
                '/[\x00-\x1F\x7F-\xFF]/',
                static fn (array $matches): string => sprintf('\\x%02X', ord($matches[0])),
                $text,
            ) ?? '[unrenderable text]';
        }

        return $neutral;
    }
}
