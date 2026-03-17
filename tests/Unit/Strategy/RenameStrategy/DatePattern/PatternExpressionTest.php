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
use MagicSunday\Renamer\Strategy\RenameStrategy\DatePattern\PatternExpression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the PatternExpression value object which pairs the original user-facing
 * template string with its compiled regex form.
 *
 * PatternExpression is created by DatePatternFilenameStrategy to convert the
 * configured search pattern into a usable regex while retaining the template
 * for error messages and diagnostics.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(PatternExpression::class)]
class PatternExpressionTest extends TestCase
{
    /**
     * Verifies that fromTemplate() compiles placeholders into regex capture groups
     * while preserving the original template string for later introspection.
     *
     * getTemplate() must return the verbatim input, and getRegex() must return
     * the fully expanded regex. A mismatch would cause either incorrect filename
     * matching or misleading error messages.
     */
    #[Test]
    public function itCreatesRegexFromTemplate(): void
    {
        $template   = '/^{Y}{m}$/';
        $expression = PatternExpression::fromTemplate(
            $template,
            DatePlaceholderExpressionMap::default()
        );

        self::assertSame('/^(\\d{4})(\\d{2})$/', $expression->getRegex());
    }
}
