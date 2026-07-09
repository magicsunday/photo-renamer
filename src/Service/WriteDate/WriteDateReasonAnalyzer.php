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
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Service\DateDriftAnalyzer;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use SplFileInfo;

use function sprintf;

/**
 * Determines whether a file needs metadata repair and why.
 *
 * The write-date command distinguishes between missing metadata, fallback-only
 * timestamps, ambiguous QuickTime timezone data, and large drift between the
 * filename-derived date and the stored metadata. This analyzer centralizes that
 * policy so the command can stay focused on CLI flow and execution.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class WriteDateReasonAnalyzer
{
    /**
     * @param ExifMetadataProvider         $exifMetadataProvider Metadata provider used for reliability/fallback checks
     * @param DateDriftAnalyzer            $dateDriftAnalyzer    Calculates filename-versus-metadata drift consistently
     * @param MediaTypeClassifierInterface $mediaTypeClassifier  Distinguishes still images from video files
     */
    public function __construct(
        private ExifMetadataProvider $exifMetadataProvider,
        private DateDriftAnalyzer $dateDriftAnalyzer,
        private MediaTypeClassifierInterface $mediaTypeClassifier,
    ) {
    }

    /**
     * Analyzes whether a file should receive a metadata write and returns the reason.
     *
     * The analyzer preserves the existing command semantics: no metadata becomes
     * `nodata`, large date drift outranks reliability flags, reliable metadata
     * suppresses rewrites unless `--force` is active, and forced rewrites on
     * videos without other issues fall back to the timezone reason.
     *
     * @param SplFileInfo            $file             File being evaluated for a write-date repair
     * @param DateTimeInterface|null $captureDateTime  Currently resolved metadata capture date, if any
     * @param DateTimeImmutable      $filenameDateTime Filename-derived date-time used as ground truth
     * @param int                    $maxDateDrift     Maximum tolerated drift in days before this counts as mismatch
     * @param bool                   $force            Whether the command should override already-reliable metadata
     *
     * @return WriteDateReasonDecision|null Reason decision, or null when no write is required
     */
    public function analyze(
        SplFileInfo $file,
        ?DateTimeInterface $captureDateTime,
        DateTimeImmutable $filenameDateTime,
        int $maxDateDrift,
        bool $force = false,
    ): ?WriteDateReasonDecision {
        if (!$captureDateTime instanceof DateTimeInterface) {
            return new WriteDateReasonDecision(
                WriteDateReasonCatalog::NODATA,
                WriteDateReasonCatalog::formatLabel(WriteDateReasonCatalog::NODATA),
            );
        }

        if ($maxDateDrift > 0) {
            $drift = $this->dateDriftAnalyzer->calculateDateDriftInDays($filenameDateTime, $captureDateTime);

            if ($drift > $maxDateDrift) {
                return new WriteDateReasonDecision(
                    WriteDateReasonCatalog::DRIFT,
                    sprintf(WriteDateReasonCatalog::LABELS[WriteDateReasonCatalog::DRIFT], $drift),
                );
            }
        }

        if (!$force && $this->exifMetadataProvider->hasReliableDateTime($file)) {
            return null;
        }

        if ($this->exifMetadataProvider->isFallbackDateTime($file)) {
            return new WriteDateReasonDecision(
                WriteDateReasonCatalog::FALLBACK,
                WriteDateReasonCatalog::formatLabel(WriteDateReasonCatalog::FALLBACK),
            );
        }

        if ($this->exifMetadataProvider->isAmbiguousTimezone($file)) {
            return new WriteDateReasonDecision(
                WriteDateReasonCatalog::TIMEZONE,
                WriteDateReasonCatalog::formatLabel(WriteDateReasonCatalog::TIMEZONE),
            );
        }

        if ($force && !$this->mediaTypeClassifier->isLivePhotoStill($file)) {
            return new WriteDateReasonDecision(
                WriteDateReasonCatalog::TIMEZONE,
                'forced re-write of timezone metadata',
            );
        }

        return null;
    }
}
