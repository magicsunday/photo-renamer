<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\DuplicateIdentifier;

use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\ContentHashStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Unit tests for ContentHashStrategy class.
 *
 * This test class verifies the behavior of the ContentHashStrategy, which generates
 * unique identifiers for files based on their content hash. The strategy uses the
 * xxHash128 algorithm to create a fast, high-quality hash of file contents.
 *
 * The ContentHashStrategy is typically used to identify duplicate files regardless
 * of their names or locations - files with identical content will produce the same
 * hash identifier.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(ContentHashStrategy::class)]
class ContentHashStrategyTest extends TestCase
{
    /**
     * Tests that generateIdentifier returns a valid hash for an existing file.
     *
     * This test verifies that when given a valid source file with content,
     * the strategy correctly:
     * - Reads the file content
     * - Generates an xxHash128 hash
     * - Returns the hash as a string identifier
     *
     * The test creates a temporary file with known content, generates its hash
     * using the strategy, and compares it with a directly computed xxHash128 hash
     * to ensure correctness.
     */
    #[Test]
    public function generateIdentifierReturnsHash(): void
    {
        // Create stub objects for source and target files
        $sourceFile = self::createStub(SplFileInfo::class);
        $sourceFile->method('getPathname')->willReturn(__DIR__ . '/testFile.txt');

        $targetFile = self::createStub(SplFileInfo::class);

        // Create a temporary test file with known content
        file_put_contents(
            __DIR__ . '/testFile.txt',
            'Test content for hashing'
        );

        // Initialize strategy and generate identifier
        $strategy = new ContentHashStrategy(new SafeHashCalculator());
        $result   = $strategy->generateIdentifier(
            $sourceFile,
            $targetFile
        );

        // Calculate expected hash directly for comparison
        $expectedHash = hash_file(
            'xxh128',
            __DIR__ . '/testFile.txt'
        );

        // Verify the strategy produces the correct hash
        self::assertEquals(
            $expectedHash,
            $result
        );

        // Clean up temporary file
        unlink(__DIR__ . '/testFile.txt');
    }

    /**
     * Tests that generateIdentifier returns false for non-existent files.
     *
     * This test ensures proper error handling when the strategy attempts to
     * hash a file that doesn't exist. The expected behavior is to return false
     * rather than throwing an exception, allowing calling code to handle the
     * missing file gracefully.
     *
     * This scenario might occur when:
     * - A file is deleted between scanning and processing
     * - A broken symbolic link is encountered
     * - File permissions prevent reading
     */
    #[Test]
    public function generateIdentifierReturnsFalseForNonExistentFile(): void
    {
        // Create stub for a non-existent file
        $sourceFile = self::createStub(SplFileInfo::class);
        $sourceFile->method('getPathname')->willReturn(__DIR__ . '/nonExistentFile.txt');

        $targetFile = self::createStub(SplFileInfo::class);

        // Attempt to generate an identifier for a non-existent file
        $strategy = new ContentHashStrategy(new SafeHashCalculator());

        $this->expectException(HashComputationException::class);
        $this->expectExceptionMessageMatches('/Failed to compute xxh128 hash/');

        $strategy->generateIdentifier(
            $sourceFile,
            $targetFile
        );
    }

    /**
     * Tests that generateIdentifier correctly handles empty files.
     *
     * This test verifies that the strategy can process files with no content
     * (0 bytes). Empty files should still produce a valid hash - the xxHash128
     * algorithm generates a consistent hash even for empty input.
     *
     * This is important for:
     * - Detecting duplicate empty files (e.g., placeholder files)
     * - Ensuring the strategy doesn't fail on edge cases
     * - Maintaining consistency in hash generation
     */
    #[Test]
    public function generateIdentifierWithEmptyFile(): void
    {
        // Create stubs for source and target files
        $sourceFile = self::createStub(SplFileInfo::class);
        $sourceFile->method('getPathname')->willReturn(__DIR__ . '/emptyFile.txt');

        $targetFile = self::createStub(SplFileInfo::class);

        // Create an empty temporary file
        file_put_contents(
            __DIR__ . '/emptyFile.txt',
            ''
        );

        // Generate identifier for an empty file
        $strategy = new ContentHashStrategy(new SafeHashCalculator());
        $result   = $strategy->generateIdentifier(
            $sourceFile,
            $targetFile
        );

        // Calculate expected hash for empty content
        $expectedHash = hash_file(
            'xxh128',
            __DIR__ . '/emptyFile.txt'
        );

        // Verify empty files produce valid, consistent hashes
        self::assertEquals(
            $expectedHash,
            $result
        );

        // Clean up temporary file
        unlink(__DIR__ . '/emptyFile.txt');
    }
}
