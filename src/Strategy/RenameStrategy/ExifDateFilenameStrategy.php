<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy;

use DateTimeImmutable;
use DateTimeInterface;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use Override;
use SplFileInfo;

use function basename;
use function mb_strlen;
use function mb_substr;
use function min;
use function rtrim;

/**
 * Generates target filenames by formatting the EXIF DateTimeOriginal value
 * according to a user-supplied PHP date() pattern (e.g. "Ymd_His"). The capture
 * timestamp already includes subsecond precision from the metadata extractor,
 * so formatting is a single {@see DateTimeInterface::format()} call.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ExifDateFilenameStrategy implements LivePhotoAwareRenameStrategyInterface, MetadataAwareRenameStrategyInterface
{
    /**
     * @param string               $targetFilenamePattern PHP date() format string defining the target basename
     * @param ExifMetadataProvider $exifMetadataProvider  Caching EXIF metadata accessor
     */
    public function __construct(
        private string $targetFilenamePattern,
        private ExifMetadataProvider $exifMetadataProvider,
    ) {
    }

    /**
     * Formats the EXIF capture date into a target filename using the configured pattern.
     * Returns null when the file has no usable EXIF date, causing it to be skipped
     * by the pipeline.
     *
     * @param SplFileInfo $splFileInfo Source file to read EXIF data from
     *
     * @return string|null Target filename with extension, or null when EXIF date is absent
     *
     * @throws TargetFilenameException When reading EXIF metadata fails
     */
    #[Override]
    public function generateFilename(SplFileInfo $splFileInfo): ?string
    {
        $captureDateTime = $this->exifMetadataProvider->getCaptureDateTime($splFileInfo);

        if (!$captureDateTime instanceof DateTimeInterface) {
            return null;
        }

        $targetBasename = basename($captureDateTime->format($this->targetFilenamePattern));

        // Strip the zero-time portion when the capture date has no time info
        // (midnight with zero subseconds). Formats the same pattern with a known
        // non-zero time and compares to find what the zero-time suffix looks like,
        // then removes it. This works with any user-supplied pattern.
        if ($captureDateTime->format('H:i:s.v') === '00:00:00.000') {
            $referenceDate = DateTimeImmutable::createFromInterface($captureDateTime)
                ->setTime(12, 34, 56, 789000);

            $withTime    = basename($referenceDate->format($this->targetFilenamePattern));
            $withoutTime = basename($captureDateTime->format($this->targetFilenamePattern));

            // Find the common prefix between "date+nonzero-time" and "date+zero-time".
            // Everything after the common prefix in the zero-time version is the zero suffix.
            $commonLen = 0;
            $minLen    = min(mb_strlen($withTime), mb_strlen($withoutTime));

            while ($commonLen < $minLen && mb_substr($withTime, $commonLen, 1) === mb_substr($withoutTime, $commonLen, 1)) {
                ++$commonLen;
            }

            if ($commonLen > 0) {
                $targetBasename = rtrim(mb_substr($withoutTime, 0, $commonLen), '-_.');
            }
        }

        return $targetBasename . '.' . FileHelper::normalizeExtension($splFileInfo->getExtension());
    }

    /**
     * Returns whether the given file's capture date came from the fallback
     * DateTime tag (0x0132) instead of DateTimeOriginal or CreateDate.
     *
     * @param SplFileInfo $splFileInfo File to query
     *
     * @return bool True when the date came from the fallback tag
     */
    public function isFallbackDateTime(SplFileInfo $splFileInfo): bool
    {
        return $this->exifMetadataProvider->isFallbackDateTime($splFileInfo);
    }

    /**
     * Returns whether the given file has an ambiguous timezone.
     */
    public function isAmbiguousTimezone(SplFileInfo $splFileInfo): bool
    {
        return $this->exifMetadataProvider->isAmbiguousTimezone($splFileInfo);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getLivePhotoContentIdentifier(SplFileInfo $splFileInfo): ?string
    {
        return $this->exifMetadataProvider->getContentIdentifier($splFileInfo);
    }
}
