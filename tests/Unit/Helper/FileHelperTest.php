<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Helper;

use DateTimeImmutable;
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
#[CoversClass(FileHelper::class)]
#[UsesClass(LinkConfig::class)]
final class FileHelperTest extends TestCase
{
    /**
     * Tests basename extraction without extension for various file types.
     *
     * @param string $path        The file path to test
     * @param string $expected    The expected basename without extension
     * @param string $description Human-readable description of the test case
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
     * Tests duplicate suffix stripping from basenames.
     *
     * @param string $basename    The input basename without extension
     * @param string $expected    The expected result after stripping
     * @param string $description Human-readable description of the test case
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
     * Tests date+time extraction from filename paths.
     *
     * @param string      $path        The file path to test
     * @param string|null $expected    The expected date+time string (Y-m-d H:i:s), or null
     * @param string      $description Human-readable description of the test case
     */
    #[Test]
    #[DataProvider('extractDateTimeFromPathProvider')]
    public function extractDateTimeFromPath(
        string $path,
        ?string $expected,
        string $description,
    ): void {
        $result = FileHelper::extractDateTimeFromPath($path);

        if ($expected === null) {
            self::assertNull(
                $result,
                sprintf('Failed for case: %s (expected null)', $description),
            );
        } else {
            self::assertInstanceOf(
                DateTimeImmutable::class,
                $result,
                sprintf('Failed for case: %s (expected DateTimeImmutable)', $description),
            );

            self::assertSame(
                $expected,
                $result->format('Y-m-d H:i:s'),
                sprintf('Failed for case: %s', $description),
            );
        }
    }

    /**
     * Provides test cases for extractDateTimeFromPath().
     *
     * @return array<string, array{path: string, expected: string|null, description: string}>
     */
    public static function extractDateTimeFromPathProvider(): array
    {
        return [
            'date with time and milliseconds' => [
                'path'        => '/photos/2013-10-17_10-36-18-000.mp4',
                'expected'    => '2013-10-17 10:36:18',
                'description' => 'Should extract date and time, ignoring milliseconds',
            ],
            'date with time' => [
                'path'        => '/photos/2013-10-17_10-36-18.mp4',
                'expected'    => '2013-10-17 10:36:18',
                'description' => 'Should extract date and time from separator-style filename',
            ],
            'date only' => [
                'path'        => '/photos/2013-10-17.jpg',
                'expected'    => '2013-10-17 00:00:00',
                'description' => 'Should extract date only, with time set to midnight',
            ],
            'compact date and time' => [
                'path'        => '/photos/20131017_103618.jpg',
                'expected'    => '2013-10-17 10:36:18',
                'description' => 'Should extract date and time from compact format',
            ],
            'prefixed compact date and time' => [
                'path'        => '/photos/IMG_20131017_103618.jpg',
                'expected'    => '2013-10-17 10:36:18',
                'description' => 'Should extract date and time from IMG_ prefixed compact format',
            ],
            'no date in filename' => [
                'path'        => '/photos/IMG_1234.jpg',
                'expected'    => null,
                'description' => 'Should return null when no date pattern is found',
            ],
            'compact date only' => [
                'path'        => '/photos/20131017.jpg',
                'expected'    => '2013-10-17 00:00:00',
                'description' => 'Should extract compact date only, with time set to midnight',
            ],
            'date with numbered suffix' => [
                'path'        => '/photos/2019-06-12-01.jpg',
                'expected'    => '2019-06-12 00:00:00',
                'description' => 'Should extract date from filename with numeric suffix (not time)',
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
}
