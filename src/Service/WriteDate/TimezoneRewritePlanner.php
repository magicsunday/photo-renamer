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
use DateTimeInterface;
use DateTimeZone;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use SplFileInfo;

/**
 * Plans timezone-specific metadata rewrites for QuickTime-based media.
 *
 * The write-date command distinguishes normal filename-to-metadata repairs from
 * QuickTime timezone repairs. This planner owns the latter policy: it decides
 * whether the existing raw QuickTime timestamp should be interpreted as local
 * time stored as UTC (`--local-as-utc`) or as real UTC that needs conversion to
 * the configured local timezone.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class TimezoneRewritePlanner
{
    /**
     * @param ExifMetadataProvider $exifMetadataProvider Metadata provider used to read raw QuickTime timestamps
     */
    public function __construct(
        private ExifMetadataProvider $exifMetadataProvider,
    ) {
    }

    /**
     * Plans the effective write date-time and preservation mode for a file.
     *
     * Non-timezone reasons fall back to the filename-derived date with no special
     * preserve flag. Timezone repairs use the raw QuickTime timestamp when
     * available; otherwise they also fall back to the filename date.
     *
     * @param SplFileInfo       $file             File that may need a timezone-specific repair
     * @param string            $reasonKey        Reason key selected by the command
     * @param DateTimeImmutable $filenameDateTime Filename-derived date-time used as the general fallback
     * @param bool              $force            Whether the command is forcing a rewrite of already reliable metadata
     * @param bool              $localAsUtc       Whether the observed QuickTime clock time should be treated as local time
     * @param DateTimeZone|null $timezone         Configured target timezone, or null when none is available
     *
     * @return TimezoneRewritePlan Planned write information for the command
     */
    public function plan(
        SplFileInfo $file,
        string $reasonKey,
        DateTimeImmutable $filenameDateTime,
        bool $force,
        bool $localAsUtc,
        ?DateTimeZone $timezone,
    ): TimezoneRewritePlan {
        if ($reasonKey !== WriteDateReasonCatalog::TIMEZONE) {
            return new TimezoneRewritePlan($filenameDateTime, false);
        }

        $rawDateTime = $force
            ? ($this->exifMetadataProvider->getRawQuickTimeCreateDate($file) ?? $this->exifMetadataProvider->getRawCaptureDateTime($file))
            : $this->exifMetadataProvider->getRawCaptureDateTime($file);

        if (($rawDateTime instanceof DateTimeInterface) && ($timezone instanceof DateTimeZone)) {
            if ($localAsUtc) {
                return new TimezoneRewritePlan(
                    new DateTimeImmutable(
                        $rawDateTime->format('Y-m-d H:i:s'),
                        $timezone,
                    ),
                    false,
                );
            }

            return new TimezoneRewritePlan(
                DateTimeImmutable::createFromInterface($rawDateTime)->setTimezone($timezone),
                true,
            );
        }

        return new TimezoneRewritePlan($filenameDateTime, false);
    }
}
