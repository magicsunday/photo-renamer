<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Helper\FilterIterator;

use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Regex\RegexMatchResult;
use MagicSunday\Renamer\Regex\SafeRegex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIterator;
use SplFileInfo;

/**
 * Unit tests for the RecursiveRegexFileFilterIterator class.
 *
 * This test class validates the behavior of a recursive iterator that filters files
 * based on regular expression patterns. The iterator is designed to traverse directory
 * structures recursively while only accepting files that match a given regex pattern.
 *
 * The iterator is particularly useful for:
 * - Processing only specific file types (e.g., only .jpg images)
 * - Finding files with certain naming patterns
 * - Excluding unwanted files during directory traversal
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(RecursiveRegexFileFilterIterator::class)]
#[UsesClass(RegexMatchResult::class)]
#[UsesClass(SafeRegex::class)]
final class RecursiveRegexFileFilterIteratorTest extends TestCase
{
    /** @var string Regular expression pattern to match .txt files */
    private const string REGEX_TXT_FILES = '/\.txt$/';

    /**
     * Create a stub SplFileInfo object for testing.
     *
     * This helper method creates a mock SplFileInfo object with configurable
     * properties to simulate different types of filesystem entries (files/directories).
     *
     * @param bool        $isDir    Whether the entry should be a directory
     * @param bool        $isFile   Whether the entry should be a file
     * @param string|null $filename The filename to return (null for directories)
     * @param bool        $isLink   Whether the entry is a symbolic link
     *
     * @return Stub&SplFileInfo A stubbed SplFileInfo object
     */
    private function createFileInfoStub(bool $isDir, bool $isFile, ?string $filename = null, bool $isLink = false): Stub&SplFileInfo
    {
        $stub = self::createStub(SplFileInfo::class);
        $stub->method('isDir')->willReturn($isDir);
        $stub->method('isFile')->willReturn($isFile);
        $stub->method('isLink')->willReturn($isLink);

        if ($filename !== null) {
            $stub->method('getFilename')->willReturn($filename);
        }

        return $stub;
    }

    /**
     * Create a filter iterator with a positioned inner iterator.
     *
     * This helper creates a properly initialized RecursiveRegexFileFilterIterator
     * with the inner iterator positioned at the first element.
     *
     * @param SplFileInfo $fileInfo The file info to iterate over
     * @param string      $regex    The regex pattern to use for filtering
     *
     * @return RecursiveRegexFileFilterIterator The configured filter iterator
     */
    private function createFilterIterator(SplFileInfo $fileInfo, string $regex = self::REGEX_TXT_FILES): RecursiveRegexFileFilterIterator
    {
        $iterator = new RecursiveArrayIterator([$fileInfo]);
        $iterator->rewind(); // Position the inner iterator at the first element

        // @phpstan-ignore argument.type
        return new RecursiveRegexFileFilterIterator($iterator, $regex, new SafeRegex());
    }

    /**
     * Create a recursive iterator for testing getChildren() method.
     *
     * This creates a custom recursive iterator that can be used to test
     * the recursive behavior of the filter iterator.
     *
     * @param array<int, SplFileInfo> $items Items to include in the iterator
     *
     * @return RecursiveIterator<string, SplFileInfo> A recursive iterator for testing
     */
    private function createRecursiveIterator(array $items = []): RecursiveIterator
    {
        // Anonymous class implementing RecursiveIterator for testing purposes
        return new class($items) extends RecursiveArrayIterator {
            public function hasChildren(): bool
            {
                return false;
            }

            public function getChildren(): RecursiveArrayIterator
            {
                return new self([]);
            }
        };
    }

    /**
     * Provides test data for the accept() method tests.
     *
     * Each test case includes:
     * - Whether the item is a directory
     * - Whether the item is a file
     * - The filename (if applicable)
     * - The expected result of the accept() method
     *
     * @return array<string, array{isDir: bool, isFile: bool, filename: ?string, expectedResult: bool, isLink?: bool}>
     */
    public static function acceptTestDataProvider(): array
    {
        return [
            'directory should be accepted' => [
                'isDir'          => true,
                'isFile'         => false,
                'filename'       => null,
                'expectedResult' => true,
            ],
            'matching txt file should be accepted' => [
                'isDir'          => false,
                'isFile'         => true,
                'filename'       => 'example.txt',
                'expectedResult' => true,
            ],
            'non-matching jpg file should be rejected' => [
                'isDir'          => false,
                'isFile'         => true,
                'filename'       => 'example.jpg',
                'expectedResult' => false,
            ],
            'file with .txt in middle should be rejected' => [
                'isDir'          => false,
                'isFile'         => true,
                'filename'       => 'example.txt.jpg',
                'expectedResult' => false,
            ],
            'uppercase TXT file should be rejected' => [
                'isDir'          => false,
                'isFile'         => true,
                'filename'       => 'example.TXT',
                'expectedResult' => false,
            ],
            'symlink to file should be rejected' => [
                'isDir'          => false,
                'isFile'         => true,
                'filename'       => 'link.txt',
                'expectedResult' => false,
                'isLink'         => true,
            ],
            'symlink to directory should be rejected' => [
                'isDir'          => true,
                'isFile'         => false,
                'filename'       => 'linkdir',
                'expectedResult' => false,
                'isLink'         => true,
            ],
        ];
    }

    /**
     * Verifies the decision of the `accept()` method for various entry types.
     * Ensures that directories are always accepted (to enable recursion),
     * while files are only accepted if they match the regex pattern.
     * Symbolic links are ignored.
     *
     * @param bool        $isDir          Whether the stub should simulate a directory
     * @param bool        $isFile         Whether the stub should simulate a file
     * @param string|null $filename       The simulated filename
     * @param bool        $expectedResult Expected result from accept()
     * @param bool        $isLink         Whether the stub should simulate a symlink
     */
    #[Test]
    #[DataProvider('acceptTestDataProvider')]
    public function accept(bool $isDir, bool $isFile, ?string $filename, bool $expectedResult, bool $isLink = false): void
    {
        // Arrange: Create a file info stub with specified properties
        $fileInfoStub   = $this->createFileInfoStub($isDir, $isFile, $filename, $isLink);
        $filterIterator = $this->createFilterIterator($fileInfoStub);

        // Act & Assert: Verify the accept() method returns expected result
        self::assertSame($expectedResult, $filterIterator->accept());
    }

    /**
     * Provides test data for getChildren() method tests.
     *
     * @return array<string, array{isDir: bool, isFile: bool, filename: ?string}>
     */
    public static function getChildrenTestDataProvider(): array
    {
        return [
            'with matching file' => [
                'isDir'    => false,
                'isFile'   => true,
                'filename' => 'example.txt',
            ],
            'with non-matching file' => [
                'isDir'    => false,
                'isFile'   => true,
                'filename' => 'example.jpg',
            ],
            'with directory' => [
                'isDir'    => true,
                'isFile'   => false,
                'filename' => 'somedir',
            ],
        ];
    }

    /**
     * Verifies that `getChildren()` returns a new iterator of the same type for directories.
     * This is essential for recursive traversal of subdirectories while maintaining
     * the filter rules (regex).
     *
     * @param bool        $isDir    Whether the stub should be a directory
     * @param bool        $isFile   Whether the stub should be a file
     * @param string|null $filename The simulated filename
     */
    #[Test]
    #[DataProvider('getChildrenTestDataProvider')]
    public function getChildrenReturnsFilterIterator(bool $isDir, bool $isFile, ?string $filename): void
    {
        // Arrange: Create test data and iterator
        $fileInfoStub  = $this->createFileInfoStub($isDir, $isFile, $filename);
        $innerIterator = $this->createRecursiveIterator([$fileInfoStub]);

        $filterIterator = new RecursiveRegexFileFilterIterator(
            $innerIterator,
            self::REGEX_TXT_FILES,
            new SafeRegex(),
        );

        // Act & Assert: Verify getChildren() returns correct type (no exception thrown)
        $children = $filterIterator->getChildren();
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertInstanceOf(RecursiveRegexFileFilterIterator::class, $children);
    }

    /**
     * Ensures that `getChildren()` returns a valid iterator even if the current
     * directory has no children (files/subdirectories).
     */
    #[Test]
    public function getChildrenWithEmptyIterator(): void
    {
        // Arrange: Create an empty iterator
        $innerIterator = $this->createRecursiveIterator([]);

        $filterIterator = new RecursiveRegexFileFilterIterator(
            $innerIterator,
            self::REGEX_TXT_FILES,
            new SafeRegex(),
        );

        // Act & Assert: Verify getChildren() works with empty iterator (no exception thrown)
        $children = $filterIterator->getChildren();
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertInstanceOf(RecursiveRegexFileFilterIterator::class, $children);
    }

    /**
     * Verifies complete iteration over a mixed directory structure.
     * Ensures that only files matching the regex pattern end up in the result,
     * while directories are traversed correctly but do not appear in the final
     * file listing (as they are consumed in recursion).
     */
    #[Test]
    public function iterationWithMixedContent(): void
    {
        // Arrange: Create a mixed collection of files and directories
        $files = [
            $this->createFileInfoStub(false, true, 'file1.txt'),  // Should be accepted
            $this->createFileInfoStub(false, true, 'file2.jpg'),  // Should be rejected
            $this->createFileInfoStub(true, false, 'directory'),  // Should be accepted (directory)
            $this->createFileInfoStub(false, true, 'file3.txt'),  // Should be accepted
            $this->createFileInfoStub(false, true, 'file4.png'),  // Should be rejected
        ];

        $iterator = new RecursiveArrayIterator($files);
        // @phpstan-ignore argument.type
        $filterIterator = new RecursiveRegexFileFilterIterator($iterator, self::REGEX_TXT_FILES, new SafeRegex());

        // Act: Iterate through the filter and collect accepted items
        $acceptedItems = [];

        foreach ($filterIterator as $item) {
            $acceptedItems[] = $item;
        }

        // Assert: Verify only matching files and directories are included
        // Should contain: file1.txt, directory, file3.txt
        self::assertCount(3, $acceptedItems);
        self::assertSame($files[0], $acceptedItems[0]); // file1.txt
        self::assertSame($files[2], $acceptedItems[1]); // directory
        self::assertSame($files[3], $acceptedItems[2]); // file3.txt
    }
}
