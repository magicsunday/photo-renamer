<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\RenameStrategy;

use MagicSunday\Renamer\Strategy\RenameStrategy\InheritFilenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function sprintf;

#[CoversClass(InheritFilenameStrategy::class)]
class InheritFilenameStrategyTest extends TestCase
{
    private InheritFilenameStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new InheritFilenameStrategy();
    }

    #[Test]
    #[DataProvider('filenameProvider')]
    public function generateFilename(
        string $filename,
        string $expected,
        string $description,
    ): void {
        $file = new SplFileInfo($filename);

        $this->assertSame(
            $expected,
            $this->strategy->generateFilename($file),
            sprintf('Failed for case: %s', $description)
        );
    }

    /**
     * Provides test cases for filename generation.
     *
     * @return array<string, array{filename: string, expected: string, description: string}>
     */
    public static function filenameProvider(): array
    {
        return [
            'removes duplicate identifier' => [
                'filename'    => 'test-duplicate-001.txt',
                'expected'    => 'test.txt',
                'description' => 'Should remove -duplicate-XXX suffix from filename',
            ],
            'preserves original filename without duplicate' => [
                'filename'    => 'original.txt',
                'expected'    => 'original.txt',
                'description' => 'Should keep original filename when no duplicate identifier exists',
            ],
            'handles files without extension' => [
                'filename'    => 'file-duplicate-002',
                'expected'    => 'file',
                'description' => 'Should handle files without extension correctly',
            ],
            'handles complex multi-part extensions' => [
                'filename'    => 'archive-duplicate-003.tar.gz',
                'expected'    => 'archive.tar.gz',
                'description' => 'Should handle multi-part extensions like .tar.gz',
            ],
            'removes all duplicate identifiers' => [
                'filename'    => 'test-duplicate-001-duplicate-002.txt',
                'expected'    => 'test.txt',
                'description' => 'Should remove all of the duplicate identifiers',
            ],
            'preserves similar non-matching patterns' => [
                'filename'    => 'test-duplicated-file.txt',
                'expected'    => 'test-duplicated-file.txt',
                'description' => 'Should not remove similar but non-matching patterns like "duplicated"',
            ],
            'handles edge case with exact pattern match' => [
                'filename'    => 'file-duplicate-999.jpg',
                'expected'    => 'file.jpg',
                'description' => 'Should handle maximum three-digit duplicate number',
            ],
            'preserves incomplete duplicate patterns' => [
                'filename'    => 'file-duplicate-.txt',
                'expected'    => 'file-duplicate-.txt',
                'description' => 'Should not remove incomplete duplicate patterns',
            ],
            'preserves duplicate with wrong digit count' => [
                'filename'    => 'file-duplicate-1.txt',
                'expected'    => 'file-duplicate-1.txt',
                'description' => 'Should not remove duplicate identifier with wrong digit count',
            ],
            'removes duplicate identifier - path given' => [
                'filename'    => '/var/www/images/photo-duplicate-001.jpg',
                'expected'    => 'photo.jpg',
                'description' => 'Should remove -duplicate-XXX suffix from filename (complete path given)',
            ],
        ];
    }
}
