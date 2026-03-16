<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\DuplicateIdentifier;

use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetPathnameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Unit tests for the TargetPathnameStrategy class.
 *
 * This test validates that the strategy correctly identifies duplicates based on
 * the complete target pathname (including the full path). Files with the same name
 * in different directories would NOT be considered duplicates by this strategy.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(TargetPathnameStrategy::class)]
class TargetPathnameStrategyTest extends TestCase
{
    /**
     * The strategy instance being tested.
     */
    private TargetPathnameStrategy $strategy;

    /**
     * Sets up the test fixture before each test method.
     * Creates a fresh instance of TargetPathnameStrategy.
     */
    protected function setUp(): void
    {
        $this->strategy = new TargetPathnameStrategy();
    }

    /**
     * Tests that the strategy returns the complete target pathname as identifier.
     *
     * This test verifies that:
     * - The complete path is preserved in the identifier
     * - Directory structure is maintained
     * - The source file is completely ignored (only target matters)
     *
     * This differs from TargetFilenameStrategy, which returns only the filename.
     * Example: For target "/path/to/file/target.txt", returns the full path.
     */
    #[Test]
    public function generateIdentifierReturnsTargetPath(): void
    {
        // Arrange: Create source and target file objects
        // This strategy ignores the source file
        $sourceFile = new SplFileInfo('/path/to/file/source.txt');
        $targetFile = new SplFileInfo('/path/to/file/target.txt');

        // Act: Generate the identifier from the target file
        $result = $this->strategy->generateIdentifier($sourceFile, $targetFile);

        // Assert: The complete pathname should be returned
        self::assertSame(
            '/path/to/file/target.txt',
            $result
        );
    }
}
