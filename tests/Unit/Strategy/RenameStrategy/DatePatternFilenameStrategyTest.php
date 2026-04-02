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
        $file     = new SplFileInfo('test.txt');
        $strategy = $this->createStrategy('/[', '{Y}-{m}-{d}');

        $this->expectException(TargetFilenameException::class);
        $this->expectExceptionMessageMatches('/Date pattern error:/');

        $strategy->generateFilename($file);
    }

    /**
     * Verifies that the strategy extracts date components from a filename
     * and reformats them according to the replacement template.
     */
    #[Test]
    public function generateFilenameProcessesDatePattern(): void
    {
        $file     = new SplFileInfo('IMG_20240315.jpg');
        $strategy = $this->createStrategy('/^IMG_(\d{4})(\d{2})(\d{2})(\..*)?$/', '{Y}-{m}-{d}');

        self::assertSame('2024-03-15.jpg', $strategy->generateFilename($file));
    }

    /**
     * Verifies that the strategy correctly handles filenames that already contain
     * a duplicate identifier suffix by removing it before processing.
     */
    #[Test]
    public function generateFilenameRemovesDuplicateIdentifier(): void
    {
        $file     = new SplFileInfo('IMG_20240315-duplicate-001.jpg');
        $strategy = $this->createStrategy('/^IMG_(\d{4})(\d{2})(\d{2})(\..*)?$/', '{Y}-{m}-{d}');

        self::assertSame('2024-03-15.jpg', $strategy->generateFilename($file));
    }

    /**
     * Verifies the expansion of two-digit years into four-digit years.
     * Years < 70 are assumed to be in the 2000s, while >= 70 are in the 1900s.
     */
    #[Test]
    public function generateFilenameConvertsTwoDigitYear(): void
    {
        // Captures are y (2-digit year), m, d — source pattern declares this
        $strategy = $this->createStrategyWithSourcePattern(
            '/^photo_(\d{2})(\d{2})(\d{2})(\..*)?$/',
            '{Y}',
            '/^{y}{m}{d}$/',
        );

        self::assertSame('2024.jpg', $strategy->generateFilename(new SplFileInfo('photo_240315.jpg')));
        self::assertSame('1999.jpg', $strategy->generateFilename(new SplFileInfo('photo_991231.jpg')));
    }

    /**
     * Verifies that the strategy can reorder date components (e.g., from DD-MM-YYYY to YYYY-MM-DD).
     */
    #[Test]
    public function generateFilenameReordersDateComponents(): void
    {
        $file = new SplFileInfo('file_15-03-2024.txt');
        // Regex captures: group1=d, group2=m, group3=Y (European order)
        // Source pattern declares the capture order: {d}-{m}-{Y}
        // Replacement reorders to ISO: {Y}-{m}-{d}
        $strategy = $this->createStrategyWithSourcePattern(
            '/^file_(\d{2})-(\d{2})-(\d{4})(\..*)?$/',
            '{Y}-{m}-{d}',
            '/^{d}-{m}-{Y}$/',
        );

        self::assertSame('2024-03-15.txt', $strategy->generateFilename($file));
    }

    /**
     * Verifies that the strategy works correctly for files without an extension.
     */
    #[Test]
    public function generateFilenameHandlesFilesWithoutExtension(): void
    {
        $file     = new SplFileInfo('README_20240315');
        $strategy = $this->createStrategy('/^README_(\d{4})(\d{2})(\d{2})(.*)?$/', '{Y}-{m}-{d}');

        self::assertSame('2024-03-15', $strategy->generateFilename($file));
    }

    /**
     * Verifies the extraction and reformatting of a complete date-time string.
     */
    #[Test]
    public function generateFilenameWithFullDateTime(): void
    {
        $file     = new SplFileInfo('VID_20240315_143022.mp4');
        $strategy = $this->createStrategy(
            '/^VID_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})(\..*)?$/',
            '{Y}-{m}-{d}_{H}-{i}-{s}',
        );

        self::assertSame('2024-03-15_14-30-22.mp4', $strategy->generateFilename($file));
    }

    /**
     * Verifies that filenames that do not match the regex pattern are returned unchanged.
     */
    #[Test]
    public function generateFilenameReturnsOriginalForNonMatchingFile(): void
    {
        $strategy = $this->createStrategy('/^IMG_(\d{4})(\d{2})(\d{2})(\..*)?$/', '{Y}-{m}-{d}');

        // Non-matching files keep their original name (inherited from InheritFilenameStrategy)
        self::assertSame('random-file.jpg', $strategy->generateFilename(new SplFileInfo('random-file.jpg')));
    }

    /**
     * Verifies that additional filename parts (suffixes) are preserved while reformatting the date part.
     */
    #[Test]
    public function generateFilenameWithTrailingSuffix(): void
    {
        $file     = new SplFileInfo('2024-03-15.01-caption.jpg');
        $strategy = $this->createStrategy(
            '/^(\d{4})-(\d{2})-(\d{2})\.(\d{2})(.+)$/',
            '{Y}-{m}-{d}_{H}-00-00',
        );

        self::assertSame('2024-03-15_01-00-00-caption.jpg', $strategy->generateFilename($file));
    }

    /**
     * Creates a strategy where the capture order matches the replacement placeholder order.
     * For reordering tests, use createStrategyWithSourcePattern() instead.
     */
    private function createStrategy(string $regex, string $replacement): DatePatternFilenameStrategy
    {
        return new DatePatternFilenameStrategy(
            $regex,
            $replacement,
            PatternMatchSet::fromPattern($replacement),
            new SafeRegex(),
        );
    }

    /**
     * Creates a strategy with an explicit source pattern that defines the capture order.
     * Used when regex capture order differs from the replacement placeholder order.
     */
    private function createStrategyWithSourcePattern(string $regex, string $replacement, string $sourcePattern): DatePatternFilenameStrategy
    {
        return new DatePatternFilenameStrategy(
            $regex,
            $replacement,
            PatternMatchSet::fromPattern($sourcePattern),
            new SafeRegex(),
        );
    }
}
