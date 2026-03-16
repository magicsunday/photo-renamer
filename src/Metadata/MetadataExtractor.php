<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Metadata;

use DateTimeImmutable;
use DateTimeInterface;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use SplFileInfo;
use Stringable;
use Throwable;

use function is_string;
use function sprintf;
use function trim;

/**
 * Reads raw EXIF/XMP/QuickTime metadata via the imagemeta MetadataReader library
 * and converts it into a TemporalMetadata value object containing capture date
 * and Live Photo content identifier. Navigates the structured metadata tree
 * produced by imagemeta with a fallback chain for different tag locations.
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
     * Extracts temporal metadata and Live Photo content identifier from the file.
     * Returns null when neither a capture date nor a content identifier is present.
     *
     * @param SplFileInfo $file File to read metadata from
     *
     * @return TemporalMetadata|null Extracted metadata, or null when the file lacks relevant fields
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

        $structured = $metadata->structured();

        $captureDateTime = $this->extractCaptureDateTime($structured);
        $livePhotoId     = $this->extractContentIdentifier($structured);

        if (!$captureDateTime instanceof DateTimeInterface && $livePhotoId === null) {
            return null;
        }

        return new TemporalMetadata($captureDateTime, $livePhotoId);
    }

    /**
     * Extracts the capture date/time from the structured metadata.
     *
     * Reads from locationTime.temporal.original, falling back to
     * locationTime.temporal.create, then locationTime.capture.dateTime.
     */
    private function extractCaptureDateTime(mixed $structured): ?DateTimeInterface
    {
        $locationTime = $this->readProperty($structured, 'locationTime');

        if ($locationTime === null) {
            return null;
        }

        $temporal = $this->readProperty($locationTime, 'temporal');

        if ($temporal !== null) {
            $original = $this->readProperty($temporal, 'original');

            if ($original instanceof DateTimeInterface) {
                return $original;
            }

            $create = $this->readProperty($temporal, 'create');

            if ($create instanceof DateTimeInterface) {
                return $create;
            }
        }

        $capture  = $this->readProperty($locationTime, 'capture');
        $dateTime = $capture !== null ? $this->readProperty($capture, 'dateTime') : null;

        if ($dateTime instanceof DateTimeInterface) {
            return $dateTime;
        }

        return $this->normaliseStringDateTime($dateTime);
    }

    /**
     * Extracts the Apple Live Photo content identifier from the structured metadata.
     *
     * Reads from makerNotesApple.identity.contentIdentifier.
     */
    private function extractContentIdentifier(mixed $structured): ?string
    {
        $makerNotesApple = $this->readProperty($structured, 'makerNotesApple');

        if ($makerNotesApple === null) {
            return null;
        }

        $identity = $this->readProperty($makerNotesApple, 'identity');

        if ($identity === null) {
            return null;
        }

        return $this->normaliseStringValue($this->readProperty($identity, 'contentIdentifier'));
    }

    /**
     * Reads a named property from an arbitrary object via direct property access.
     * Returns null for non-objects or when the property does not exist.
     *
     * @param mixed  $object Object to read from
     * @param string $name   Property name to access
     *
     * @return mixed Property value, or null when inaccessible
     */
    private function readProperty(mixed $object, string $name): mixed
    {
        if (!is_object($object)) {
            return null;
        }

        if (property_exists($object, $name)) {
            /** @phpstan-ignore property.dynamicName */
            return $object->{$name};
        }

        return null;
    }

    /**
     * Attempts to parse a mixed value as a DateTimeInterface. Accepts existing
     * DateTimeInterface instances directly, or parses non-empty strings via
     * DateTimeImmutable. Returns null for unparseable or empty values.
     *
     * @param mixed $value Raw value from the metadata tree
     *
     * @return DateTimeInterface|null Parsed date/time, or null on failure
     */
    private function normaliseStringDateTime(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Converts a mixed value to a trimmed non-empty string, or null.
     * Accepts strings, integers and Stringable objects.
     *
     * @param mixed $value Raw value from the metadata tree
     *
     * @return string|null Trimmed string, or null when empty or unsupported type
     */
    private function normaliseStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Stringable || is_string($value) || is_int($value)) {
            $stringValue = trim((string) $value);

            return $stringValue !== '' ? $stringValue : null;
        }

        return null;
    }
}
