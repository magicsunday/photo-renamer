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
use function is_array;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_contains;
use function strtolower;

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

        return ExifMetadataResult::withMetadata(ExifRawMetadata::fromArray($data));
    }

    private function isUnsupportedFormatMessage(string $message): bool
    {
        return str_contains(strtolower($message), 'not supported');
    }
}
