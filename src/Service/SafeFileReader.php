<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Exception\FileReadException;
use SplFileInfo;

use function file_get_contents;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;

class SafeFileReader
{
    /**
     * Reads the contents of a file while translating PHP warnings into domain exceptions.
     *
     * @param SplFileInfo $file File to be read.
     *
     * @return string File contents as a string.
     */
    public function read(SplFileInfo $file): string
    {
        $path = $file->getPathname();

        $previousHandler = set_error_handler(
            static function (int $severity, string $message) use ($path): never {
                throw new FileReadException(
                    sprintf('Failed to read file "%s": %s', $path, $message),
                );
            },
        );

        try {
            $contents = file_get_contents($path);
        } finally {
            restore_error_handler();
        }

        if ($contents === false) {
            throw new FileReadException(
                sprintf('Failed to read file "%s": Unknown error.', $path),
            );
        }

        return $contents;
    }
}
