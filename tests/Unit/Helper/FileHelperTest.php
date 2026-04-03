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
use MagicSunday\Renamer\Model\LinkConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Console\Formatter\OutputFormatter;

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
#[UsesClass(LinkConfig::class)]
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

    /**
     * When link config is disabled, linkifyPath returns plain display text.
     */
    #[Test]
    public function linkifyPathReturnsPlainTextWhenDisabled(): void
    {
        $config = new LinkConfig(null, null, null);

        self::assertSame(
            'photos/test.jpg',
            FileHelper::linkifyPath('photos/test.jpg', 'photos/test.jpg', null, $config),
        );
    }

    /**
     * When link config is enabled, linkifyPath wraps display text in href tags.
     */
    #[Test]
    public function linkifyPathReturnsHrefWhenEnabled(): void
    {
        $config = new LinkConfig('/volume1/Fotos', 'F:\\', 'photo-select');

        $result = FileHelper::linkifyPath(
            'Hochzeit/photo.jpg',
            'Hochzeit/photo.jpg',
            '/volume1/Fotos',
            $config,
        );

        self::assertStringStartsWith('<href=', $result);
        self::assertStringContainsString('photo-select://', $result);
        self::assertStringContainsString('Hochzeit/photo.jpg', $result);
        self::assertStringEndsWith('</>', $result);
    }

    /**
     * Verifies that the href tag from linkifyPath can be combined with
     * Symfony Console color tags without losing either color or link.
     *
     * Symfony Console cannot nest <fg> and <href> — one eats the other.
     * The correct approach is to combine both in a single tag:
     * <fg=yellow;href=url>text</>
     */
    #[Test]
    public function linkifyPathOutputCombinesColorAndHrefInSingleTag(): void
    {
        $config = new LinkConfig('/volume1/Fotos', 'F:\\', 'photo-select');

        $result = FileHelper::linkifyPath(
            'photo.jpg',
            'photo.jpg',
            '/volume1/Fotos',
            $config,
            'yellow',
        );

        // Must produce a single combined tag: <fg=yellow;href=...>text</>
        self::assertMatchesRegularExpression(
            '/<fg=yellow;href=[^>]+>photo\.jpg<\/>/',
            $result,
            'linkifyPath should produce a combined fg+href tag, not nested tags',
        );

        // Verify that Symfony Console renders both color AND href
        $formatter = new OutputFormatter(true);
        $rendered  = (string) $formatter->format($result);

        // Must contain ANSI yellow (ESC[33m)
        self::assertStringContainsString("\033[33m", $rendered, 'Output must contain ANSI yellow color code');

        // Must contain OSC 8 href sequence
        self::assertStringContainsString("\033]8;;", $rendered, 'Output must contain OSC 8 href sequence');

        // Must contain the display text
        self::assertStringContainsString('photo.jpg', $rendered);
    }

    /**
     * When color is specified without links, wraps in plain fg tag.
     */
    #[Test]
    public function linkifyPathAppliesColorWithoutHref(): void
    {
        $config = new LinkConfig(null, null, null);

        $result = FileHelper::linkifyPath('photo.jpg', 'photo.jpg', null, $config, 'yellow');

        self::assertSame('<fg=yellow>photo.jpg</>', $result);
    }

    /**
     * linkifyPath computes the subdirectory offset between root and source.
     */
    #[Test]
    public function linkifyPathComputesSubdirectoryOffset(): void
    {
        $config = new LinkConfig('/srv/photos', '/mnt/nas/photos', null);

        $result = FileHelper::linkifyPath(
            'IMG_001.jpg',
            'IMG_001.jpg',
            '/srv/photos/2024/vacation',
            $config,
        );

        // The offset is 2024/vacation, so the full path is /mnt/nas/photos/2024/vacation/
        self::assertStringContainsString('2024/vacation', $result);
    }

    /**
     * linkifyPath with Windows-style backslashes in FILE_LINK_BASE.
     */
    #[Test]
    public function linkifyPathNormalizesBackslashesInBase(): void
    {
        $config = new LinkConfig('/srv/photos', 'Z:\\Photos\\Archive', 'photo-select');

        $result = FileHelper::linkifyPath(
            'photo.jpg',
            'photo.jpg',
            '/srv/photos',
            $config,
        );

        self::assertStringContainsString('photo-select://', $result);
        self::assertStringNotContainsString('\\', $result);
    }

    // =========================================================================
    // pathToFileUrl
    // =========================================================================

    /**
     * Verifies the transformation of a file path into a file:// URL.
     * Supports both Unix paths and Windows drive letters, and correctly
     * performs URL encoding of special characters (e.g., spaces).
     *
     * @param string $path     The input path
     * @param string $expected The expected file:// URL
     */
    #[Test]
    #[DataProvider('pathToFileUrlProvider')]
    public function pathToFileUrl(string $path, string $expected): void
    {
        self::assertSame($expected, FileHelper::pathToFileUrl($path));
    }

    /**
     * @return array<string, array{path: string, expected: string}>
     */
    public static function pathToFileUrlProvider(): array
    {
        return [
            'unix absolute' => [
                'path'     => '/srv/photos/test.jpg',
                'expected' => 'file:///srv/photos/test.jpg',
            ],
            'unix root' => [
                'path'     => '/',
                'expected' => 'file:///',
            ],
            'spaces in path' => [
                'path'     => '/srv/my photos/test file.jpg',
                'expected' => 'file:///srv/my%20photos/test%20file.jpg',
            ],
            'windows drive letter' => [
                'path'     => 'F:\\Photos\\test.jpg',
                'expected' => 'file:///F:/Photos/test.jpg',
            ],
            'windows drive with forward slashes' => [
                'path'     => 'F:/Photos/test.jpg',
                'expected' => 'file:///F:/Photos/test.jpg',
            ],
        ];
    }

    // =========================================================================
    // relativizePath
    // =========================================================================

    /**
     * Verifies the shortening of an absolute path to a relative path based
     * on a base directory. If the path is not within the base directory
     * or no base directory is specified, the original path is returned.
     *
     * @param string      $pathname The absolute path
     * @param string|null $base     The base directory
     * @param string      $expected The expected relative path
     */
    #[Test]
    #[DataProvider('relativizePathProvider')]
    public function relativizePath(string $pathname, ?string $base, string $expected): void
    {
        self::assertSame($expected, FileHelper::relativizePath($pathname, $base));
    }

    /**
     * @return array<string, array{pathname: string, base: string|null, expected: string}>
     */
    public static function relativizePathProvider(): array
    {
        return [
            'strips base' => [
                'pathname' => '/srv/photos/2024/test.jpg',
                'base'     => '/srv/photos',
                'expected' => '2024/test.jpg',
            ],
            'null base returns pathname' => [
                'pathname' => '/srv/photos/test.jpg',
                'base'     => null,
                'expected' => '/srv/photos/test.jpg',
            ],
            'empty base returns pathname' => [
                'pathname' => '/srv/photos/test.jpg',
                'base'     => '',
                'expected' => '/srv/photos/test.jpg',
            ],
            'relative base returns pathname' => [
                'pathname' => '/srv/photos/test.jpg',
                'base'     => 'relative/path',
                'expected' => '/srv/photos/test.jpg',
            ],
            'no match returns pathname' => [
                'pathname' => '/srv/photos/test.jpg',
                'base'     => '/other/base',
                'expected' => '/srv/photos/test.jpg',
            ],
            'trailing separator stripped' => [
                'pathname' => '/srv/photos/test.jpg',
                'base'     => '/srv/photos/',
                'expected' => 'test.jpg',
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
