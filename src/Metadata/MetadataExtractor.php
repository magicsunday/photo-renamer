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

        $structured      = $metadata->structured();
        $captureDateTime = $this->extractCaptureDateTime($structured);
        $livePhotoId     = $this->extractContentIdentifier($structured);

        if (!$captureDateTime instanceof DateTimeInterface && $livePhotoId === null) {
            return null;
        }

        return new TemporalMetadata($captureDateTime, $livePhotoId);
    }

    /**
     * Returns the original capture timestamp, falling back to the creation
     * timestamp and then the generic capture dateTime. All three are nullable
     * {@see \DateTimeImmutable} properties on the imagemeta value objects.
     */
    private function extractCaptureDateTime(StructuredMetadata $structured): ?DateTimeInterface
    {
        return $structured->locationTime->temporal->original
            ?? $structured->locationTime->temporal->create
            ?? $structured->locationTime->capture->dateTime;
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
