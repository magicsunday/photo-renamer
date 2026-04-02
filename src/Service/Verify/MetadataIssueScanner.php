<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Verify;

use DateTimeInterface;
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Service\DateDriftAnalyzer;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use SplFileInfo;

use function dirname;
use function in_array;
use function is_string;
use function strtolower;

/**
 * Scans files for per-file metadata issues before Live Photo completeness checks.
 *
 * This service owns the first verification pass: supported-type checks, metadata
 * extraction, reliability checks, drift detection, and content-identifier
 * collection. The later directory-level Live Photo completeness analysis stays
 * separate because it depends on the aggregated content-id map produced here.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class MetadataIssueScanner
{
    /**
     * @param ExifMetadataProvider         $exifMetadataProvider Metadata provider used during verification
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
     * Scans files and classifies per-file metadata issues.
     *
     * The scanner returns all categories except `livephoto`, because missing
     * companion analysis requires a second pass over the aggregated content-id
     * map. Entry formatting is delegated back to the command through a callback
     * so the scanner can stay focused on domain classification rather than CLI
     * presentation details.
     *
     * @param list<SplFileInfo>                                            $files           Files to verify
     * @param string                                                       $sourceDirectory Root used for relative-path calculation
     * @param int                                                          $maxDateDrift    Maximum tolerated calendar-day drift before categorizing as mismatch
     * @param callable(string, string, string, ?DateTimeInterface): string $entryFormatter  Formats category entries for output
     * @param callable(): void|null                                        $progressAdvance Optional hook that is invoked after each scanned file
     *
     * @return VerifyScanResult Categorized findings, counters, and the content-id map for the next pass
     */
    public function scan(
        array $files,
        string $sourceDirectory,
        int $maxDateDrift,
        callable $entryFormatter,
        ?callable $progressAdvance = null,
    ): VerifyScanResult {
        /** @var array<string, list<string>> $categories */
        $categories = [
            VerifyCategoryCatalog::TIMEZONE  => [],
            VerifyCategoryCatalog::FALLBACK  => [],
            VerifyCategoryCatalog::DRIFT     => [],
            VerifyCategoryCatalog::LIVEPHOTO => [],
            VerifyCategoryCatalog::ERROR     => [],
            VerifyCategoryCatalog::NODATA    => [],
            VerifyCategoryCatalog::FILETYPE  => [],
        ];

        /**
         * @var array<string, array<string, list<array{pathname: string, isStill: bool}>>> $contentIdMap
         */
        $contentIdMap = [];

        $scannedFiles = 0;
        $okCount      = 0;

        foreach ($files as $file) {
            ++$scannedFiles;

            if ($progressAdvance !== null) {
                $progressAdvance();
            }

            $relativePath = FileHelper::relativizePath($file->getPathname(), $sourceDirectory);
            $extension    = strtolower($file->getExtension());

            if (!in_array($extension, Constants::SUPPORTED_MEDIA_EXTENSIONS, true)) {
                $categories[VerifyCategoryCatalog::FILETYPE][] = $relativePath;

                continue;
            }

            try {
                $captureDateTime = $this->exifMetadataProvider->getCaptureDateTime($file);
            } catch (ExifMetadataReadException) {
                $categories[VerifyCategoryCatalog::ERROR][] = $relativePath;

                continue;
            }

            if (!$captureDateTime instanceof DateTimeInterface) {
                $contentId = $this->exifMetadataProvider->getContentIdentifier($file);

                if (is_string($contentId)) {
                    $this->addToContentIdMap($contentIdMap, $file, $contentId);
                }

                $categories[VerifyCategoryCatalog::NODATA][] = $entryFormatter(
                    $relativePath,
                    $file->getPathname(),
                    VerifyCategoryCatalog::NODATA,
                    null,
                );

                continue;
            }

            $hasIssue = false;

            if (!$this->exifMetadataProvider->hasReliableDateTime($file)) {
                if ($this->exifMetadataProvider->isAmbiguousTimezone($file)) {
                    $categories[VerifyCategoryCatalog::TIMEZONE][] = $entryFormatter(
                        $relativePath,
                        $file->getPathname(),
                        VerifyCategoryCatalog::TIMEZONE,
                        $captureDateTime,
                    );
                    $hasIssue = true;
                }

                if ($this->exifMetadataProvider->isFallbackDateTime($file)) {
                    $categories[VerifyCategoryCatalog::FALLBACK][] = $entryFormatter(
                        $relativePath,
                        $file->getPathname(),
                        VerifyCategoryCatalog::FALLBACK,
                        $captureDateTime,
                    );
                    $hasIssue = true;
                }
            }

            if ($maxDateDrift > 0) {
                $drift = $this->dateDriftAnalyzer->calculateFilenameDateOnlyDriftInDays($file, $captureDateTime);

                if (($drift !== null) && ($drift > $maxDateDrift)) {
                    $categories[VerifyCategoryCatalog::DRIFT][] = $relativePath;
                    $hasIssue                                   = true;
                }
            }

            $contentId = $this->exifMetadataProvider->getContentIdentifier($file);

            if (is_string($contentId)) {
                $this->addToContentIdMap($contentIdMap, $file, $contentId);
            }

            if (!$hasIssue) {
                ++$okCount;
            }
        }

        return new VerifyScanResult(
            $scannedFiles,
            $okCount,
            $categories,
            $contentIdMap,
        );
    }

    /**
     * Adds a file's content identifier to the per-directory content-ID map.
     *
     * @param array<string, array<string, list<array{pathname: string, isStill: bool}>>> $contentIdMap Mutable aggregation map
     * @param SplFileInfo                                                                $file         File contributing the content identifier
     * @param string                                                                     $contentId    Apple content identifier extracted from metadata
     */
    private function addToContentIdMap(array &$contentIdMap, SplFileInfo $file, string $contentId): void
    {
        $directory = dirname($file->getPathname());
        $isStill   = $this->mediaTypeClassifier->isLivePhotoStill($file);

        $contentIdMap[$directory][$contentId][] = [
            'pathname' => $file->getPathname(),
            'isStill'  => $isStill,
        ];
    }
}
