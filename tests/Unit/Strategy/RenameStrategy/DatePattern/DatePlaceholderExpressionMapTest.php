<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\RenameStrategy\DatePattern;

use MagicSunday\Renamer\Strategy\RenameStrategy\DatePattern\DatePlaceholderExpressionMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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
final class DatePlaceholderExpressionMapTest extends TestCase
{
    /**
     * Verifies that all standard date placeholders ({Y}, {m}, {d}) in a pattern
     * are replaced with the correct regex capture groups while preserving literal
     * characters like the dash separators and anchors.
     *
     * A failure would mean the date-pattern command cannot extract year, month,
     * or day from filenames that follow the configured naming convention.
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
     * Verifies that placeholders not present in the default map (e.g. {X}) are
     * left as literal text in the output, while recognised placeholders next to
     * them are still substituted correctly.
     *
     * This is important for forward compatibility: if a user includes a future
     * or custom placeholder, it must not corrupt the regex of the surrounding
     * recognised tokens.
     */
    #[Test]
    public function itKeepsUnknownPlaceholdersUntouched(): void
    {
        $map     = DatePlaceholderExpressionMap::default();
        $pattern = '/^{X}-{Y}$/';

        self::assertSame('/^{X}-(\\d{4})$/', $map->replacePlaceholders($pattern));
    }
}
