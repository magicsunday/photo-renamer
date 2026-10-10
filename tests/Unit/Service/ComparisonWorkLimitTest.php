<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use InvalidArgumentException;
use MagicSunday\Renamer\Service\ComparisonWorkLimit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies that work budgets are finite, inclusive and explicit on exhaustion.
 * Strict parsing protects operator intent from silent rounding or integer overflow.
 */
#[CoversClass(ComparisonWorkLimit::class)]
final class ComparisonWorkLimitTest extends TestCase
{
    /**
     * Allows precisely the configured number of visits; the next one must fail
     * before callers can analyze or allocate another candidate pair.
     */
    #[Test]
    public function acceptsTheExactBudgetAndRejectsTheNextVisit(): void
    {
        $limit = ComparisonWorkLimit::fromEnvironment('2');
        $limit->assertWithinLimit(1, 'synthetic batch');
        $limit->assertWithinLimit(2, 'synthetic batch');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/MAX_COMPARISON_PAIRS=2/');
        $limit->assertWithinLimit(3, 'synthetic batch');
    }

    /**
     * Rejects zero or negative injected budgets instead of offering unlimited work.
     *
     * @param int $budget Invalid directly injected comparison budget
     */
    #[Test]
    #[DataProvider('invalidIntegerBudgets')]
    public function rejectsNonPositiveBudgets(int $budget): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ComparisonWorkLimit($budget);
    }

    /**
     * @return iterable<string, array{int}> Non-positive integer configurations
     */
    public static function invalidIntegerBudgets(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    /**
     * Rejects malformed, fractional or overflowing environment budgets rather
     * than inheriting Symfony's integer processor's floating-point coercion.
     *
     * @param string $value Invalid environment value
     */
    #[Test]
    #[DataProvider('invalidEnvironmentBudgets')]
    public function rejectsInvalidEnvironmentBudgets(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComparisonWorkLimit::fromEnvironment($value);
    }

    /**
     * @return iterable<string, array{string}> Invalid finite-budget representations
     */
    public static function invalidEnvironmentBudgets(): iterable
    {
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'decimal' => ['2.5'];
        yield 'scientific' => ['1e6'];
        yield 'unlimited' => ['unlimited'];
        yield 'overflow' => ['999999999999999999999999999999'];
    }
}
