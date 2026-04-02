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
use DateTimeZone;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use SplFileInfo;

use function in_array;
use function strtolower;

/**
 * Scans files and produces the pending metadata writes for write-date.
 *
 * This analyzer owns the first pass of the command: file-type filtering,
 * filename-date extraction, metadata reads, write-reason classification,
 * optional reason filtering, and timezone-specific rewrite planning. The
 * command keeps only CLI interaction, confirmation, rendering, and execution.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class WriteDateCandidateAnalyzer
{
    /**
     * Extensions where exiftool cannot write date metadata reliably.
     * AVI uses a RIFF container where QuickTime atom writes silently fail.
     */
    private const array UNSUPPORTED_WRITE_EXTENSIONS = ['avi'];

    /**
     * @param ExifMetadataProvider         $exifMetadataProvider    Metadata provider used during scan
     * @param MediaTypeClassifierInterface $mediaTypeClassifier     Distinguishes still images from video files
     * @param WriteDateReasonAnalyzer      $writeDateReasonAnalyzer Determines why a file needs metadata repair
     * @param TimezoneRewritePlanner       $timezoneRewritePlanner  Plans timezone-specific write values
     */
    public function __construct(
        private ExifMetadataProvider $exifMetadataProvider,
        private MediaTypeClassifierInterface $mediaTypeClassifier,
        private WriteDateReasonAnalyzer $writeDateReasonAnalyzer,
        private TimezoneRewritePlanner $timezoneRewritePlanner,
    ) {
    }

    /**
     * Scans files and returns the resulting write candidates plus scan counters.
     *
     * The analyzer preserves the existing command behaviour:
     * unsupported media types are ignored entirely, unsupported write targets are
     * counted separately, missing filename dates are tracked, metadata read errors
     * are counted, and already-correct files remain in the summary even when they
     * were excluded by a reason filter.
     *
     * @param list<SplFileInfo>     $files           Files to inspect for metadata repairs
     * @param int                   $maxDateDrift    Maximum tolerated date drift before classifying as mismatch
     * @param list<string>|null     $reasonFilter    Optional reason keys to keep
     * @param bool                  $force           Whether already-reliable metadata may still be rewritten
     * @param bool                  $localAsUtc      Whether raw QuickTime clock time should be treated as local time
     * @param DateTimeZone|null     $timezone        Configured target timezone for QuickTime repairs
     * @param callable(): void|null $progressAdvance Optional hook invoked after each scanned file
     *
     * @return WriteDateScanResult Pending writes and counters for the command summary
     */
    public function scan(
        array $files,
        int $maxDateDrift,
        ?array $reasonFilter,
        bool $force,
        bool $localAsUtc,
        ?DateTimeZone $timezone,
        ?callable $progressAdvance = null,
    ): WriteDateScanResult {
        $scannedFiles     = 0;
        $alreadyCorrect   = 0;
        $noDateInName     = 0;
        $readErrors       = 0;
        $unsupportedWrite = 0;

        /** @var list<WriteDatePendingWrite> $pendingWrites */
        $pendingWrites = [];

        foreach ($files as $file) {
            ++$scannedFiles;

            if ($progressAdvance !== null) {
                $progressAdvance();
            }

            $extension = strtolower($file->getExtension());

            // Skip unsupported file types
            if (!in_array($extension, Constants::SUPPORTED_MEDIA_EXTENSIONS, true)) {
                continue;
            }

            // Skip extensions where exiftool cannot write metadata reliably
            if (in_array($extension, self::UNSUPPORTED_WRITE_EXTENSIONS, true)) {
                ++$unsupportedWrite;

                continue;
            }

            // Extract date+time from filename
            $filenameDateTime = FileHelper::extractDateTimeFromPath($file->getPathname());

            if (!$filenameDateTime instanceof DateTimeImmutable) {
                ++$noDateInName;

                continue;
            }

            // Read current metadata
            try {
                $captureDateTime = $this->exifMetadataProvider->getCaptureDateTime($file);
            } catch (ExifMetadataReadException) {
                ++$readErrors;

                continue;
            }

            $reasonDecision = $this->writeDateReasonAnalyzer->analyze(
                $file,
                $captureDateTime,
                $filenameDateTime,
                $maxDateDrift,
                $force,
            );

            if (!$reasonDecision instanceof WriteDateReasonDecision) {
                ++$alreadyCorrect;

                continue;
            }

            if (($reasonFilter !== null) && (!in_array($reasonDecision->key, $reasonFilter, true))) {
                ++$alreadyCorrect;

                continue;
            }

            // For timezone reason: fix QuickTime timestamps lacking timezone info.
            // --local-as-utc: camera stored local time as "UTC" (non-Apple cameras).
            //   → Keep the existing time, just add the timezone offset.
            // Default: camera stored real UTC (Apple/DJI).
            //   → Convert UTC to local time using the configured timezone.
            // For all other reasons: use the filename date as the write value.
            $rewritePlan = $this->timezoneRewritePlanner->plan(
                $file,
                $reasonDecision->key,
                $filenameDateTime,
                $force,
                $localAsUtc,
                $timezone,
            );

            $pendingWrites[] = new WriteDatePendingWrite(
                $file->getPathname(),
                $reasonDecision->key,
                $reasonDecision->label,
                !$this->mediaTypeClassifier->isLivePhotoStill($file),
                $rewritePlan->writeDateTime,
                $rewritePlan->preserveCreateDate,
            );
        }

        return new WriteDateScanResult(
            $scannedFiles,
            $alreadyCorrect,
            $noDateInName,
            $readErrors,
            $unsupportedWrite,
            $pendingWrites,
        );
    }
}
