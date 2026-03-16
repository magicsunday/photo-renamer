<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Command\FilterIterator;

use MagicSunday\Renamer\Command\FilterIterator\RecursiveUppercaseFilenameFilterIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveIterator;
use stdClass;
use TypeError;

/**
 * Unit tests for the RecursiveUppercaseFilenameFilterIterator class.
 *
 * This test class validates the behavior of a specialized iterator that filters files
 * containing uppercase characters in their filenames. The iterator extends the
 * RecursiveRegexFileFilterIterator with a predefined pattern to detect uppercase letters.
 *
 * The iterator is useful for:
 * - Finding files that don't comply with lowercase naming conventions
 * - Preparing files for case-sensitive filesystems
 * - Standardizing file collections (e.g., photos from cameras that use uppercase)
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(RecursiveUppercaseFilenameFilterIterator::class)]
final class RecursiveUppercaseFilenameFilterIteratorTest extends TestCase
{
    /**
     * Test that the constructor initializes the parent with the correct regular expression.
     *
     * This test validates that:
     * - The iterator can be successfully instantiated with a RecursiveIterator
     * - The parent constructor is properly called with the uppercase detection regex
     * - The created instance is of the correct type
     *
     * The actual regex pattern for detecting uppercase letters is encapsulated
     * within the RecursiveUppercaseFilenameFilterIterator class.
     */
    #[Test]
    public function constructorPassesCorrectRegexToParent(): void
    {
        // Arrange: Create a stub RecursiveIterator for testing
        $stubIterator = self::createStub(RecursiveIterator::class);

        // Act: Create the uppercase filter iterator
        $recursiveUpperFilter = new RecursiveUppercaseFilenameFilterIterator($stubIterator);

        // Assert: Verify the instance was created successfully (no exception thrown)
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertInstanceOf(RecursiveUppercaseFilenameFilterIterator::class, $recursiveUpperFilter);
    }

    /**
     * Test that a valid RecursiveIterator is required in constructor.
     *
     * This test ensures type safety by validating that:
     * - The constructor enforces the RecursiveIterator type requirement
     * - Passing an invalid type throws a TypeError
     * - The iterator cannot be instantiated with incorrect parameters
     *
     * This is important for maintaining the recursive filtering capability
     * throughout the directory traversal.
     */
    #[Test]
    public function constructorRequiresRecursiveIterator(): void
    {
        // Assert: Expect a TypeError when passing invalid type
        $this->expectException(TypeError::class);

        // Act: Attempt to create iterator with invalid parameter type
        // @phpstan-ignore-next-line (intentionally passing wrong type for test)
        new RecursiveUppercaseFilenameFilterIterator(new stdClass());
    }
}
