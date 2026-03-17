<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\RenameStrategy\DatePattern;

use MagicSunday\Renamer\Strategy\RenameStrategy\DatePattern\PatternMatchSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the token extraction of PatternMatchSet, which represents the
 * ordered list of date placeholders found in a user-supplied search pattern.
 *
 * PatternMatchSet is built once per command invocation and used by
 * DatePatternFilenameStrategy to map regex capture groups back to their
 * corresponding PHP date format characters (Y, m, d, H, i, s).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(PatternMatchSet::class)]
class PatternMatchSetTest extends TestCase
{
    /**
     * Verifies that fromPattern() parses a regex-like template and returns the
     * bare placeholders (e.g. "Y") in order of appearance.
     *
     * Correct ordering is critical because the capture groups in the compiled regex
     * are positional -- group 1 maps to the first placeholder, group 2 to the second,
     * and so on. A reordering or omission would assign wrong date values.
     */
    #[Test]
    public function itExtractsPlaceholders(): void
    {
        $set = PatternMatchSet::fromPattern('/^{Y}-{m}-{d}$/');

        self::assertSame(['Y', 'm', 'd'], $set->placeholders());
    }

    /**
     * Verifies that a single placeholder is correctly extracted.
     */
    #[Test]
    public function itExtractsSinglePlaceholder(): void
    {
        $set = PatternMatchSet::fromPattern('/^{H}$/');

        self::assertSame(['H'], $set->placeholders());
    }
}
