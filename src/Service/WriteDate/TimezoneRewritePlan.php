<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\WriteDate;

use DateTimeImmutable;

/**
 * Immutable write-plan result for timezone-specific metadata rewrites.
 *
 * The write-date command needs two pieces of information for a timezone repair:
 * the local date-time that should be written and whether the original QuickTime
 * CreateDate atom must stay preserved while only Keys:CreationDate receives the
 * corrected local offset.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class TimezoneRewritePlan
{
    /**
     * @param DateTimeImmutable $writeDateTime      Planned local date-time to write into metadata
     * @param bool              $preserveCreateDate Whether the original QuickTime CreateDate must remain untouched
     */
    public function __construct(
        public DateTimeImmutable $writeDateTime,
        public bool $preserveCreateDate,
    ) {
    }
}
