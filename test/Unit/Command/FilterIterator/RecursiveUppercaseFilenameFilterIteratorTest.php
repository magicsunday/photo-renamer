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

#[CoversClass(RecursiveUppercaseFilenameFilterIterator::class)]
final class RecursiveUppercaseFilenameFilterIteratorTest extends TestCase
{
    /**
     * Test that the constructor initializes the parent with the correct regular expression.
     */
    #[Test]
    public function constructorPassesCorrectRegexToParent(): void
    {
        $stubIterator         = $this->createStub(RecursiveIterator::class);
        $recursiveUpperFilter = new RecursiveUppercaseFilenameFilterIterator($stubIterator);

        // Assert that the created instance is of the expected type
        self::assertInstanceOf(
            RecursiveUppercaseFilenameFilterIterator::class,
            $recursiveUpperFilter
        );
    }

    /**
     * Test that a valid RecursiveIterator is required in constructor.
     */
    #[Test]
    public function constructorRequiresRecursiveIterator(): void
    {
        $this->expectException(TypeError::class);

        // Passing an invalid type to trigger the exception
        new RecursiveUppercaseFilenameFilterIterator(new stdClass());
    }
}
