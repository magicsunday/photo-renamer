<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\RenameStrategy\DatePattern;

use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Strategy\RenameStrategy\DatePattern\DatePlaceholderExpressionMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the placeholder-to-regex substitution logic of DatePlaceholderExpressionMap.
 *
 * The map translates user-facing date tokens like {Y}, {m}, {d} into their
 * corresponding regex capture groups (\d{4}, \d{2}, etc.) so that date components
 * can be extracted from filenames during date-pattern renaming. Incorrect
 * substitution would cause the regex to either fail to match valid filenames
 * or extract wrong date parts.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(DatePlaceholderExpressionMap::class)]
#[UsesClass(SafeRegex::class)]
final class DatePlaceholderExpressionMapTest extends TestCase
{
    /**
     * Verifies that known placeholders ({Y}, {m}, {d}) in the pattern are
     * correctly replaced by the corresponding regex capture groups.
     * This forms the basis for extracting date components from filenames.
     */
    #[Test]
    public function itReplacesKnownPlaceholders(): void
    {
        $map      = DatePlaceholderExpressionMap::default();
        $pattern  = '/^{Y}-{m}-{d}$/';
        $expected = '/^(\\d{4})-(\\d{2})-(\\d{2})$/';

        self::assertSame($expected, $map->replacePlaceholders($pattern));
    }

    /**
     * Ensures that unknown placeholders (e.g., {X}) in the pattern remain
     * unchanged as literals, while known placeholders next to them continue
     * to be correctly replaced.
     */
    #[Test]
    public function itKeepsUnknownPlaceholdersUntouched(): void
    {
        $map     = DatePlaceholderExpressionMap::default();
        $pattern = '/^{X}-{Y}$/';

        self::assertSame('/^{X}-(\\d{4})$/', $map->replacePlaceholders($pattern));
    }
}
