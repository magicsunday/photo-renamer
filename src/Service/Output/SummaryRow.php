<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Output;

/**
 * Immutable label/value row for aligned console summary tables.
 *
 * Multiple commands project counters into the same two-column summary layout.
 * This DTO replaces repeated `list<array{string, string}>` contracts at
 * collaborator boundaries while keeping the rendering model intentionally small.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class SummaryRow
{
    /**
     * @param string $label Operator-facing row label shown in the first column
     * @param string $value Already formatted value shown in the second column
     */
    public function __construct(
        public string $label,
        public string $value,
    ) {
    }
}
