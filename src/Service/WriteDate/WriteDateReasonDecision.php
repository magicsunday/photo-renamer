<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\WriteDate;

/**
 * Immutable analyzer result describing why write-date should touch a file.
 *
 * The command only needs a stable reason key for filtering plus a rendered
 * label for human output. This small value object keeps the analyzer result
 * explicit and avoids tuple-style arrays in the command/application layer.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class WriteDateReasonDecision
{
    /**
     * @param string $key   Stable reason key used for filtering and summaries
     * @param string $label Human-readable reason text shown in command output
     */
    public function __construct(
        public string $key,
        public string $label,
    ) {
    }
}
