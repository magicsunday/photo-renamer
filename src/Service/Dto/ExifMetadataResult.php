<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dto;

use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ExifRawMetadata;

/**
 * Immutable value object describing the availability of extracted EXIF metadata.
 */
final class ExifMetadataResult
{
    /**
     * Initializes the result with optional EXIF metadata.
     *
     * @param ExifRawMetadata|null $metadata Optional metadata returned by the EXIF extractor.
     */
    private function __construct(private readonly ?ExifRawMetadata $metadata)
    {
    }

    /**
     * Creates a result without EXIF metadata.
     *
     * @return self Result indicating that no metadata was found.
     */
    public static function withoutMetadata(): self
    {
        return new self(null);
    }

    /**
     * Creates a result that contains EXIF metadata.
     *
     * @param ExifRawMetadata $metadata Raw metadata obtained from the EXIF extractor.
     *
     * @return self Result encapsulating the provided metadata.
     */
    public static function withMetadata(ExifRawMetadata $metadata): self
    {
        return new self($metadata);
    }

    /**
     * Determines whether the result contains EXIF metadata.
     *
     * @return bool True when metadata is available, false otherwise.
     */
    public function hasMetadata(): bool
    {
        return $this->metadata !== null;
    }

    /**
     * Retrieves the contained EXIF metadata if available.
     *
     * @return ExifRawMetadata|null The stored metadata instance or null when absent.
     */
    public function metadata(): ?ExifRawMetadata
    {
        return $this->metadata;
    }
}
