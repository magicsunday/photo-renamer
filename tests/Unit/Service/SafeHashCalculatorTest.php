<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(SafeHashCalculator::class)]
/**
 * Verifies the SafeHashCalculator, which wraps PHP's hash_file() with an error
 * handler that converts warnings into typed HashComputationException instances.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class SafeHashCalculatorTest extends TestCase
{
    /**
     * Verifies that hashing an existing readable file produces a non-empty
     * hexadecimal string using the xxh128 algorithm.
     *
     * This is the happy path: a regular file with readable permissions should
     * always produce a valid hash without exceptions.
     */
    #[Test]
    public function hashFileReturnsNonEmptyStringForReadableFile(): void
    {
        $calculator = new SafeHashCalculator();

        $tempFile = tempnam(sys_get_temp_dir(), 'hash-test-');
        self::assertIsString($tempFile);

        try {
            file_put_contents($tempFile, 'test content for hashing');

            $hash = $calculator->hashFile(new SplFileInfo($tempFile), 'xxh128');

            self::assertNotEmpty($hash);
            self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $hash);
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * Verifies that hashing the same file twice produces identical results,
     * confirming deterministic behaviour of the hash calculator.
     */
    #[Test]
    public function hashFileReturnsDeterministicResult(): void
    {
        $calculator = new SafeHashCalculator();

        $tempFile = tempnam(sys_get_temp_dir(), 'hash-det-');
        self::assertIsString($tempFile);

        try {
            file_put_contents($tempFile, 'deterministic content');

            $hash1 = $calculator->hashFile(new SplFileInfo($tempFile), 'xxh128');
            $hash2 = $calculator->hashFile(new SplFileInfo($tempFile), 'xxh128');

            self::assertSame($hash1, $hash2);
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * Verifies that hashing two files with different content produces different
     * hash values, confirming the calculator distinguishes file contents.
     */
    #[Test]
    public function hashFileReturnsDifferentHashesForDifferentContent(): void
    {
        $calculator = new SafeHashCalculator();

        $tempFileA = tempnam(sys_get_temp_dir(), 'hash-a-');
        $tempFileB = tempnam(sys_get_temp_dir(), 'hash-b-');
        self::assertIsString($tempFileA);
        self::assertIsString($tempFileB);

        try {
            file_put_contents($tempFileA, 'content alpha');
            file_put_contents($tempFileB, 'content beta');

            $hashA = $calculator->hashFile(new SplFileInfo($tempFileA), 'xxh128');
            $hashB = $calculator->hashFile(new SplFileInfo($tempFileB), 'xxh128');

            self::assertNotSame($hashA, $hashB);
        } finally {
            unlink($tempFileA);
            unlink($tempFileB);
        }
    }

    /**
     * Verifies that attempting to hash a non-existent file throws a
     * HashComputationException with a message identifying the failed file
     * and algorithm.
     *
     * This is the error path: file_get_contents() emits a warning when the
     * file does not exist, which SafeHashCalculator must convert into a
     * typed domain exception.
     */
    #[Test]
    public function hashFileThrowsHashComputationExceptionForNonExistentFile(): void
    {
        $calculator = new SafeHashCalculator();

        $nonExistentFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'non-existent-' . uniqid('', true) . '.jpg';

        $this->expectException(HashComputationException::class);
        $this->expectExceptionMessage('Failed to compute xxh128 hash');

        $calculator->hashFile(new SplFileInfo($nonExistentFile), 'xxh128');
    }
}
