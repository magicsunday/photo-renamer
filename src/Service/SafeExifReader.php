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

use const E_WARNING;

class SafeExifReader
{
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
            function (int $severity, string $message) use ($filename): bool {
                if ($severity === E_WARNING && $this->isRecoverableWarningMessage($message)) {
                    return true;
                }

                throw new ExifMetadataReadException(
                    sprintf('Failed to read EXIF metadata from "%s": %s', $filename, $message),
                );
            },
        );

        try {
            $data = exif_read_data($filename, null, true);
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

        $contentIdentifier = $this->extractXmpContentIdentifier($filename, $data);

        if ($contentIdentifier !== null) {
            if (!isset($data['XMP']) || !is_array($data['XMP'])) {
                $data['XMP'] = [];
            }

            $data['XMP']['xmp:ContentIdentifier'] = $contentIdentifier;
        }

        return ExifMetadataResult::withMetadata(ExifRawMetadata::fromArray($data));
    }

    private function isUnsupportedFormatMessage(string $message): bool
    {
        return str_contains(strtolower($message), 'not supported');
    }

    private function isRecoverableWarningMessage(string $message): bool
    {
        return str_contains(strtolower($message), 'incorrect app1 exif identifier code');
    }

    /**
     * @param array<int|string, mixed> $metadata
     */
    private function extractXmpContentIdentifier(string $filename, array $metadata): ?string
    {
        $xmpSection = $metadata['XMP'] ?? null;

        if (is_array($xmpSection)) {
            $value = $xmpSection['xmp:ContentIdentifier'] ?? null;

            if (is_string($value) && $value !== '') {
                return trim($value);
            }
        } elseif (is_string($xmpSection)) {
            $identifier = $this->extractXmpContentIdentifierFromPayload($xmpSection);

            if ($identifier !== null) {
                return $identifier;
            }
        }

        $app1Payload = $metadata['APP1'] ?? null;

        if (is_string($app1Payload)) {
            $identifier = $this->extractXmpContentIdentifierFromPayload($app1Payload);

            if ($identifier !== null) {
                return $identifier;
            }
        }

        $contents = @file_get_contents($filename);

        if ($contents === false) {
            return null;
        }

        return $this->extractXmpContentIdentifierFromPayload($contents);
    }

    private function extractXmpContentIdentifierFromPayload(string $payload): ?string
    {
        if (preg_match('/xmp:ContentIdentifier="([^"]+)"/i', $payload, $matches) === 1) {
            return trim($matches[1]);
        }

        if (preg_match('/<xmp:ContentIdentifier>([^<]+)<\\/xmp:ContentIdentifier>/i', $payload, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }
}
