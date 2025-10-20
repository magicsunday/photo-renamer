<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Service\SafeExifReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(SafeExifReader::class)]
final class SafeExifReaderTest extends TestCase
{
    #[Test]
    public function itReturnsEmptyResultWhenFormatUnsupported(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'safe_exif_');

        self::assertNotFalse($tempFile);

        $path = $tempFile . '.png';

        self::assertTrue(rename($tempFile, $path));
        self::assertNotFalse(file_put_contents($path, hex2bin('89504E470D0A1A0A')));

        $reader = new SafeExifReader();

        try {
            $result = $reader->read(new SplFileInfo($path));

            self::assertFalse($result->hasMetadata());
            self::assertNull($result->metadata());
        } finally {
            @unlink($path);
        }
    }
}
