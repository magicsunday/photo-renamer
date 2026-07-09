<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\LivePhoto;

/**
 * Holds the exact-second and fallback-second candidate indexes for one side of
 * the Live Photo conflict matching process.
 *
 * The detector prefers same-second matches over fallback-window matches. This
 * DTO keeps that preference explicit without leaking `exact` / `fallback`
 * string keys across the matching code.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class LivePhotoConflictCandidateTiers
{
    /**
     * @var list<int>
     */
    private array $exact = [];

    /**
     * @var list<int>
     */
    private array $fallback = [];

    /**
     * Adds a same-second candidate index.
     *
     * @param int $candidateIndex Candidate index from the opposite asset list
     */
    public function addExact(int $candidateIndex): void
    {
        $this->exact[] = $candidateIndex;
    }

    /**
     * Adds a fallback-window candidate index.
     *
     * @param int $candidateIndex Candidate index from the opposite asset list
     */
    public function addFallback(int $candidateIndex): void
    {
        $this->fallback[] = $candidateIndex;
    }

    /**
     * Returns the preferred candidates, favoring same-second matches over
     * fallback-window matches.
     *
     * @return list<int>
     */
    public function preferredCandidates(): array
    {
        if ($this->exact !== []) {
            return $this->exact;
        }

        return $this->fallback;
    }
}
