<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Helper;

use MagicSunday\Renamer\Helper\FileHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function sprintf;

/**
 * Unit tests for the static helper methods on the FileHelper class.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
/**
 * Comprehensive tests for FileHelper covering the remaining mechanical helper
 * responsibilities after date parsing and drift semantics were split into their
 * own collaborators.
 *
 * Verifies that:
 * - Basenames and extensions are extracted correctly across OS boundaries.
 * - Duplicate suffixes are stripped reliably before re-processing.
 * - Directory URLs and relative paths are computed correctly for CLI output.
 */
#[CoversClass(FileHelper::class)]
final class FileHelperTest extends TestCase
{
    /**
     * Verifies that basenameWithoutExtension() handles multiple dots and
     * path separators correctly, returning only the filename portion.
     *
     * This is critical for generating consistent target names from varied source
     * file naming conventions (e.g., WhatsApp-style names vs. camera raw files).
     *
     * @param string $path        The file path to analyze
     * @param string $expected    The expected filename without extension
     * @param string $description Context description for failure messages
     */
    #[Test]
    #[DataProvider('basenameWithoutExtensionProvider')]
    public function basenameWithoutExtension(
        string $path,
        string $expected,
        string $description,
    ): void {
        $file = new SplFileInfo($path);

        self::assertSame(
            $expected,
            FileHelper::basenameWithoutExtension($file),
            sprintf('Failed for case: %s', $description),
        );
    }

    /**
     * Provides test cases for basenameWithoutExtension().
     *
     * @return array<string, array{path: string, expected: string, description: string}>
     */
    public static function basenameWithoutExtensionProvider(): array
    {
        return [
            'normal file with extension' => [
                'path'        => '/photos/IMG_1234.jpg',
                'expected'    => 'IMG_1234',
                'description' => 'Should strip the extension from a normal file',
            ],
            'file without extension' => [
                'path'        => '/photos/README',
                'expected'    => 'README',
                'description' => 'Should return the full basename when there is no extension',
            ],
            'dotfile' => [
                'path'        => '/photos/.hidden',
                'expected'    => '.hidden',
                'description' => 'Should return the full basename for a dotfile (leading dot is not an extension separator)',
            ],
        ];
    }

    /**
     * Verifies that stripDuplicateSuffix() removes the "-duplicate-NNN" portion
     * while preserving the base filename.
     *
     * This is essential for the renamer to be idempotent when running multiple
     * times on the same target directory, ensuring that previously suffixed
     * files are correctly recognized as potential matches.
     *
     * @param string $basename    The base filename to clean (without extension)
     * @param string $expected    The expected name after stripping the suffix
     * @param string $description Context description for failure messages
     */
    #[Test]
    #[DataProvider('stripDuplicateSuffixProvider')]
    public function stripDuplicateSuffix(
        string $basename,
        string $expected,
        string $description,
    ): void {
        self::assertSame(
            $expected,
            FileHelper::stripDuplicateSuffix($basename),
            sprintf('Failed for case: %s', $description),
        );
    }

    /**
     * Provides test cases for stripDuplicateSuffix().
     *
     * @return array<string, array{basename: string, expected: string, description: string}>
     */
    public static function stripDuplicateSuffixProvider(): array
    {
        return [
            'no suffix present' => [
                'basename'    => 'IMG_1234',
                'expected'    => 'IMG_1234',
                'description' => 'Should return the basename unchanged when no duplicate suffix exists',
            ],
            'single suffix' => [
                'basename'    => 'IMG_1234-duplicate-001',
                'expected'    => 'IMG_1234',
                'description' => 'Should strip a single duplicate suffix',
            ],
            'nested suffix strips only last' => [
                'basename'    => 'IMG_1234-duplicate-001-duplicate-002',
                'expected'    => 'IMG_1234-duplicate-001',
                'description' => 'Should strip only the trailing duplicate suffix thanks to end-of-string anchor',
            ],
        ];
    }

    // =========================================================================
    // normalizeExtension
    // =========================================================================

    /**
     * Ensures that the extension ".jpeg" (case-insensitive)
     * is correctly mapped to ".jpg".
     */
    #[Test]
    public function normalizeExtensionMapsJpeg(): void
    {
        self::assertSame('jpg', FileHelper::normalizeExtension('jpeg'));
        self::assertSame('jpg', FileHelper::normalizeExtension('JPEG'));
    }

    /**
     * Ensures that other extensions (e.g., ".png") are not
     * modified by the normalization.
     */
    #[Test]
    public function normalizeExtensionPreservesOthers(): void
    {
        self::assertSame('heic', FileHelper::normalizeExtension('HEIC'));
        self::assertSame('mov', FileHelper::normalizeExtension('mov'));
        self::assertSame('', FileHelper::normalizeExtension(''));
    }
}
