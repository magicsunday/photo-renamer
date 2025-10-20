<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Fixtures;

use RuntimeException;
use SplFileInfo;

use function base64_decode;
use function file_put_contents;
use function register_shutdown_function;
use function rename;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class LivePhotoFixtureFactory
{
    private const JPEG_BASE64 = <<<BASE64
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

    private const MOV_BASE64 = <<<BASE64
AAAAj21vb3YAAACHdWR0YQAAAH9tZXRhAAAAAAAAAD5rZXlzAAAAAAAAAAEAAAAuAAAAAGNvbS5h
cHBsZS5xdWlja3RpbWUuY29udGVudC5pZGVudGlmaWVyAAAANWlsc3QAAAAtAAAAAQAAACVkYXRh
AAAAAQAAAABVVUlELUlQSE9ORS1MSVZFUEhPVE8=
BASE64;

    public static function createJpeg(): SplFileInfo
    {
        return self::createFixture(self::JPEG_BASE64, '.jpg');
    }

    public static function createMov(): SplFileInfo
    {
        return self::createFixture(self::MOV_BASE64, '.mov');
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
}
