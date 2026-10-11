<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Helper;

use MagicSunday\Renamer\Helper\ConsoleText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Verifies the visible-text contract after actual Symfony formatting. JSON
 * comparisons keep control-byte regressions inert even in PHPUnit failures.
 * Native filenames and filesystem operations never use these representations.
 */
#[CoversClass(ConsoleText::class)]
final class ConsoleTextTest extends TestCase
{
    /**
     * Covers null, editing controls, ESC, DEL and Unicode C1 controls. Every
     * active byte becomes a readable escape instead of changing terminal state
     * or splitting an untrusted diagnostic into additional log lines.
     *
     * @return void
     */
    #[Test]
    public function editingAndTerminalControlsBecomeVisibleEscapes(): void
    {
        $input    = "\0\x08\t\n\x0C\r\x1B\x7F\u{0085}\u{009b}\u{009f}";
        $expected = '\\u0000\\b\\t\\n\\f\\r\\u001b\\u007f\\u0085\\u009b\\u009f';
        $rendered = new OutputFormatter(false)->format(ConsoleText::escape($input));
        self::assertSame(json_encode($expected, JSON_THROW_ON_ERROR), json_encode($rendered, JSON_THROW_ON_ERROR));
    }

    /**
     * Preserves ordinary accented, Asian and emoji text, literal markup and a
     * trailing Windows separator. Symfony's own trailing-backslash sentinel
     * must be consumed by the formatter rather than leak as a null character.
     *
     * @return void
     */
    #[Test]
    public function unicodeMarkupAndTrailingBackslashesRemainLiteral(): void
    {
        $input    = 'école/東京 📷 <fg=red>literal</>\\';
        $rendered = new OutputFormatter(false)->format(ConsoleText::escape($input));
        self::assertSame(json_encode($input, JSON_THROW_ON_ERROR), json_encode($rendered, JSON_THROW_ON_ERROR));
    }

    /**
     * Handles arbitrary Linux filename bytes that are not valid UTF-8. Invalid
     * bytes and raw C1/ESC/line controls remain visible in a lossless byte-based
     * representation instead of dropping the whole filename on a Unicode error.
     *
     * @return void
     */
    #[Test]
    public function invalidUtf8UsesAnInertVisibleByteRepresentation(): void
    {
        $input    = "broken-\xFF\x9B\x1B\r\n.jpg";
        $expected = 'broken-\\xFF\\x9B\\x1B\\x0D\\x0A.jpg';
        $rendered = new OutputFormatter(false)->format(ConsoleText::escape($input));
        self::assertSame(json_encode($expected, JSON_THROW_ON_ERROR), json_encode($rendered, JSON_THROW_ON_ERROR));
    }
}
