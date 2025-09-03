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
use MagicSunday\Renamer\Strategy\RenameStrategy\PatternFilenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use function sprintf;

#[CoversClass(PatternFilenameStrategy::class)]
class PatternFilenameStrategyTest extends TestCase
{
    #[Test]
    #[DataProvider('patternReplacementProvider')]
    public function generateFilenameAppliesPatternReplacement(
        string $originalFilename,
        string $pattern,
        string $replacement,
        string $expected,
        string $description,
    ): void {
        $file = new SplFileInfo($originalFilename);

        $strategy = new PatternFilenameStrategy($pattern, $replacement);

        self::assertSame(
            $expected,
            $strategy->generateFilename($file),
            sprintf('Failed for case: %s', $description)
        );
    }

    #[Test]
    #[DataProvider('invalidPatternProvider')]
    public function generateFilenameThrowsExceptionOnInvalidPattern(
        string $filename,
        string $pattern,
        string $replacement,
        string $description,
    ): void {
        $file = new SplFileInfo($filename);

        $strategy = new PatternFilenameStrategy($pattern, $replacement);

        $this->expectException(TargetFilenameException::class);
        $this->expectExceptionMessageMatches('/Regular expression error:/');

        $strategy->generateFilename($file);
    }

    /**
     * Provides test cases for pattern replacement functionality.
     *
     * @return array<string, array{originalFilename: string, pattern: string, replacement: string, expected: string, description: string}>
     */
    public static function patternReplacementProvider(): array
    {
        return [
            'replaces digits in filename' => [
                'originalFilename' => 'example123.txt',
                'pattern'          => '/\d+/',
                'replacement'      => '456',
                'expected'         => 'example456.txt',
                'description'      => 'Should replace all digits with the replacement string',
            ],
            'replaces specific word' => [
                'originalFilename' => 'test-file.jpg',
                'pattern'          => '/test/',
                'replacement'      => 'production',
                'expected'         => 'production-file.jpg',
                'description'      => 'Should replace specific word in filename',
            ],
            'replaces with empty string' => [
                'originalFilename' => 'file-2024-01-01.txt',
                'pattern'          => '/-\d{4}-\d{2}-\d{2}/',
                'replacement'      => '',
                'expected'         => 'file.txt',
                'description'      => 'Should remove matched pattern when replacement is empty',
            ],
            'replaces multiple occurrences' => [
                'originalFilename' => 'test-test-test.txt',
                'pattern'          => '/test/',
                'replacement'      => 'demo',
                'expected'         => 'demo-demo-demo.txt',
                'description'      => 'Should replace all occurrences of the pattern',
            ],
            'handles case-insensitive replacement' => [
                'originalFilename' => 'TEST-File.TXT',
                'pattern'          => '/test/i',
                'replacement'      => 'new',
                'expected'         => 'new-File.TXT',
                'description'      => 'Should handle case-insensitive pattern matching',
            ],
            'preserves file extension' => [
                'originalFilename' => 'document.pdf',
                'pattern'          => '/document/',
                'replacement'      => 'report',
                'expected'         => 'report.pdf',
                'description'      => 'Should preserve file extension after replacement',
            ],
            'handles files without extension' => [
                'originalFilename' => 'README',
                'pattern'          => '/README/',
                'replacement'      => 'CHANGELOG',
                'expected'         => 'CHANGELOG',
                'description'      => 'Should handle files without extension',
            ],
            'applies complex regex pattern' => [
                'originalFilename' => 'IMG_20240101_123456.jpg',
                'pattern'          => '/IMG_(\d{8})_(\d{6})/',
                'replacement'      => 'photo-$1-$2',
                'expected'         => 'photo-20240101-123456.jpg',
                'description'      => 'Should handle complex regex with capture groups',
            ],
            'handles special characters in replacement' => [
                'originalFilename' => 'file.txt',
                'pattern'          => '/file/',
                'replacement'      => 'doc\$1ument',
                'expected'         => 'doc$1ument.txt',
                'description'      => 'Should handle special characters in replacement string',
            ],
            'removes duplicate identifier before pattern replacement' => [
                'originalFilename' => 'photo-duplicate-001-2024.jpg',
                'pattern'          => '/2024/',
                'replacement'      => '2025',
                'expected'         => 'photo-2025.jpg',
                'description'      => 'Should remove duplicate identifier before applying pattern',
            ],
            'handles path with pattern replacement' => [
                'originalFilename' => '/var/www/images/old-photo.jpg',
                'pattern'          => '/old/',
                'replacement'      => 'new',
                'expected'         => 'new-photo.jpg',
                'description'      => 'Should handle full path and only return filename',
            ],
            'replaces with backreferences' => [
                'originalFilename' => 'document-v1.2.3.txt',
                'pattern'          => '/v(\d+)\.(\d+)\.(\d+)/',
                'replacement'      => 'version-$1_$2_$3',
                'expected'         => 'document-version-1_2_3.txt',
                'description'      => 'Should correctly handle backreferences in replacement',
            ],
            'handles unicode characters' => [
                'originalFilename' => 'файл-тест.txt',
                'pattern'          => '/тест/',
                'replacement'      => 'документ',
                'expected'         => 'файл-документ.txt',
                'description'      => 'Should handle unicode characters in pattern and replacement',
            ],
            'applies word boundary pattern' => [
                'originalFilename' => 'test-testing-test.txt',
                'pattern'          => '/\btest\b/',
                'replacement'      => 'demo',
                'expected'         => 'demo-testing-demo.txt',
                'description'      => 'Should respect word boundaries in pattern',
            ],
            'no match leaves filename unchanged' => [
                'originalFilename' => 'document.pdf',
                'pattern'          => '/xyz/',
                'replacement'      => 'abc',
                'expected'         => 'document.pdf',
                'description'      => 'Should leave filename unchanged when pattern does not match',
            ],
        ];
    }

    /**
     * Provides test cases for invalid pattern scenarios.
     *
     * @return array<string, array{filename: string, pattern: string, replacement: string, description: string}>
     */
    public static function invalidPatternProvider(): array
    {
        return [
            'missing closing delimiter' => [
                'filename'    => 'test.txt',
                'pattern'     => '/[',
                'replacement' => 'replacement',
                'description' => 'Should throw exception for unclosed character class',
            ],
            'invalid regex syntax' => [
                'filename'    => 'test.txt',
                'pattern'     => '/(?P<invalid>/',
                'replacement' => 'replacement',
                'description' => 'Should throw exception for invalid regex syntax',
            ],
            'unmatched parentheses' => [
                'filename'    => 'test.txt',
                'pattern'     => '/((test)/',
                'replacement' => 'replacement',
                'description' => 'Should throw exception for unmatched parentheses',
            ],
            'invalid quantifier' => [
                'filename'    => 'test.txt',
                'pattern'     => '/test{999999999999}/',
                'replacement' => 'replacement',
                'description' => 'Should throw exception for invalid quantifier',
            ],
            'missing delimiter' => [
                'filename'    => 'test.txt',
                'pattern'     => 'test',
                'replacement' => 'replacement',
                'description' => 'Should throw exception for pattern without delimiters',
            ],
        ];
    }
}
