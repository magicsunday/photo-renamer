<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Exception\FileReadException;
use SplFileInfo;

use function file_get_contents;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;

/**
 * Wraps PHP's file_get_contents() with an error handler that converts warnings
 * into typed FileReadException instances. Provides safe file reading without
 * relying on the caller to suppress or handle PHP warnings.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class SafeFileReader
{
    /**
     * Reads the contents of a file while translating PHP warnings into domain exceptions.
     *
     * @param SplFileInfo $file file to be read
     *
     * @return string file contents as a string
     */
    public function read(SplFileInfo $file): string
    {
        $path = $file->getPathname();

        set_error_handler(
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
