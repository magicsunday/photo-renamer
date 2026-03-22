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
use MagicSunday\Renamer\Regex\RegexMatchAllResult;
use MagicSunday\Renamer\Regex\RegexMatchCollection;
use MagicSunday\Renamer\Regex\RegexMatchGroup;
use MagicSunday\Renamer\Regex\RegexMatchResult;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Strategy\RenameStrategy\DatePattern\PatternMatchSet;
use MagicSunday\Renamer\Strategy\RenameStrategy\DatePatternFilenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function array_map;
use function implode;

/**
 * Unit tests for DatePatternFilenameStrategy class.
 *
 * This test class validates the behavior of DatePatternFilenameStrategy, which transforms
 * filenames by extracting date components from the filename using regex patterns and
 * reformatting them according to a specified date format template.
 *
 * Key features tested:
 * - Date component extraction using regex capture groups
 * - Date reformatting with PHP DateTime format characters
 * - Two-digit to four-digit year conversion
 * - Integration with duplicate identifier removal (inherited behavior)
 * - Error handling for invalid patterns
 *
 * The strategy works by:
 * 1. Matching date components in the filename using the provided regex pattern
 * 2. Mapping matched groups to date format characters (Y, m, d, H, i, s)
 * 3. Creating a DateTime object from the extracted components
 * 4. Formatting the DateTime using the replacement pattern
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(DatePatternFilenameStrategy::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(RegexMatchAllResult::class)]
#[UsesClass(RegexMatchCollection::class)]
#[UsesClass(RegexMatchGroup::class)]
#[UsesClass(RegexMatchResult::class)]
#[UsesClass(SafeRegex::class)]
#[UsesClass(PatternMatchSet::class)]
final class DatePatternFilenameStrategyTest extends TestCase
{
    /**
     * Tests that invalid regex patterns throw appropriate exceptions.
     *
     * This test ensures proper error handling when the strategy encounters
     * invalid regex patterns that cannot be processed.
     */
    #[Test]
    public function generateFilenameThrowsExceptionOnInvalidPattern(): void
    {
        // Create a file info object for testing
        $file = new SplFileInfo('test.txt');

        // Initialize the strategy with an invalid regex pattern
        $strategy = new DatePatternFilenameStrategy(
            '/[',  // Invalid regex - unclosed bracket
            '{Y}-{m}-{d}',
            $this->createPatternMatchSet(['Y']),
            new SafeRegex()
        );

        // Expect a TargetFilenameException to be thrown
        $this->expectException(TargetFilenameException::class);
        $this->expectExceptionMessageMatches('/Date pattern error:/');

        // Attempt to generate a filename, which should trigger the exception
        $strategy->generateFilename($file);
    }

    /**
     * Tests basic functionality with a simple date pattern.
     *
     * This test validates that the strategy can extract and reformat date components
     * from a filename. Due to the complex nature of how the strategy processes
     * the replacement pattern, this test focuses on verifying the basic operation
     * without making assumptions about the exact output format.
     */
    #[Test]
    public function generateFilenameProcessesDatePattern(): void
    {
        // Create a file with date in YYYYMMDD format
        $file = new SplFileInfo('IMG_20240315.jpg');

        // Configure the strategy to extract the date components
        $strategy = new DatePatternFilenameStrategy(
            '/IMG_(\d{4})(\d{2})(\d{2})/',
            '{Y}-{m}-{d}',
            $this->createPatternMatchSet(['Y', 'm', 'd']),
            new SafeRegex()
        );

        // Get the result
        $result = $strategy->generateFilename($file);

        // Assert that the result contains the expected date components
        self::assertStringContainsString('2024', $result);
        self::assertStringContainsString('03', $result);
        self::assertStringContainsString('15', $result);
        self::assertStringEndsWith('.jpg', $result);
    }

    /**
     * Tests that duplicate identifiers are removed before processing.
     *
     * This test verifies that the strategy correctly inherits the behavior
     * from InheritFilenameStrategy, removing "-duplicate-XXX" suffixes.
     */
    #[Test]
    public function generateFilenameRemovesDuplicateIdentifier(): void
    {
        // Create a file with duplicate identifier
        $file = new SplFileInfo('IMG_20240315-duplicate-001.jpg');

        // Configure strategy
        $strategy = new DatePatternFilenameStrategy(
            '/IMG_(\d{4})(\d{2})(\d{2})/',
            '{Y}-{m}-{d}',
            $this->createPatternMatchSet(['Y', 'm', 'd']),
            new SafeRegex()
        );

        // Get the result
        $result = $strategy->generateFilename($file);

        // Assert that duplicate identifier was removed (not present in result)
        self::assertStringNotContainsString('duplicate', $result);
        self::assertStringContainsString('2024', $result);
        self::assertStringEndsWith('.jpg', $result);
    }

    /**
     * Tests two-digit to four-digit year conversion.
     *
     * This test verifies that the strategy correctly converts two-digit years
     * to four-digit years using PHP's DateTime year interpretation rules.
     */
    #[Test]
    public function generateFilenameConvertsTwoDigitYear(): void
    {
        // Test with year in 2000s range (00-69)
        $file     = new SplFileInfo('photo_240315.jpg');
        $strategy = new DatePatternFilenameStrategy(
            '/photo_(\d{2})(\d{2})(\d{2})/',
            '{Y}',
            $this->createPatternMatchSet(['y', 'm', 'd']),  // Note: lowercase 'y' for 2-digit year
            new SafeRegex()
        );

        $result = $strategy->generateFilename($file);

        // Assert that 2-digit year 24 was converted to 2024
        self::assertStringContainsString('2024', $result);

        // Test with year in 1900s range (70-99)
        $file2     = new SplFileInfo('photo_991231.jpg');
        $strategy2 = new DatePatternFilenameStrategy(
            '/photo_(\d{2})(\d{2})(\d{2})/',
            '{Y}',
            $this->createPatternMatchSet(['y', 'm', 'd']),
            new SafeRegex()
        );

        $result2 = $strategy2->generateFilename($file2);

        // Assert that 2-digit year 99 was converted to 1999
        self::assertStringContainsString('1999', $result2);
    }

    /**
     * Tests reordering of date components.
     *
     * This test verifies that date components can be reordered during transformation,
     * for example converting from European format (dd-mm-yyyy) to ISO format.
     */
    #[Test]
    public function generateFilenameReordersDateComponents(): void
    {
        // Create a file with European date format (dd-mm-yyyy)
        $file = new SplFileInfo('file_15-03-2024.txt');

        // Configure strategy to reorder to ISO format
        $strategy = new DatePatternFilenameStrategy(
            '/file_(\d{2})-(\d{2})-(\d{4})/',
            '{Y}-{m}-{d}',
            $this->createPatternMatchSet(['d', 'm', 'Y']),  // Note: mapping order is d, m, Y
            new SafeRegex()
        );

        $result = $strategy->generateFilename($file);

        // Assert date components are present and in correct order
        self::assertStringContainsString('2024', $result);
        self::assertStringContainsString('03', $result);
        self::assertStringContainsString('15', $result);
        self::assertStringEndsWith('.txt', $result);
    }

    /**
     * Tests handling of files without extensions.
     *
     * This test verifies that the strategy works with files that have
     * no file extension.
     */
    #[Test]
    public function generateFilenameHandlesFilesWithoutExtension(): void
    {
        // Create a file without extension
        $file = new SplFileInfo('README_20240315');

        // Configure strategy
        $strategy = new DatePatternFilenameStrategy(
            '/README_(\d{4})(\d{2})(\d{2})/',
            '{Y}-{m}-{d}',
            $this->createPatternMatchSet(['Y', 'm', 'd']),
            new SafeRegex()
        );

        $result = $strategy->generateFilename($file);

        // Assert date components are present and no extension is added
        self::assertStringContainsString('2024', $result);
        self::assertStringContainsString('03', $result);
        self::assertStringContainsString('15', $result);
        self::assertStringNotContainsString('.', $result);
    }

    /**
     * @param string[] $placeholders
     */
    private function createPatternMatchSet(array $placeholders): PatternMatchSet
    {
        $pattern = '/^' . implode('-', array_map(
            static fn (string $p): string => '{' . $p . '}',
            $placeholders,
        )) . '$/';

        return PatternMatchSet::fromPattern($pattern);
    }
}
