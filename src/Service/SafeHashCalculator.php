<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
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
     * @param SplFileInfo $file      File whose contents should be hashed.
     * @param string      $algorithm Hash algorithm identifier supported by {@see hash_file()}.
     *
     * @return string Hexadecimal hash produced by the selected algorithm.
     */
    public function hashFile(SplFileInfo $file, string $algorithm): string
    {
        $path = $file->getPathname();

        $previousHandler = set_error_handler(
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
