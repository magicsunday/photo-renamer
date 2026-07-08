<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Helper;

use MagicSunday\Renamer\Helper\PathHelper;
use MagicSunday\Renamer\Model\LinkConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Verifies the extracted path helper responsible for relative display paths and
 * terminal hyperlink generation.
 */
#[CoversClass(PathHelper::class)]
#[UsesClass(LinkConfig::class)]
final class PathHelperTest extends TestCase
{
    /**
     * When link config is disabled, linkifyPath returns plain display text.
     */
    #[Test]
    public function linkifyPathReturnsPlainTextWhenDisabled(): void
    {
        $config = new LinkConfig(null, null, null);

        self::assertSame(
            'photos/test.jpg',
            PathHelper::linkifyPath('photos/test.jpg', 'photos/test.jpg', null, $config),
        );
    }

    /**
     * When link config is enabled, linkifyPath wraps display text in href tags.
     */
    #[Test]
    public function linkifyPathReturnsHrefWhenEnabled(): void
    {
        $config = new LinkConfig('/volume1/Fotos', 'F:\\', 'photo-select');

        $result = PathHelper::linkifyPath(
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
     * Verifies that color and href survive together in a single Symfony Console tag.
     */
    #[Test]
    public function linkifyPathOutputCombinesColorAndHrefInSingleTag(): void
    {
        $config = new LinkConfig('/volume1/Fotos', 'F:\\', 'photo-select');

        $result = PathHelper::linkifyPath(
            'photo.jpg',
            'photo.jpg',
            '/volume1/Fotos',
            $config,
            'yellow',
        );

        self::assertMatchesRegularExpression(
            '/<fg=yellow;href=[^>]+>photo\.jpg<\/>/',
            $result,
            'linkifyPath should produce a combined fg+href tag, not nested tags',
        );

        $formatter = new OutputFormatter(true);
        $rendered  = (string) $formatter->format($result);

        self::assertStringContainsString("\033[33m", $rendered, 'Output must contain ANSI yellow color code');
        self::assertStringContainsString("\033]8;;", $rendered, 'Output must contain OSC 8 href sequence');
        self::assertStringContainsString('photo.jpg', $rendered);
    }

    /**
     * When color is specified without links, wraps in a plain color tag.
     */
    #[Test]
    public function linkifyPathAppliesColorWithoutHref(): void
    {
        $config = new LinkConfig(null, null, null);

        $result = PathHelper::linkifyPath('photo.jpg', 'photo.jpg', null, $config, 'yellow');

        self::assertSame('<fg=yellow>photo.jpg</>', $result);
    }

    /**
     * Verifies source-directory offset calculation for host-accessible links.
     */
    #[Test]
    public function linkifyPathComputesSubdirectoryOffset(): void
    {
        $config = new LinkConfig('/srv/photos', '/mnt/nas/photos', null);

        $result = PathHelper::linkifyPath(
            'IMG_001.jpg',
            'IMG_001.jpg',
            '/srv/photos/2024/vacation',
            $config,
        );

        self::assertStringContainsString('2024/vacation', $result);
    }

    /**
     * Verifies backslash normalization for Windows-style base paths.
     */
    #[Test]
    public function linkifyPathNormalizesBackslashesInBase(): void
    {
        $config = new LinkConfig('/srv/photos', 'Z:\\Photos\\Archive', 'photo-select');

        $result = PathHelper::linkifyPath(
            'photo.jpg',
            'photo.jpg',
            '/srv/photos',
            $config,
        );

        self::assertStringContainsString('photo-select://', $result);
        self::assertStringNotContainsString('\\', $result);
    }

    /**
     * Verifies `file://` URL conversion for Unix and Windows paths.
     *
     * @param string $path     Input native path.
     * @param string $expected Expected encoded file URL.
     */
    #[Test]
    #[DataProvider('pathToFileUrlProvider')]
    public function pathToFileUrl(string $path, string $expected): void
    {
        self::assertSame($expected, PathHelper::pathToFileUrl($path));
    }

    /**
     * Provides path-to-URL cases.
     *
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
            'windows unc path' => [
                'path'     => '\\\\server\\share\\test file.jpg',
                'expected' => 'file://server/share/test%20file.jpg',
            ],
        ];
    }

    /**
     * Verifies absolute-to-relative path shortening.
     *
     * @param string      $pathname Absolute path.
     * @param string|null $base     Base directory.
     * @param string      $expected Expected relative path.
     */
    #[Test]
    #[DataProvider('relativizePathProvider')]
    public function relativizePath(string $pathname, ?string $base, string $expected): void
    {
        self::assertSame($expected, PathHelper::relativizePath($pathname, $base));
    }

    /**
     * Provides relative path cases.
     *
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
            'windows drive path strips base' => [
                'pathname' => 'C:\\Photos\\2024\\test.jpg',
                'base'     => 'C:\\Photos',
                'expected' => '2024/test.jpg',
            ],
            'windows drive mismatch returns pathname' => [
                'pathname' => 'D:\\Photos\\test.jpg',
                'base'     => 'C:\\Photos',
                'expected' => 'D:\\Photos\\test.jpg',
            ],
        ];
    }
}
