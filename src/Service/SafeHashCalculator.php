<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Exception\HashComputationException;
use SplFileInfo;

use function hash_file;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;

class SafeHashCalculator
{
    /**
     * Calculates a hash for the given file while converting PHP warnings into domain exceptions.
     *
     * @param SplFileInfo $file      file whose contents should be hashed
     * @param string      $algorithm hash algorithm identifier supported by {@see hash_file()}
     *
     * @return string hexadecimal hash produced by the selected algorithm
     */
    public function hashFile(SplFileInfo $file, string $algorithm): string
    {
        $path = $file->getPathname();

        set_error_handler(
            static function (int $severity, string $message) use ($path, $algorithm): never {
                throw new HashComputationException(
                    sprintf('Failed to compute %s hash for "%s": %s', $algorithm, $path, $message),
                );
            },
        );

        try {
            $hash = hash_file($algorithm, $path);
        } finally {
            restore_error_handler();
        }

        if ($hash === false) {
            throw new HashComputationException(
                sprintf('Failed to compute %s hash for "%s": Unknown error.', $algorithm, $path),
            );
        }

        return $hash;
    }
}
