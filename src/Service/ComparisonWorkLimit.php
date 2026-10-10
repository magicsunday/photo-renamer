<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use InvalidArgumentException;
use RuntimeException;

use function filter_var;
use function sprintf;

use const FILTER_VALIDATE_INT;

/**
 * Bounds candidate-pair visits in each capture group and cross-group video batch.
 *
 * A shared immutable policy keeps both quadratic analysis paths consistent while
 * counters remain local to one analysis. Exceeding the limit fails explicitly;
 * it never turns an unfinished comparison into proof of a duplicate.
 */
final readonly class ComparisonWorkLimit
{
    /**
     * @param int $maximumPairs Finite positive candidate-pair visit limit per scope
     *
     * @throws InvalidArgumentException When no finite positive budget is supplied
     */
    public function __construct(private int $maximumPairs)
    {
        if ($maximumPairs <= 0) {
            throw new InvalidArgumentException('MAX_COMPARISON_PAIRS must be a positive integer.');
        }
    }

    /**
     * Parses the operator's budget without silently rounding decimals or overflow.
     *
     * @param string $maximumPairs Environment value containing a positive integer
     *
     * @return ComparisonWorkLimit Validated finite comparison policy
     *
     * @throws InvalidArgumentException When the value is not a positive PHP integer
     */
    public static function fromEnvironment(string $maximumPairs): self
    {
        $parsed = filter_var($maximumPairs, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($parsed === false) {
            throw new InvalidArgumentException('MAX_COMPARISON_PAIRS must be a positive integer.');
        }

        return new self($parsed);
    }

    /**
     * Rejects excess work before another expensive analysis or pair allocation.
     *
     * @param int    $visitedPairs Candidate pairs already visited, including this one
     * @param string $scope        Static operator-facing analysis phase description
     *
     * @return void
     *
     * @throws RuntimeException When the configured work limit would be exceeded
     */
    public function assertWithinLimit(int $visitedPairs, string $scope): void
    {
        if ($visitedPairs > $this->maximumPairs) {
            throw new RuntimeException(sprintf(
                'Comparison work limit exceeded for %s (MAX_COMPARISON_PAIRS=%d). Analysis is incomplete; split the input or review and increase the finite budget.',
                $scope,
                $this->maximumPairs,
            ));
        }
    }
}
