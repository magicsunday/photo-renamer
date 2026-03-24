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

/**
 * Contract for computing file content hashes with error handling that converts
 * PHP warnings into typed exceptions.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface SafeHashCalculatorInterface
{
    /**
     * Calculates a hash for the given file while converting PHP warnings into domain exceptions.
     *
     * @param SplFileInfo $file      file whose contents should be hashed
     * @param string      $algorithm hash algorithm identifier supported by {@see hash_file()}
     *
     * @return string hexadecimal hash produced by the selected algorithm
     *
     * @throws HashComputationException when the file cannot be read or hashing fails
     */
    public function hashFile(SplFileInfo $file, string $algorithm): string;

    /**
     * Releases all cached hash results to free memory.
     */
    public function clearCache(): void;
}
