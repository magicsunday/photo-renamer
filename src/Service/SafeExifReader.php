<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Service\Dto\ExifMetadataResult;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ExifRawMetadata;
use SplFileInfo;
use ValueError;

use function exif_read_data;
use function file_get_contents;
use function is_array;
use function is_string;
use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_contains;
use function strtolower;
use function trim;

/**
 * @deprecated Use MetadataExtractor (ImageMeta) for temporal metadata instead.
 */
class SafeExifReader
{
    private const XMP_SCAN_LENGTH = 524_288;

    /**
     * Reads EXIF metadata and converts PHP warnings into domain exceptions.
     *
     * @param SplFileInfo $file File whose EXIF data should be read.
     *
     * @return ExifMetadataResult Returns an empty result when no EXIF metadata is present.
     */
    public function read(SplFileInfo $file): ExifMetadataResult
    {
        $filename = $file->getPathname();

        $previousHandler = set_error_handler(
            static function (int $severity, string $message) use ($filename): never {
                throw new ExifMetadataReadException(
                    sprintf('Failed to read EXIF metadata from "%s": %s', $filename, $message),
                );
            },
        );

        try {
            $data = exif_read_data($filename);
        } catch (ValueError $error) {
            throw new ExifMetadataReadException(
                sprintf('Failed to read EXIF metadata from "%s": %s', $filename, $error->getMessage()),
                previous: $error,
            );
        } catch (ExifMetadataReadException $error) {
            if ($this->isUnsupportedFormatMessage($error->getMessage())) {
                return ExifMetadataResult::withoutMetadata();
            }

            throw $error;
        } finally {
            restore_error_handler();
        }

        if ($data === false) {
            return ExifMetadataResult::withoutMetadata();
        }

        if (!is_array($data)) {
            throw new ExifMetadataReadException(
                sprintf('Unexpected EXIF data format returned for "%s".', $filename),
            );
        }

        $data = $this->appendXmpContentIdentifier($data, $filename);

        return ExifMetadataResult::withMetadata(ExifRawMetadata::fromArray($data));
    }

    private function isUnsupportedFormatMessage(string $message): bool
    {
        return str_contains(strtolower($message), 'not supported');
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    private function appendXmpContentIdentifier(array $data, string $filename): array
    {
        $existingValue = $this->resolveExistingContentIdentifier($data);

        if (is_string($existingValue) && $existingValue !== '') {
            if (!isset($data['XMP']) || !is_array($data['XMP'])) {
                $data['XMP'] = ['ContentIdentifier' => $existingValue];
            } else {
                $data['XMP']['ContentIdentifier'] ??= $existingValue;
            }

            return $data;
        }

        $identifier = $this->extractXmpContentIdentifier($filename);

        if ($identifier === null || $identifier === '') {
            return $data;
        }

        if (!isset($data['XMP']) || !is_array($data['XMP'])) {
            $data['XMP'] = [];
        }

        $data['XMP']['xmp:ContentIdentifier'] = $identifier;
        $data['XMP']['ContentIdentifier'] ??= $identifier;
        $data['ContentIdentifier'] ??= $identifier;

        return $data;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function resolveExistingContentIdentifier(array $data): ?string
    {
        $topLevel = $data['ContentIdentifier'] ?? null;

        if (is_string($topLevel) && $topLevel !== '') {
            return $topLevel;
        }

        $xmpSection = $data['XMP'] ?? null;

        if (is_array($xmpSection)) {
            foreach (['xmp:ContentIdentifier', 'ContentIdentifier'] as $key) {
                $candidate = $xmpSection[$key] ?? null;

                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function extractXmpContentIdentifier(string $filename): ?string
    {
        $buffer = @file_get_contents($filename, false, null, 0, self::XMP_SCAN_LENGTH);

        if ($buffer === false) {
            return null;
        }

        if (preg_match('/xmp:ContentIdentifier="([^"]+)"/i', $buffer, $match) === 1) {
            $value = trim($match[1]);

            if ($value !== '') {
                return $value;
            }
        }

        if (preg_match("/xmp:ContentIdentifier='([^']+)'/i", $buffer, $match) === 1) {
            $value = trim($match[1]);

            if ($value !== '') {
                return $value;
            }
        }

        if (preg_match('/<xmp:ContentIdentifier>([^<]+)<\\/xmp:ContentIdentifier>/i', $buffer, $match) === 1) {
            $value = trim($match[1]);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
