<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\RenameStrategy;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Strategy\RenameStrategy\LowerCaseFilenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Unit tests for LowerCaseFilenameStrategy class.
 *
 * This test class verifies the behavior of LowerCaseFilenameStrategy, which converts
 * all characters in a filename to lowercase. This strategy is useful for:
 * - Ensuring consistent filename casing across different operating systems
 * - Preventing case-sensitivity issues when transferring files between systems
 * - Standardizing file naming conventions in a project
 * - Improving compatibility with case-sensitive file systems (like Linux)
 *
 * The strategy converts both the filename and extension to lowercase,
 * handling various character sets and special characters appropriately.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(LowerCaseFilenameStrategy::class)]
#[UsesClass(FileHelper::class)]
final class LowerCaseFilenameStrategyTest extends TestCase
{
    /**
     * The strategy instance being tested.
     */
    private LowerCaseFilenameStrategy $strategy;

    /**
     * Sets up the test fixture before each test method.
     *
     * Creates a fresh instance of LowerCaseFilenameStrategy to ensure
     * test isolation and prevent any state carryover between tests.
     */
    protected function setUp(): void
    {
        $this->strategy = new LowerCaseFilenameStrategy();
    }

    /**
     * Tests that the strategy correctly converts filenames to lowercase.
     *
     * This test verifies the basic functionality of the strategy by:
     * - Testing mixed case filename conversion
     * - Testing uppercase extension conversion
     * - Ensuring the entire filename (including extension) is lowercase
     *
     * Example: "OriginalFileName.TXT" becomes "originalfilename.txt"
     *
     * This is the primary use case for the strategy - converting all
     * uppercase and mixed case characters to their lowercase equivalents.
     */
    #[Test]
    public function itGeneratesLowercaseFilename(): void
    {
        // Create a file with mixed case name and uppercase extension
        $file = new SplFileInfo('OriginalFileName.TXT');

        // Apply the lowercase strategy
        $result = $this->strategy->generateFilename($file);

        // Verify complete conversion to lowercase
        self::assertSame(
            'originalfilename.txt',
            $result
        );
    }
}
