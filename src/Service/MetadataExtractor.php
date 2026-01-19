<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use DateTimeImmutable;
use DateTimeInterface;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Service\Dto\TemporalMetadata;
use SplFileInfo;
use Stringable;
use Throwable;

use function is_array;
use function is_object;
use function is_string;
use function method_exists;
use function property_exists;
use function sprintf;
use function trim;
use function ucfirst;

final class MetadataExtractor
{
    public function __construct(private readonly MetadataReader $metadataReader)
    {
    }

    public function extractTemporalMetadata(SplFileInfo $file): ?TemporalMetadata
    {
        try {
            $metadata = $this->metadataReader->read($file->getPathname());
        } catch (Throwable $exception) {
            throw new ExifMetadataReadException(
                sprintf('Unable to read image metadata from "%s": %s', $file->getPathname(), $exception->getMessage()),
                previous: $exception,
            );
        }

        if ($metadata === null || !method_exists($metadata, 'structured')) {
            return null;
        }

        $structured = $metadata->structured();
        $temporal = $this->extractTemporalPayload($structured);

        if ($temporal === null) {
            return null;
        }

        $captureDateTime = $this->normaliseCaptureDateTime($this->readTemporalField($temporal, 'captureDateTime'));
        $livePhotoId = $this->normaliseLivePhotoId($this->readTemporalField($temporal, 'livePhotoId'));

        if ($captureDateTime === null && $livePhotoId === null) {
            return null;
        }

        return new TemporalMetadata($captureDateTime, $livePhotoId);
    }

    private function extractTemporalPayload(mixed $structured): object|array|null
    {
        if (is_array($structured)) {
            $temporal = $structured['temporal'] ?? null;

            return is_array($temporal) || is_object($temporal) ? $temporal : null;
        }

        if (!is_object($structured)) {
            return null;
        }

        if (property_exists($structured, 'temporal')) {
            $temporal = $structured->temporal;

            return is_array($temporal) || is_object($temporal) ? $temporal : null;
        }

        if (method_exists($structured, 'temporal')) {
            $temporal = $structured->temporal();

            return is_array($temporal) || is_object($temporal) ? $temporal : null;
        }

        return null;
    }

    private function readTemporalField(object|array $temporal, string $field): mixed
    {
        if (is_array($temporal)) {
            return $temporal[$field] ?? null;
        }

        if (property_exists($temporal, $field)) {
            return $temporal->{$field};
        }

        $getter = 'get' . ucfirst($field);

        if (method_exists($temporal, $getter)) {
            return $temporal->{$getter}();
        }

        if (method_exists($temporal, $field)) {
            return $temporal->{$field}();
        }

        return null;
    }

    private function normaliseCaptureDateTime(mixed $value): ?DateTimeInterface
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

    private function normaliseLivePhotoId(mixed $value): ?string
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
