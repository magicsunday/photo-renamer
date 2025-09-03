<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\DuplicateIdentifierStrategy;

use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\TargetFilenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Unit tests for the TargetFilenameStrategy class.
 *
 * This test validates that the strategy correctly identifies duplicates based on
 * the target filename only (ignoring the path). Files with the same name but in
 * different directories would be considered duplicates by this strategy.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(TargetFilenameStrategy::class)]
class TargetFilenameStrategyTest extends TestCase
{
    /**
     * The strategy instance being tested.
     *
     * @var TargetFilenameStrategy
     */
    private TargetFilenameStrategy $strategy;

    /**
     * Sets up the test fixture before each test method.
     * Creates a fresh instance of TargetFilenameStrategy.
     */
    protected function setUp(): void
    {
        $this->strategy = new TargetFilenameStrategy();
    }

    /**
     * Tests that the strategy returns only the target filename as identifier.
     *
     * This test verifies that:
     * - The path components are ignored
     * - Only the filename (with extension) is used as the identifier
     * - The source file is completely ignored (only target matters)
     *
     * Example: For target "/path/to/file/target.txt", returns "target.txt"
     */
    #[Test]
    public function generateIdentifierReturnsTargetFilename(): void
    {
        // Arrange: Create source and target file objects with different paths
        // The source file path doesn't matter for this strategy
        $sourceFile = new SplFileInfo('/path/to/file/source.txt');
        $targetFile = new SplFileInfo('/path/to/file/target.txt');

        // Act: Generate the identifier from the target file
        $result = $this->strategy->generateIdentifier($sourceFile, $targetFile);

        // Assert: Only the filename should be returned, without path
        self::assertSame(
            'target.txt',
            $result
        );
    }
}
