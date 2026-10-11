<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Helper;

use MagicSunday\Renamer\Exception\AnalysisMemoryBudgetExceededException;

use function ini_get;
use function ini_parse_quantity;
use function intdiv;
use function max;
use function memory_get_usage;
use function min;
use function sprintf;

/**
 * Stops incremental, retained PHP analysis before exhausting a finite budget.
 * Keep a bounded reporting/cleanup reserve, without estimating media decoder
 * memory or excluding files. Checks belong before mutation; a single large
 * allocation between checkpoints and native/kernel limits remain independent.
 */
final readonly class AnalysisMemoryBudget
{
    /**
     * Reads actual PHP allocation and its effective limit at a growth checkpoint.
     * CLI startup enforces a finite limit; an unlimited developer/test process
     * has no PHP boundary to predict and keeps its existing behavior.
     *
     * @param string $phase Fixed, trusted description of the pre-mutation phase
     *
     * @return void
     *
     * @throws AnalysisMemoryBudgetExceededException When allocated PHP memory reaches the reserved boundary
     */
    public static function assertHeadroom(string $phase): void
    {
        self::assertWithinBudget(ini_parse_quantity(ini_get('memory_limit')), memory_get_usage(true), $phase);
    }

    /**
     * Evaluates an observed allocation without allocating a synthetic object
     * graph. Reserve one eighth of the budget, bounded to 8–64 MiB, for error
     * output and cleanup. This operational margin is not a capacity forecast.
     *
     * @param int    $limitBytes     Effective PHP limit; non-positive means unlimited
     * @param int    $allocatedBytes Currently allocated PHP pages, including unused pages
     * @param string $phase          Trusted pre-mutation checkpoint description
     *
     * @return void
     *
     * @throws AnalysisMemoryBudgetExceededException When the observed allocation leaves insufficient reserve
     */
    public static function assertWithinBudget(int $limitBytes, int $allocatedBytes, string $phase): void
    {
        if ($limitBytes <= 0) {
            return;
        }

        $reserve = min(64 * 1024 * 1024, max(8 * 1024 * 1024, intdiv($limitBytes, 8)));

        if ($allocatedBytes >= $limitBytes - $reserve) {
            throw new AnalysisMemoryBudgetExceededException(sprintf(
                'Analysis incomplete during %s: PHP allocated %d bytes of %d; %d bytes are reserved for reporting and cleanup. No media mutation started. Use a larger finite PHP_MEMORY_LIMIT with sufficient container/host memory.',
                $phase,
                $allocatedBytes,
                $limitBytes,
                $reserve,
            ));
        }
    }
}
