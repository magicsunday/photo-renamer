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

#[CoversClass(DatePlaceholderExpressionMap::class)]
class DatePlaceholderExpressionMapTest extends TestCase
{
    #[Test]
    public function itReplacesKnownPlaceholders(): void
    {
        $map      = DatePlaceholderExpressionMap::default();
        $pattern  = '/^{Y}-{m}-{d}$/';
        $expected = '/^(\\d{4})-(\\d{2})-(\\d{2})$/';

        self::assertSame($expected, $map->replacePlaceholders($pattern));
    }

    #[Test]
    public function itKeepsUnknownPlaceholdersUntouched(): void
    {
        $map     = DatePlaceholderExpressionMap::default();
        $pattern = '/^{X}-{Y}$/';

        self::assertSame('/^{X}-(\\d{4})$/', $map->replacePlaceholders($pattern));
    }
}
