<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Helper;

use MagicSunday\Renamer\Exception\AnalysisMemoryBudgetExceededException;
use MagicSunday\Renamer\Helper\AnalysisMemoryBudget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnalysisMemoryBudget::class)]
#[UsesClass(AnalysisMemoryBudgetExceededException::class)]
/**
 * Verifies finite PHP reporting reserves without consuming the runner's memory.
 * Boundary cases cover low budgets and the capped reserve used by large runs.
 */
final class AnalysisMemoryBudgetTest extends TestCase
{
    /**
     * Allocations strictly below the reporting boundary may continue. The
     * developer-only unlimited setting has no finite PHP boundary to predict.
     *
     * @param int $limitMiB Effective PHP budget in MiB
     * @param int $usedMiB  Allocated PHP pages in MiB
     *
     * @return void
     */
    #[Test]
    #[DataProvider('safeAllocations')]
    public function allowsAllocationBelowReservedBoundary(int $limitMiB, int $usedMiB): void
    {
        AnalysisMemoryBudget::assertWithinBudget($limitMiB * 1024 * 1024, $usedMiB * 1024 * 1024, 'synthetic analysis');
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{int, int}> Finite under-boundary and unlimited observations
     */
    public static function safeAllocations(): iterable
    {
        yield '32 MiB keeps minimum reserve' => [32, 23];
        yield '128 MiB uses proportional reserve' => [128, 111];
        yield '1 GiB caps reserve at 64 MiB' => [1024, 959];
        yield '3 GiB keeps capped reserve' => [3072, 3007];
        yield 'unlimited developer process' => [-1, 4096];
    }

    /**
     * Hitting or crossing the planned boundary must throw an explicit failure
     * rather than permitting allocation until PHP emits an uncatchable fatal.
     *
     * @param int $limitMiB Effective PHP budget in MiB
     * @param int $usedMiB  Allocated PHP pages in MiB
     *
     * @return void
     */
    #[Test]
    #[DataProvider('unsafeAllocations')]
    public function rejectsBoundaryAndOvershootWithIncompleteAnalysis(int $limitMiB, int $usedMiB): void
    {
        try {
            AnalysisMemoryBudget::assertWithinBudget($limitMiB * 1024 * 1024, $usedMiB * 1024 * 1024, 'synthetic analysis');
            self::fail('Insufficient headroom must stop analysis.');
        } catch (AnalysisMemoryBudgetExceededException $exception) {
            self::assertStringContainsString('Analysis incomplete during synthetic analysis', $exception->getMessage());
            self::assertStringContainsString('No media mutation started', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int, int}> Exact boundaries and an already exceeded limit
     */
    public static function unsafeAllocations(): iterable
    {
        yield '32 MiB boundary' => [32, 24];
        yield '128 MiB boundary' => [128, 112];
        yield '1 GiB boundary' => [1024, 960];
        yield '3 GiB boundary' => [3072, 3008];
        yield 'overshoot' => [64, 65];
    }

    /**
     * The actual PHPUnit runner has a finite 4-GiB budget. Exercise observation
     * of its current ini limit and allocated pages without changing global ini.
     *
     * @return void
     */
    #[Test]
    public function observesActualRuntimeHeadroom(): void
    {
        AnalysisMemoryBudget::assertHeadroom('runner observation');
        $this->addToAssertionCount(1);
    }
}
