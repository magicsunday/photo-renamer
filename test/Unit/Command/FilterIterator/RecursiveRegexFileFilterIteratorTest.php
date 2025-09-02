<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Command\FilterIterator;

use MagicSunday\Renamer\Command\FilterIterator\RecursiveRegexFileFilterIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIterator;
use SplFileInfo;

#[CoversClass(RecursiveRegexFileFilterIterator::class)]
final class RecursiveRegexFileFilterIteratorTest extends TestCase
{
    private const string REGEX_TXT_FILES = '/\.txt$/';

    /**
     * Create a stub SplFileInfo object.
     *
     * @param bool        $isDir
     * @param bool        $isFile
     * @param string|null $filename
     *
     * @return Stub&SplFileInfo
     */
    private function createFileInfoStub(bool $isDir, bool $isFile, ?string $filename = null): Stub
    {
        $stub = $this->createStub(SplFileInfo::class);
        $stub->method('isDir')->willReturn($isDir);
        $stub->method('isFile')->willReturn($isFile);

        if ($filename !== null) {
            $stub->method('getFilename')->willReturn($filename);
        }

        return $stub;
    }

    /**
     * Create a filter iterator with a positioned inner iterator.
     *
     * @param SplFileInfo $fileInfo
     * @param string      $regex
     *
     * @return RecursiveRegexFileFilterIterator
     */
    private function createFilterIterator(SplFileInfo $fileInfo, string $regex = self::REGEX_TXT_FILES): RecursiveRegexFileFilterIterator
    {
        $iterator = new RecursiveArrayIterator([$fileInfo]);
        $iterator->rewind(); // Position the inner iterator

        return new RecursiveRegexFileFilterIterator($iterator, $regex);
    }

    /**
     * Create a recursive iterator for testing getChildren().
     *
     * @param array $items
     *
     * @return RecursiveIterator
     */
    private function createRecursiveIterator(array $items = []): RecursiveIterator
    {
        return new class($items) extends RecursiveArrayIterator {
            public function hasChildren(): bool
            {
                return false;
            }

            public function getChildren(): ?RecursiveArrayIterator
            {
                return new self([]);
            }
        };
    }

    /**
     * @return array<string, array{isDir: bool, isFile: bool, filename: ?string, expectedResult: bool}>
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
        ];
    }

    #[Test]
    #[DataProvider('acceptTestDataProvider')]
    public function accept(bool $isDir, bool $isFile, ?string $filename, bool $expectedResult): void
    {
        $fileInfoStub   = $this->createFileInfoStub($isDir, $isFile, $filename);
        $filterIterator = $this->createFilterIterator($fileInfoStub);

        self::assertSame($expectedResult, $filterIterator->accept());
    }

    /**
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

    #[Test]
    #[DataProvider('getChildrenTestDataProvider')]
    public function getChildrenReturnsFilterIterator(bool $isDir, bool $isFile, ?string $filename): void
    {
        $fileInfoStub  = $this->createFileInfoStub($isDir, $isFile, $filename);
        $innerIterator = $this->createRecursiveIterator([$fileInfoStub]);

        $filterIterator = new RecursiveRegexFileFilterIterator(
            $innerIterator,
            self::REGEX_TXT_FILES
        );

        self::assertInstanceOf(
            RecursiveRegexFileFilterIterator::class,
            $filterIterator->getChildren()
        );
    }

    #[Test]
    public function getChildrenWithEmptyIterator(): void
    {
        $innerIterator = $this->createRecursiveIterator([]);

        $filterIterator = new RecursiveRegexFileFilterIterator(
            $innerIterator,
            self::REGEX_TXT_FILES
        );

        self::assertInstanceOf(
            RecursiveRegexFileFilterIterator::class,
            $filterIterator->getChildren()
        );
    }

    /**
     * Test that the filter correctly iterates through mixed content.
     */
    #[Test]
    public function iterationWithMixedContent(): void
    {
        $files = [
            $this->createFileInfoStub(false, true, 'file1.txt'),
            $this->createFileInfoStub(false, true, 'file2.jpg'),
            $this->createFileInfoStub(true, false, 'directory'),
            $this->createFileInfoStub(false, true, 'file3.txt'),
            $this->createFileInfoStub(false, true, 'file4.png'),
        ];

        $iterator       = new RecursiveArrayIterator($files);
        $filterIterator = new RecursiveRegexFileFilterIterator($iterator, self::REGEX_TXT_FILES);

        $acceptedItems = [];
        foreach ($filterIterator as $item) {
            $acceptedItems[] = $item;
        }

        // Should contain: file1.txt, directory, file3.txt
        self::assertCount(3, $acceptedItems);
        self::assertSame($files[0], $acceptedItems[0]); // file1.txt
        self::assertSame($files[2], $acceptedItems[1]); // directory
        self::assertSame($files[3], $acceptedItems[2]); // file3.txt
    }
}
