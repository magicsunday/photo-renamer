<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use DateTimeImmutable;
use DateTimeInterface;
use MagicSunday\Renamer\Helper\FileHelper;
use SplFileInfo;

/**
 * Centralizes filename-versus-metadata date drift calculation for commands that
 * need a consistent threshold decision without sharing a larger execution path.
 *
 * The analyzer stays deliberately narrow: it only computes whole-day drift
 * between two dates, or between a file's date-based filename and its metadata
 * timestamp. It does not decide whether a timestamp is reliable, nor whether a
 * command should rewrite metadata.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class DateDriftAnalyzer
{
    /**
     * Calculates the drift in days between two date values.
     *
     * The result is always the absolute day difference as reported by
     * `DateTimeInterface::diff()`. Sub-day clock differences therefore collapse
     * to zero, matching the command-level drift semantics already used by the
     * application.
     *
     * @param DateTimeInterface $expectedDateTime The reference date, usually derived from the filename
     * @param DateTimeInterface $actualDateTime   The metadata-derived date to compare against
     *
     * @return int|null Absolute drift in days, or null if PHP cannot provide a day count
     */
    public function calculateDateDriftInDays(
        DateTimeInterface $expectedDateTime,
        DateTimeInterface $actualDateTime,
    ): ?int {
        $drift = $expectedDateTime->diff($actualDateTime)->days;

        return $drift !== false ? $drift : null;
    }

    /**
     * Calculates the drift in days between a file's date-based filename and a
     * metadata timestamp.
     *
     * Commands such as `rename:verify` use this when they only have the current
     * file and the extracted metadata timestamp. Files without a date-based name
     * return null so callers can keep their existing "not comparable" behavior.
     *
     * @param SplFileInfo       $file           File whose pathname may contain a date
     * @param DateTimeInterface $actualDateTime The metadata-derived date to compare against
     *
     * @return int|null Absolute drift in days, or null when the filename has no parseable date
     */
    public function calculateFilenameDateDriftInDays(
        SplFileInfo $file,
        DateTimeInterface $actualDateTime,
    ): ?int {
        $filenameDateTime = FileHelper::extractDateTimeFromPath($file->getPathname());

        if (!$filenameDateTime instanceof DateTimeInterface) {
            return null;
        }

        return $this->calculateDateDriftInDays($filenameDateTime, $actualDateTime);
    }

    /**
     * Calculates the drift in days between a file's date-based filename and a
     * metadata timestamp using date-only semantics.
     *
     * This preserves the historical `rename:verify` behavior where drift means
     * calendar-day mismatch rather than elapsed 24-hour intervals. Both sides are
     * normalized to midnight before comparison, so values crossing midnight by a
     * few minutes still count as one day of drift.
     *
     * @param SplFileInfo       $file           File whose pathname may contain a date
     * @param DateTimeInterface $actualDateTime The metadata-derived date to compare against
     *
     * @return int|null Absolute drift in days, or null when the filename has no parseable date
     */
    public function calculateFilenameDateOnlyDriftInDays(
        SplFileInfo $file,
        DateTimeInterface $actualDateTime,
    ): ?int {
        $filenameDate = FileHelper::extractDateFromPath($file->getPathname());

        if (!$filenameDate instanceof DateTimeInterface) {
            return null;
        }

        return $this->calculateDateDriftInDays(
            $filenameDate,
            DateTimeImmutable::createFromInterface($actualDateTime)->setTime(0, 0),
        );
    }
}
