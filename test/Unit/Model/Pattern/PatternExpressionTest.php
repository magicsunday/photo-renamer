<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model\Pattern;

use MagicSunday\Renamer\Model\Pattern\DatePlaceholderExpressionMap;
use MagicSunday\Renamer\Model\Pattern\PatternExpression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PatternExpression::class)]
class PatternExpressionTest extends TestCase
{
    #[Test]
    public function itCreatesRegexFromTemplate(): void
    {
        $template   = '/^{Y}{m}$/';
        $expression = PatternExpression::fromTemplate(
            $template,
            DatePlaceholderExpressionMap::default()
        );

        self::assertSame($template, $expression->getTemplate());
        self::assertSame('/^(\\d{4})(\\d{2})$/', $expression->getRegex());
    }
}
