<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Output;

use MagicSunday\Renamer\Model\OutputEntryTag;

/**
 * Immutable result of applying local output-tag adjustments for one entry.
 *
 * RenameOutputRenderer upgrades tags and warning text after the primary entry
 * classification, for example when date-drift policy turns a rename into a
 * warning. This DTO keeps that local output-boundary contract explicit instead
 * of passing a positional tag/reason tuple through multiple call sites.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OutputEntryTagResolution
{
    /**
     * @param OutputEntryTag $tag           Final output tag after local adjustments
     * @param string|null    $warningReason Operator-facing warning reason attached to the entry
     */
    public function __construct(
        public OutputEntryTag $tag,
        public ?string $warningReason,
    ) {
    }
}
