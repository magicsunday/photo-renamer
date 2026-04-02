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
 * Immutable write instruction produced by the write-date scan phase.
 *
 * The analyzer resolves all policy questions up front and returns explicit
 * write candidates that the command can later render, confirm, and execute
 * without re-deriving metadata decisions.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class WriteDatePendingWrite
{
    /**
     * @param string            $path               Absolute file path that should be updated
     * @param string            $reasonKey          Stable reason key for filtering and summaries
     * @param string            $reasonLabel        Human-readable explanation for the write
     * @param bool              $isVideo            Whether the file should be written through QuickTime fields
     * @param DateTimeImmutable $writeDateTime      Effective local date-time to write into metadata
     * @param bool              $preserveCreateDate Whether QuickTime CreateDate must remain untouched while Keys:CreationDate is repaired
     */
    public function __construct(
        public string $path,
        public string $reasonKey,
        public string $reasonLabel,
        public bool $isVideo,
        public DateTimeImmutable $writeDateTime,
        public bool $preserveCreateDate,
    ) {
    }
}
