<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model;

use MagicSunday\Renamer\Model\OutputEntryTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the OutputEntryTag enum provides correct letters, formatted tags,
 * and colors for all six output entry types.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(OutputEntryTag::class)]
final class OutputEntryTagTest extends TestCase
{
    #[Test]
    public function itReturnsCorrectLetters(): void
    {
        self::assertSame('R', OutputEntryTag::Rename->letter());
        self::assertSame('F', OutputEntryTag::Fallback->letter());
        self::assertSame('D', OutputEntryTag::Duplicate->letter());
        self::assertSame('O', OutputEntryTag::Original->letter());
        self::assertSame('S', OutputEntryTag::Skipped->letter());
        self::assertSame('E', OutputEntryTag::Error->letter());
    }

    #[Test]
    public function itReturnsFormattedTags(): void
    {
        self::assertSame('<fg=green>[R]</>', OutputEntryTag::Rename->formattedTag());
        self::assertSame('<fg=yellow>[F]</>', OutputEntryTag::Fallback->formattedTag());
        self::assertSame('<fg=red>[D]</>', OutputEntryTag::Duplicate->formattedTag());
        self::assertSame('<fg=blue>[O]</>', OutputEntryTag::Original->formattedTag());
        self::assertSame('<fg=gray>[S]</>', OutputEntryTag::Skipped->formattedTag());
        self::assertSame('<fg=red>[E]</>', OutputEntryTag::Error->formattedTag());
    }

    #[Test]
    public function itReturnsCorrectColors(): void
    {
        self::assertSame('green', OutputEntryTag::Rename->color());
        self::assertSame('yellow', OutputEntryTag::Fallback->color());
        self::assertSame('red', OutputEntryTag::Duplicate->color());
        self::assertSame('blue', OutputEntryTag::Original->color());
        self::assertSame('gray', OutputEntryTag::Skipped->color());
        self::assertSame('red', OutputEntryTag::Error->color());
    }
}
