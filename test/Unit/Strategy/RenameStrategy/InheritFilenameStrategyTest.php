<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\RenameStrategy;

use MagicSunday\Renamer\Strategy\RenameStrategy\InheritFilenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function sprintf;

/**
 * Unit tests for InheritFilenameStrategy class.
 *
 * This test class verifies the behavior of InheritFilenameStrategy, which is responsible
 * for cleaning up filenames that contain duplicate identifiers. The strategy removes
 * "-duplicate-XXX" suffixes from filenames while preserving the original file extension.
 *
 * This strategy is typically used when:
 * - Files have been renamed with duplicate identifiers during previous operations
 * - You want to restore original filenames by removing duplicate markers
 * - Files need to be cleaned up after a duplicate detection/renaming process
 *
 * The pattern matched is: -duplicate-\d{3} (e.g., -duplicate-001, -duplicate-999)
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(InheritFilenameStrategy::class)]
class InheritFilenameStrategyTest extends TestCase
{
    /**
     * The strategy instance being tested.
     *
     * @var InheritFilenameStrategy
     */
    private InheritFilenameStrategy $strategy;

    /**
     * Sets up the test fixture before each test method.
     *
     * Initializes a fresh instance of InheritFilenameStrategy to ensure
     * test isolation and prevent state leakage between tests.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->strategy = new InheritFilenameStrategy();
    }

    /**
     * Tests filename generation with various input patterns.
     *
     * This parameterized test verifies that the strategy correctly processes
     * different filename patterns, including
     * - Files with duplicate identifiers that should be removed
     * - Files without duplicate identifiers that should remain unchanged
     * - Edge cases like missing extensions, multipart extensions, and malformed patterns
     *
     * The test uses a data provider to test multiple scenarios efficiently,
     * ensuring comprehensive coverage of the strategy's behavior.
     *
     * @param string $filename    The input filename to process
     * @param string $expected    The expected output filename after processing
     * @param string $description Human-readable description of the test case
     *
     * @return void
     */
    #[Test]
    #[DataProvider('filenameProvider')]
    public function generateFilename(
        string $filename,
        string $expected,
        string $description,
    ): void {
        // Create a SplFileInfo instance from the test filename
        $file = new SplFileInfo($filename);

        // Assert that the strategy produces the expected output
        self::assertSame(
            $expected,
            $this->strategy->generateFilename($file),
            sprintf('Failed for case: %s', $description)
        );
    }

    /**
     * Provides test cases for filename generation.
     *
     * This data provider supplies a comprehensive set of test cases covering:
     * - Standard duplicate identifier removal
     * - Files without duplicate identifiers (should pass through unchanged)
     * - Files without extensions
     * - Files with complex multipart extensions (.tar.gz)
     * - Multiple duplicate identifiers in the same filename
     * - Similar but non-matching patterns (e.g., "duplicated" vs "duplicate")
     * - Edge cases with malformed or incomplete patterns
     * - Files with full path specifications
     *
     * Each test case includes:
     * - filename: The input filename to be processed
     * - expected: The expected result after processing
     * - description: A human-readable description of what's being tested
     *
     * @return array<string, array{filename: string, expected: string, description: string}>
     */
    public static function filenameProvider(): array
    {
        return [
            'removes duplicate identifier' => [
                'filename'    => 'test-duplicate-001.txt',
                'expected'    => 'test.txt',
                'description' => 'Should remove -duplicate-XXX suffix from filename',
            ],
            'preserves original filename without duplicate' => [
                'filename'    => 'original.txt',
                'expected'    => 'original.txt',
                'description' => 'Should keep original filename when no duplicate identifier exists',
            ],
            'handles files without extension' => [
                'filename'    => 'file-duplicate-002',
                'expected'    => 'file',
                'description' => 'Should handle files without extension correctly',
            ],
            'handles complex multi-part extensions' => [
                'filename'    => 'archive-duplicate-003.tar.gz',
                'expected'    => 'archive.tar.gz',
                'description' => 'Should handle multi-part extensions like .tar.gz',
            ],
            'removes all duplicate identifiers' => [
                'filename'    => 'test-duplicate-001-duplicate-002.txt',
                'expected'    => 'test.txt',
                'description' => 'Should remove all of the duplicate identifiers',
            ],
            'preserves similar non-matching patterns' => [
                'filename'    => 'test-duplicated-file.txt',
                'expected'    => 'test-duplicated-file.txt',
                'description' => 'Should not remove similar but non-matching patterns like "duplicated"',
            ],
            'handles edge case with exact pattern match' => [
                'filename'    => 'file-duplicate-999.jpg',
                'expected'    => 'file.jpg',
                'description' => 'Should handle maximum three-digit duplicate number',
            ],
            'preserves incomplete duplicate patterns' => [
                'filename'    => 'file-duplicate-.txt',
                'expected'    => 'file-duplicate-.txt',
                'description' => 'Should not remove incomplete duplicate patterns',
            ],
            'preserves duplicate with wrong digit count' => [
                'filename'    => 'file-duplicate-1.txt',
                'expected'    => 'file-duplicate-1.txt',
                'description' => 'Should not remove duplicate identifier with wrong digit count',
            ],
            'removes duplicate identifier - path given' => [
                'filename'    => '/var/www/images/photo-duplicate-001.jpg',
                'expected'    => 'photo.jpg',
                'description' => 'Should remove -duplicate-XXX suffix from filename (complete path given)',
            ],
        ];
    }
}
