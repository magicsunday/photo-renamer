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
 * Contract for computing file content hashes with robust error handling.
 *
 * This interface defines the behavior for components that calculate hashes
 * (e.g., MD5, SHA-1, dHash) of files. It enforces the conversion of standard
 * PHP warnings (e.g., from `hash_file()`) into typed domain exceptions,
 * ensuring that the calling pipeline can handle failures gracefully and
 * predictably. Implementations may also provide caching to avoid redundant
 * I/O operations for the same file.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface SafeHashCalculatorInterface
{
    /**
     * Calculates a hash for the given file using the specified algorithm.
     *
     * This method wraps the underlying hashing function to provide a safer API
     * that transforms file-system related issues or hashing failures into
     * a {@see HashComputationException}.
     *
     * @param SplFileInfo $file      The file whose contents should be hashed.
     * @param string      $algorithm The hash algorithm identifier (e.g., 'md5', 'sha256')
     *                               supported by the underlying engine (usually {@see hash_file()}).
     *
     * @return string The hexadecimal hash produced by the selected algorithm.
     *
     * @throws HashComputationException Thrown when the file cannot be accessed, read,
     *                                  or when the hashing algorithm fails.
     */
    public function hashFile(SplFileInfo $file, string $algorithm): string;

    /**
     * Releases all cached hash results and clears internal state to free memory.
     *
     * This is particularly useful in long-running CLI processes or when
     * processing very large photo collections where memory usage needs to
     * be kept under control.
     */
    public function clearCache(): void;
}
