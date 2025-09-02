<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\DuplicateIdentifierStrategy;

use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\ContentHashStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(ContentHashStrategy::class)]
class ContentHashStrategyTest extends TestCase
{
    #[Test]
    public function generateIdentifierReturnsHash(): void
    {
        $sourceFile = $this->createMock(SplFileInfo::class);
        $sourceFile->method('getPathname')->willReturn(__DIR__ . '/testFile.txt');

        $targetFile = $this->createMock(SplFileInfo::class);

        file_put_contents(
            __DIR__ . '/testFile.txt',
            'Test content for hashing'
        );

        $strategy = new ContentHashStrategy();
        $result   = $strategy->generateIdentifier(
            $sourceFile,
            $targetFile
        );

        $expectedHash = hash_file(
            'xxh128',
            __DIR__ . '/testFile.txt'
        );

        self::assertEquals(
            $expectedHash,
            $result
        );

        unlink(__DIR__ . '/testFile.txt');
    }

    #[Test]
    public function generateIdentifierReturnsFalseForNonExistentFile(): void
    {
        $sourceFile = $this->createMock(SplFileInfo::class);
        $sourceFile->method('getPathname')->willReturn(__DIR__ . '/nonExistentFile.txt');
        $targetFile = $this->createMock(SplFileInfo::class);

        $strategy = new ContentHashStrategy();
        $result   = $strategy->generateIdentifier(
            $sourceFile,
            $targetFile
        );

        self::assertFalse($result);
    }

    #[Test]
    public function generateIdentifierWithEmptyFile(): void
    {
        $sourceFile = $this->createMock(SplFileInfo::class);
        $sourceFile->method('getPathname')->willReturn(__DIR__ . '/emptyFile.txt');
        $targetFile = $this->createMock(SplFileInfo::class);

        file_put_contents(
            __DIR__ . '/emptyFile.txt',
            ''
        );

        $strategy = new ContentHashStrategy();
        $result   = $strategy->generateIdentifier(
            $sourceFile,
            $targetFile
        );

        $expectedHash = hash_file(
            'xxh128',
            __DIR__ . '/emptyFile.txt'
        );

        self::assertEquals(
            $expectedHash,
            $result
        );

        unlink(__DIR__ . '/emptyFile.txt');
    }
}
