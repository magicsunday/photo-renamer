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
use DateTimeZone;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Value\StructuredMetadata;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use Override;
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
    /**
     * @param MetadataReader $metadataReader imagemeta reader for parsing EXIF/QuickTime/XMP metadata
     */
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
    #[Override]
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

        $isQuickTimeContainer = $metadata->quickTime instanceof QuickTimeMeta;

        [$captureDateTime, $isFallback, $isAmbiguousTimezone] = $this->extractCaptureDateTimeWithFallbackFlag($structured, $isQuickTimeContainer);

        if (!($captureDateTime instanceof DateTimeInterface) && ($livePhotoId === null)) {
            return null;
        }

        return new TemporalMetadata($captureDateTime, $livePhotoId, $isFallback, $isAmbiguousTimezone);
    }

    /**
     * Returns the capture timestamp, whether it came from the fallback DateTime
     * tag (0x0132), and whether the timezone is ambiguous.
     *
     * The imagemeta library's `temporal->original` uses `dateTimeOriginalBestEffort()`
     * which itself falls back through 0x9003 → 0x9004 → 0x0132, so we cannot
     * use it to distinguish the source. Instead, we check `temporal->create`
     * (pure 0x9004) and compare: if `temporal->original` is set but `temporal->create`
     * is null and both resolve to the same timestamp, the date came from the
     * generic capture fallback (0x0132), not from a dedicated date tag.
     *
     * @return array{DateTimeInterface|null, bool, bool} Tuple of [captureDateTime, isFallback, isAmbiguousTimezone]
     */
    private function extractCaptureDateTimeWithFallbackFlag(StructuredMetadata $structured, bool $isQuickTimeContainer): array
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

        // Fallback detection: if there's no dedicated original date (0x9003), no
        // dedicated create date (0x9004), and the resolved timestamp equals the
        // modify date (0x0132), the date is from the generic DateTime tag — not
        // from DateTimeOriginal or CreateDate.
        $isFallback = !($original instanceof DateTimeInterface)
            && !($create instanceof DateTimeInterface)
            && ($modify instanceof DateTimeInterface)
            && ($dateTime->getTimestamp() === $modify->getTimestamp());

        // QuickTime containers without explicit timezone info (no Keys CreationDate
        // with offset, no OffsetTime* tags) are flagged as ambiguous. We cannot
        // determine if the timestamp is UTC (modern cameras) or local time (old cameras).
        // The user sees [W] and can use --timezone for manual correction.
        $isAmbiguousTimezone = $isQuickTimeContainer
            && !($temporal->tz instanceof DateTimeZone)
            && ($temporal->offsetTimeOriginal === null)
            && ($temporal->offsetTimeDigitized === null)
            && ($temporal->offsetTime === null);

        return [$dateTime, $isFallback, $isAmbiguousTimezone];
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
