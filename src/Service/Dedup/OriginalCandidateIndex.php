<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dedup;

use SplFileInfo;

/**
 * Stores original-file candidates keyed by their clean basename for the
 * filename-based dedup command.
 *
 * The index is intentionally lightweight: it does not classify files or decide
 * which candidate should win. It only provides efficient lookup of possible
 * originals that can later be evaluated by the matcher policy.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OriginalCandidateIndex
{
    /**
     * @param array<string, list<SplFileInfo>> $candidatesByBasename Candidates keyed by clean basename
     */
    public function __construct(
        private array $candidatesByBasename,
    ) {
    }

    /**
     * Returns all indexed candidates for the given clean basename.
     *
     * @param string $basename Basename without extension and without duplicate suffix
     *
     * @return list<SplFileInfo> Indexed candidates for the basename
     */
    public function getCandidatesForBasename(string $basename): array
    {
        return $this->candidatesByBasename[$basename] ?? [];
    }
}
