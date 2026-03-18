<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Metadata;

use DateTimeInterface;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Value\StructuredMetadata;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use SplFileInfo;
use Throwable;

use function sprintf;
use function trim;

/**
 * Extracts capture date and Apple Live Photo content identifier from image/video
 * files via the imagemeta {@see MetadataReader}. Accesses the typed
 * {@see StructuredMetadata} tree directly (no dynamic property lookups).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class MetadataExtractor implements MetadataExtractorInterface
{
    public function __construct(private MetadataReader $metadataReader)
    {
    }

    /**
     * Reads metadata from the given file and returns a {@see TemporalMetadata}
     * combining the capture timestamp and Live Photo content identifier.
     * Returns null when the file contains neither.
     *
     * @throws ExifMetadataReadException When the underlying metadata reader fails
     */
    public function extractTemporalMetadata(SplFileInfo $file): ?TemporalMetadata
    {
        try {
            $metadata = $this->metadataReader->read($file->getPathname());
        } catch (Throwable $exception) {
            throw new ExifMetadataReadException(
                sprintf(
                    'Unable to read image metadata from "%s": %s',
                    $file->getPathname(),
                    $exception->getMessage(),
                ),
                $exception->getCode(),
                previous: $exception,
            );
        }

        $structured  = $metadata->structured();
        $livePhotoId = $this->extractContentIdentifier($structured);

        [$captureDateTime, $isFallback, $isUtcWithoutTimezone] = $this->extractCaptureDateTimeWithFallbackFlag($structured);

        if (!($captureDateTime instanceof DateTimeInterface) && ($livePhotoId === null)) {
            return null;
        }

        return new TemporalMetadata($captureDateTime, $livePhotoId, $isFallback, $isUtcWithoutTimezone);
    }

    /**
     * Returns the capture timestamp, whether it came from the fallback DateTime
     * tag (0x0132), and whether it is a UTC timestamp without explicit timezone info.
     *
     * The imagemeta library's `temporal->original` uses `dateTimeOriginalBestEffort()`
     * which itself falls back through 0x9003 → 0x9004 → 0x0132, so we cannot
     * use it to distinguish the source. Instead, we check `temporal->create`
     * (pure 0x9004) and compare: if `temporal->original` is set but `temporal->create`
     * is null and both resolve to the same timestamp, the date came from the
     * generic capture fallback (0x0132), not from a dedicated date tag.
     *
     * @return array{DateTimeInterface|null, bool, bool} Tuple of [captureDateTime, isFallback, isUtcWithoutTimezone]
     */
    private function extractCaptureDateTimeWithFallbackFlag(StructuredMetadata $structured): array
    {
        $temporal = $structured->locationTime->temporal;
        $original = $temporal->original;
        $create   = $temporal->create;
        $modify   = $temporal->modify;
        $capture  = $structured->locationTime->capture->dateTime;

        // Prefer original, then create, then capture (which includes 0x0132 fallback).
        $dateTime = $original ?? $create ?? $capture;

        if (!$dateTime instanceof DateTimeInterface) {
            return [null, false, false];
        }

        // Fallback detection: if there's no dedicated create date (0x9004) and the
        // resolved timestamp equals the modify date (0x0132), the date is from the
        // generic DateTime tag — not from DateTimeOriginal or CreateDate.
        $isFallback = !($create instanceof DateTimeInterface)
            && ($modify instanceof DateTimeInterface)
            && ($dateTime->getTimestamp() === $modify->getTimestamp());

        // UTC-without-timezone detection: QuickTime containers may store timestamps
        // as Mac epoch (seconds since 1904) which are truly UTC, or as date strings
        // which are in the camera's local time. Unfortunately, there is no reliable way
        // to distinguish between the two — old cameras wrote local time, modern ones
        // write UTC, both end up with timezone "+00:00" after imagemeta processing.
        //
        // We only flag as "UTC without timezone" when the file has an explicit timezone
        // from the Keys CreationDate (Apple devices: temporal->tz is set) but the
        // resolved timestamp still appears to be UTC. This covers iPhone re-encodes
        // that have Keys metadata but whose mvhd dates are in UTC.
        //
        // For all other QuickTime files (no Keys metadata), we trust the timestamp
        // as-is and rely on the --timezone CLI option for manual correction and the
        // date-drift warning (--max-date-drift) to flag suspicious changes.
        $isUtcWithoutTimezone = false;

        return [$dateTime, $isFallback, $isUtcWithoutTimezone];
    }

    /**
     * Returns the Apple Live Photo content identifier from the Apple maker notes,
     * or null when the file is not part of a Live Photo pair. Empty/whitespace-only
     * identifiers are normalised to null.
     */
    private function extractContentIdentifier(StructuredMetadata $structured): ?string
    {
        $contentIdentifier = $structured->makerNotesApple?->identity?->contentIdentifier;

        if ($contentIdentifier === null) {
            return null;
        }

        $trimmed = trim($contentIdentifier);

        return $trimmed !== '' ? $trimmed : null;
    }
}
