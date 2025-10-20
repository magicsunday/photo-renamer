<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use SplFileInfo;
use ValueError;

use function exif_read_data;
use function is_array;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;

class SafeExifReader
{
    /**
     * Reads EXIF metadata and converts PHP warnings into domain exceptions.
     *
     * @return array<string, mixed>|null Returns null when no EXIF metadata is present.
     */
    public function read(SplFileInfo $file): ?array
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
        } finally {
            restore_error_handler();
        }

        if ($data === false) {
            return null;
        }

        if (!is_array($data)) {
            throw new ExifMetadataReadException(
                sprintf('Unexpected EXIF data format returned for "%s".', $filename),
            );
        }

        return $data;
    }
}
