<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model\Pattern;

use MagicSunday\Renamer\Model\Pattern\PatternMatch;
use MagicSunday\Renamer\Model\Pattern\PatternMatchSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PatternMatchSet::class)]
#[CoversClass(PatternMatch::class)]
class PatternMatchSetTest extends TestCase
{
    #[Test]
    public function itExtractsTokensAndPlaceholders(): void
    {
        $set = PatternMatchSet::fromPattern('/^{Y}-{m}-{d}$/');

        self::assertSame(['{Y}', '{m}', '{d}'], $set->tokens());
        self::assertSame(['Y', 'm', 'd'], $set->placeholders());
    }

    #[Test]
    public function itProvidesTypeSafeAccess(): void
    {
        $set   = PatternMatchSet::fromPattern('/^{H}$/');
        $match = $set->get(0);

        self::assertNotNull($match);
        self::assertSame('{H}', $match->getToken());
        self::assertSame('H', $match->getPlaceholder());
    }
}
