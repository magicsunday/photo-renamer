<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Fixtures;

use RuntimeException;
use SplFileInfo;

use function base64_decode;
use function base64_encode;
use function count;
use function file_put_contents;
use function pack;
use function register_shutdown_function;
use function rename;
use function sprintf;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Factory for creating minimal-but-valid JPEG and QuickTime MOV fixture files
 * with embedded EXIF/XMP metadata for Live Photo pairing tests.
 *
 * The JPEG fixture contains a real JFIF/EXIF header with a DateTimeOriginal tag
 * and an XMP ContentIdentifier. The MOV fixture contains a QuickTime moov/udta/meta
 * atom tree with the Apple content identifier and creation date keys.
 *
 * All fixtures are written to the system temp directory and automatically deleted
 * via a shutdown function, so tests do not need explicit cleanup.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class LivePhotoFixtureFactory
{
    private const string JPEG_BASE64 = <<<BASE64
/9j/4QBYRXhpZgAASUkqAAgAAAABAGmHBAABAAAAGgAAAAAAAAACAAOQAgAUAAAAOAAAAJGSAgAE
AAAATAAAAAAAAAAyMDI0OjAxOjAyIDEyOjM0OjU2ADEyMwD/4QFgaHR0cDovL25zLmFkb2JlLmNv
bS94YXAvMS4wL1wwPD94cGFja2V0IGJlZ2luPSdcdUZFRkYnIGlkPSdXNU0wTXBDZWhpSHpyZVN6
TlRjemtjOWQnPz48eDp4bXBtZXRhIHhtbG5zOng9J2Fkb2JlOm5zOm1ldGEvJz48cmRmOlJERiB4
bWxuczpyZGY9J2h0dHA6Ly93d3cudzMub3JnLzE5OTkvMDIvMjItcmRmLXN5bnRheC1ucyMnPjxy
ZGY6RGVzY3JpcHRpb24geG1sbnM6eG1wPSdodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvJyB4
bXA6Q29udGVudElkZW50aWZpZXI9J1VVSUQtSVBIT05FLUxJVkVQSE9UTyc+PC9yZGY6RGVzY3Jp
cHRpb24+PC9yZGY6UkRGPjwveDp4bXBtZXRhPjw/eHBhY2tldCBlbmQ9J3cnPz7/4AAQSkZJRgAB
AQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBx
dWFsaXR5ID0gOTAK/9sAQwADAgIDAgIDAwMDBAMDBAUIBQUEBAUKBwcGCAwKDAwLCgsLDQ4SEA0O
EQ4LCxAWEBETFBUVFQwPFxgWFBgSFBUU/9sAQwEDBAQFBAUJBQUJFA0LDRQUFBQUFBQUFBQUFBQU
FBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQU/8AAEQgAAQABAwERAAIRAQMRAf/E
AB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAE
EQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZH
SElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1
tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEB
AQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXET
IjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFla
Y2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXG
x8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A/VOgD//Z
BASE64;

    public static function createJpeg(): SplFileInfo
    {
        return self::createFixture(self::JPEG_BASE64, '.jpg');
    }

    public static function createMov(): SplFileInfo
    {
        $payload = self::createQuickTimeMovPayload(
            'UUID-IPHONE-LIVEPHOTO',
            '2024-05-05T12:34:56.789+00:00',
            'com.apple.quicktime.creationdate',
        );

        return self::createFixture(base64_encode($payload), '.mov');
    }

    public static function createMovWithCreationDate(string $creationDate, string $keyName = 'com.apple.quicktime.creationdate'): SplFileInfo
    {
        $payload = self::createQuickTimeMovPayload(
            'UUID-IPHONE-LIVEPHOTO',
            $creationDate,
            $keyName,
        );

        return self::createFixture(base64_encode($payload), '.mov');
    }

    private static function createFixture(string $base64, string $extension): SplFileInfo
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'live_photo_');

        if ($tmpPath === false) {
            throw new RuntimeException('Unable to create temporary fixture.');
        }

        $targetPath = $tmpPath . $extension;

        if (!@rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);

            throw new RuntimeException(sprintf('Unable to prepare fixture path with extension "%s".', $extension));
        }

        $payload = base64_decode($base64, true);

        if ($payload === false) {
            @unlink($targetPath);

            throw new RuntimeException('Failed to decode base64 fixture payload.');
        }

        if (@file_put_contents($targetPath, $payload) === false) {
            @unlink($targetPath);

            throw new RuntimeException(sprintf('Failed to write fixture file "%s".', $targetPath));
        }

        register_shutdown_function(static function () use ($targetPath): void {
            @unlink($targetPath);
        });

        return new SplFileInfo($targetPath);
    }

    private static function createQuickTimeMovPayload(
        string $identifier,
        string $creationDate,
        string $creationDateKey,
    ): string {
        $entries = [
            ['key' => 'com.apple.quicktime.content.identifier', 'value' => $identifier],
            ['key' => $creationDateKey, 'value' => $creationDate],
        ];

        $keysPayload = "\0\0\0\0" . pack('N', count($entries));
        $ilstEntries = '';

        foreach ($entries as $index => $entry) {
            $keyPayload = pack('N', 8 + strlen($entry['key']))
                . "\0\0\0\0"
                . $entry['key'];

            $keysPayload .= $keyPayload;

            $value       = $entry['value'];
            $dataPayload = pack('N', 16 + strlen($value))
                . 'data'
                . "\0\0\0\1"
                . "\0\0\0\0"
                . $value;

            $ilstEntries .= pack('N', 8 + strlen($dataPayload))
                . pack('N', $index + 1)
                . $dataPayload;
        }

        $keysAtom = pack('N', 8 + strlen($keysPayload))
            . 'keys'
            . $keysPayload;

        $ilstAtom = pack('N', 8 + strlen($ilstEntries))
            . 'ilst'
            . $ilstEntries;

        $metaPayload = "\0\0\0\0"
            . $keysAtom
            . $ilstAtom;

        $metaAtom = pack('N', 8 + strlen($metaPayload))
            . 'meta'
            . $metaPayload;

        $udtaAtom = pack('N', 8 + strlen($metaAtom))
            . 'udta'
            . $metaAtom;

        return pack('N', 8 + strlen($udtaAtom))
            . 'moov'
            . $udtaAtom;
    }
}
