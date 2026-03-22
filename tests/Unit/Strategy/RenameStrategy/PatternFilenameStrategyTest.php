<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\RenameStrategy;

use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Strategy\RenameStrategy\PatternFilenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function sprintf;

/**
 * Unit tests for PatternFilenameStrategy class.
 *
 * This test class validates the behavior of PatternFilenameStrategy, which provides
 * powerful regex-based filename transformation capabilities. The strategy allows users
 * to define custom patterns and replacements for renaming files based on their content.
 *
 * Key features tested:
 * - Regular expression pattern matching and replacement
 * - Support for capture groups and backreferences ($1, $2, etc.)
 * - Case-sensitive and case-insensitive matching
 * - Unicode character support
 * - Automatic removal of duplicate identifiers before pattern application
 * - Proper error handling for invalid regex patterns
 *
 * Common use cases:
 * - Standardizing file naming conventions (e.g., IMG_XXXX to photo-XXXX)
 * - Removing or replacing date patterns in filenames
 * - Batch renaming with complex transformations
 * - Cleaning up camera-generated filenames
 * - Version number updates in documentation files
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(PatternFilenameStrategy::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(SafeRegex::class)]
final class PatternFilenameStrategyTest extends TestCase
{
    /**
     * Tests that the strategy correctly applies pattern replacements.
     *
     * This parameterized test validates the core functionality of the PatternFilenameStrategy
     * by testing various regex patterns and replacement scenarios. Each test case demonstrates
     * a different aspect of the pattern matching and replacement capability.
     *
     * The test covers:
     * - Simple string replacements
     * - Complex regex patterns with capture groups
     * - Case-insensitive matching with the 'i' flag
     * - Unicode character support
     * - Word boundary matching
     * - Multiple occurrence replacements
     * - Edge cases like files without extensions
     *
     * Important behaviors verified:
     * - Duplicate identifiers (-duplicate-XXX) are removed before pattern application
     * - File extensions are preserved during transformation
     * - Full paths are handled correctly (only filename is returned)
     * - Patterns that don't match leave the filename unchanged
     *
     * @param string $originalFilename The input filename to be transformed
     * @param string $pattern          The regular expression pattern to match
     * @param string $replacement      The replacement string (may include backreferences)
     * @param string $expected         The expected filename after transformation
     * @param string $description      Human-readable description of the test case
     */
    #[Test]
    #[DataProvider('patternReplacementProvider')]
    public function generateFilenameAppliesPatternReplacement(
        string $originalFilename,
        string $pattern,
        string $replacement,
        string $expected,
        string $description,
    ): void {
        // Create a file info object from the test filename
        $file = new SplFileInfo($originalFilename);

        // Initialize the strategy with the pattern and replacement
        $strategy = new PatternFilenameStrategy($pattern, $replacement, new SafeRegex());

        // Assert that the generated filename matches expectations
        self::assertSame(
            $expected,
            $strategy->generateFilename($file),
            sprintf('Failed for case: %s', $description)
        );
    }

    /**
     * Tests that invalid regex patterns throw appropriate exceptions.
     *
     * This test ensures robust error handling when users provide malformed or
     * invalid regular expression patterns. The strategy should detect regex errors
     * during pattern compilation and throw a TargetFilenameException with a
     * descriptive error message.
     *
     * Types of invalid patterns tested:
     * - Missing or unclosed delimiters
     * - Unmatched parentheses or brackets
     * - Invalid quantifiers
     * - Syntax errors in regex constructs
     * - Patterns without proper delimiters
     *
     * This is important for:
     * - Providing clear feedback to users about pattern errors
     * - Preventing silent failures or unexpected behavior
     * - Maintaining application stability with invalid input
     *
     * @param string $filename    The test filename (content doesn't matter for these tests)
     * @param string $pattern     The invalid regex pattern to test
     * @param string $replacement The replacement string (not used but required by strategy)
     * @param string $description Human-readable description of the invalid pattern type
     */
    #[Test]
    #[DataProvider('invalidPatternProvider')]
    public function generateFilenameThrowsExceptionOnInvalidPattern(
        string $filename,
        string $pattern,
        string $replacement,
        string $description,
    ): void {
        // Create a file info object for testing
        $file = new SplFileInfo($filename);

        // Initialize the strategy with the invalid pattern
        $strategy = new PatternFilenameStrategy($pattern, $replacement, new SafeRegex());

        // Expect a TargetFilenameException to be thrown
        $this->expectException(TargetFilenameException::class);
        $this->expectExceptionMessageMatches('/Regular expression error:/');

        // Attempt to generate a filename, which should trigger the exception
        $strategy->generateFilename($file);
    }

    /**
     * Provides test cases for pattern replacement functionality.
     *
     * This comprehensive data provider covers a wide range of regex pattern scenarios,
     * from simple string replacements to complex transformations with capture groups.
     * Each test case is designed to validate a specific aspect of the pattern matching
     * and replacement functionality.
     *
     * Test categories include:
     * - Basic pattern matching (digits, words, specific strings)
     * - Capture groups and backreferences ($1, $2, etc.)
     * - Case-insensitive matching with flags
     * - Unicode and special character handling
     * - Word boundary matching (\b)
     * - Edge cases (no match, empty replacement, files without extensions)
     * - Integration with duplicate identifier removal
     *
     * @return array<string, array{originalFilename: string, pattern: string, replacement: string, expected: string, description: string}>
     */
    public static function patternReplacementProvider(): array
    {
        return [
            'replaces digits in filename' => [
                'originalFilename' => 'example123.txt',
                'pattern'          => '/\d+/',
                'replacement'      => '456',
                'expected'         => 'example456.txt',
                'description'      => 'Should replace all digits with the replacement string',
            ],
            'replaces specific word' => [
                'originalFilename' => 'test-file.jpg',
                'pattern'          => '/test/',
                'replacement'      => 'production',
                'expected'         => 'production-file.jpg',
                'description'      => 'Should replace specific word in filename',
            ],
            'replaces with empty string' => [
                'originalFilename' => 'file-2024-01-01.txt',
                'pattern'          => '/-\d{4}-\d{2}-\d{2}/',
                'replacement'      => '',
                'expected'         => 'file.txt',
                'description'      => 'Should remove matched pattern when replacement is empty',
            ],
            'replaces multiple occurrences' => [
                'originalFilename' => 'test-test-test.txt',
                'pattern'          => '/test/',
                'replacement'      => 'demo',
                'expected'         => 'demo-demo-demo.txt',
                'description'      => 'Should replace all occurrences of the pattern',
            ],
            'handles case-insensitive replacement' => [
                'originalFilename' => 'TEST-File.TXT',
                'pattern'          => '/test/i',
                'replacement'      => 'new',
                'expected'         => 'new-File.txt',
                'description'      => 'Should handle case-insensitive pattern matching',
            ],
            'preserves file extension' => [
                'originalFilename' => 'document.pdf',
                'pattern'          => '/document/',
                'replacement'      => 'report',
                'expected'         => 'report.pdf',
                'description'      => 'Should preserve file extension after replacement',
            ],
            'handles files without extension' => [
                'originalFilename' => 'README',
                'pattern'          => '/README/',
                'replacement'      => 'CHANGELOG',
                'expected'         => 'CHANGELOG',
                'description'      => 'Should handle files without extension',
            ],
            'applies complex regex pattern' => [
                'originalFilename' => 'IMG_20240101_123456.jpg',
                'pattern'          => '/IMG_(\d{8})_(\d{6})/',
                'replacement'      => 'photo-$1-$2',
                'expected'         => 'photo-20240101-123456.jpg',
                'description'      => 'Should handle complex regex with capture groups',
            ],
            'handles special characters in replacement' => [
                'originalFilename' => 'file.txt',
                'pattern'          => '/file/',
                'replacement'      => 'doc$1ument',  // $1 is empty, results in 'document'
                'expected'         => 'document.txt',
                'description'      => 'Should handle special characters in replacement string',
            ],
            'removes duplicate identifier before pattern replacement' => [
                'originalFilename' => 'photo-2024-duplicate-001.jpg',
                'pattern'          => '/2024/',
                'replacement'      => '2025',
                'expected'         => 'photo-2025.jpg',
                'description'      => 'Should remove duplicate identifier before applying pattern',
            ],
            'handles path with pattern replacement' => [
                'originalFilename' => '/var/www/images/old-photo.jpg',
                'pattern'          => '/old/',
                'replacement'      => 'new',
                'expected'         => 'new-photo.jpg',
                'description'      => 'Should handle full path and only return filename',
            ],
            'replaces with backreferences' => [
                'originalFilename' => 'document-v1.2.3.txt',
                'pattern'          => '/v(\d+)\.(\d+)\.(\d+)/',
                'replacement'      => 'version-$1_$2_$3',
                'expected'         => 'document-version-1_2_3.txt',
                'description'      => 'Should correctly handle backreferences in replacement',
            ],
            'handles unicode characters' => [
                'originalFilename' => 'файл-тест.txt',
                'pattern'          => '/тест/',
                'replacement'      => 'документ',
                'expected'         => 'файл-документ.txt',
                'description'      => 'Should handle unicode characters in pattern and replacement',
            ],
            'applies word boundary pattern' => [
                'originalFilename' => 'test-testing-test.txt',
                'pattern'          => '/\btest\b/',
                'replacement'      => 'demo',
                'expected'         => 'demo-testing-demo.txt',
                'description'      => 'Should respect word boundaries in pattern',
            ],
            'no match leaves filename unchanged' => [
                'originalFilename' => 'document.pdf',
                'pattern'          => '/xyz/',
                'replacement'      => 'abc',
                'expected'         => 'document.pdf',
                'description'      => 'Should leave filename unchanged when pattern does not match',
            ],
        ];
    }

    /**
     * Provides test cases for invalid pattern scenarios.
     *
     * This data provider supplies various malformed regex patterns that should
     * trigger exception handling in the strategy. These tests ensure that the
     * application provides meaningful error messages when users supply invalid patterns.
     *
     * Types of errors covered:
     * - Syntax errors (unclosed brackets, missing delimiters)
     * - Invalid regex constructs (malformed named groups, quantifiers)
     * - Structural errors (unmatched parentheses)
     * - Missing regex delimiters (plain strings without //)
     *
     * Each test case helps ensure robust error handling and user feedback.
     *
     * @return array<string, array{filename: string, pattern: string, replacement: string, description: string}>
     */
    public static function invalidPatternProvider(): array
    {
        return [
            'missing closing delimiter' => [
                'filename'    => 'test.txt',
                'pattern'     => '/[',
                'replacement' => 'replacement',
                'description' => 'Should throw exception for unclosed character class',
            ],
            'invalid regex syntax' => [
                'filename'    => 'test.txt',
                'pattern'     => '/(?P<invalid>/',
                'replacement' => 'replacement',
                'description' => 'Should throw exception for invalid regex syntax',
            ],
            'unmatched parentheses' => [
                'filename'    => 'test.txt',
                'pattern'     => '/((test)/',
                'replacement' => 'replacement',
                'description' => 'Should throw exception for unmatched parentheses',
            ],
            'invalid quantifier' => [
                'filename'    => 'test.txt',
                'pattern'     => '/test{999999999999}/',
                'replacement' => 'replacement',
                'description' => 'Should throw exception for invalid quantifier',
            ],
            'missing delimiter' => [
                'filename'    => 'test.txt',
                'pattern'     => 'test',
                'replacement' => 'replacement',
                'description' => 'Should throw exception for pattern without delimiters',
            ],
        ];
    }
}
