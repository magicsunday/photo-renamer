<?php

/**
 * Creates test images/videos with metadata for validating all renamer scenarios.
 * Run: docker compose run --rm buildbox php scripts/create-test-images.php
 */

declare(strict_types=1);

$dir = __DIR__ . '/../test-images';

if (is_dir($dir)) {
    exec('rm -rf ' . escapeshellarg($dir));
}

// Minimal valid JPEG with JFIF/EXIF header (from LivePhotoFixtureFactory)
const JPEG_BASE64 = '/9j/4QBYRXhpZgAASUkqAAgAAAABAGmHBAABAAAAGgAAAAAAAAACAAOQAgAUAAAAOAAAAJGSAgAE'
    . 'AAAATAAAAAAAAAAyMDI0OjAxOjAyIDEyOjM0OjU2ADEyMwD/4QFgaHR0cDovL25zLmFkb2JlLmNv'
    . 'bS94YXAvMS4wL1wwPD94cGFja2V0IGJlZ2luPSdcdUZFRkYnIGlkPSdXNU0wTXBDZWhpSHpyZVN6'
    . 'TlRjemtjOWQnPz48eDp4bXBtZXRhIHhtbG5zOng9J2Fkb2JlOm5zOm1ldGEvJz48cmRmOlJERiB4'
    . 'bWxuczpyZGY9J2h0dHA6Ly93d3cudzMub3JnLzE5OTkvMDIvMjItcmRmLXN5bnRheC1ucyMnPjxy'
    . 'ZGY6RGVzY3JpcHRpb24geG1sbnM6eG1wPSdodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvJyB4'
    . 'bXA6Q29udGVudElkZW50aWZpZXI9J1VVSUQtSVBIT05FLUxJVkVQSE9UTyc+PC9yZGY6RGVzY3Jp'
    . 'cHRpb24+PC9yZGY6UkRGPjwveDp4bXBtZXRhPjw/eHBhY2tldCBlbmQ9J3cnPz7/4AAQSkZJRgAB'
    . 'AQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBx'
    . 'dWFsaXR5ID0gOTAK/9sAQwADAgIDAgIDAwMDBAMDBAUIBQUEBAUKBwcGCAwKDAwLCgsLDQ4SEA0O'
    . 'EQ4LCxAWEBETFBUVFQwPFxgWFBgSFBUU/9sAQwEDBAQFBAUJBQUJFA0LDRQUFBQUFBQUFBQUFBQU'
    . 'FBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQU/8AAEQgAAQABAwERAAIRAQMRAf/E'
    . 'AB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAE'
    . 'EQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZH'
    . 'SElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1'
    . 'tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEB'
    . 'AQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXET'
    . 'IjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFla'
    . 'Y2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXG'
    . 'x8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A/VOgD//Z';

function createJpeg(string $path): void
{
    $jpeg = base64_decode(JPEG_BASE64, true);
    file_put_contents($path, $jpeg);
    // Strip ALL pre-existing metadata (including non-standard XMP segments)
    exec('exiftool -overwrite_original -all= -XMP:all= ' . escapeshellarg($path) . ' 2>/dev/null');
}

function createMov(string $path): void
{
    // Generate a real 0.1s QuickTime MOV via ffmpeg (1 frame, black, silent)
    exec(sprintf(
        'ffmpeg -y -f lavfi -i color=black:s=16x16:d=0.1 -f lavfi -i anullsrc=r=44100:cl=mono -t 0.1 -c:v libx264 -c:a aac -movflags +faststart -f mov %s 2>/dev/null',
        escapeshellarg($path),
    ));
}

function createMp4(string $path): void
{
    exec(sprintf(
        'ffmpeg -y -f lavfi -i color=black:s=16x16:d=0.1 -f lavfi -i anullsrc=r=44100:cl=mono -t 0.1 -c:v libx264 -c:a aac -movflags +faststart -f mp4 %s 2>/dev/null',
        escapeshellarg($path),
    ));
}

function createHeic(string $path): void
{
    $tmpJpeg = sys_get_temp_dir() . '/heic-source-' . uniqid() . '.jpg';

    // Create a small JPEG via ffmpeg, then convert to HEIC via heif-enc
    exec(sprintf(
        'ffmpeg -y -f lavfi -i color=white:s=16x16 -frames:v 1 %s 2>/dev/null',
        escapeshellarg($tmpJpeg),
    ));

    exec(sprintf(
        'heif-enc -o %s %s 2>/dev/null',
        escapeshellarg($path),
        escapeshellarg($tmpJpeg),
    ));

    @unlink($tmpJpeg);
}

function stripToMetadataOnly(string $source, string $dest, bool $isVideo = false): void
{
    if (!file_exists($source)) {
        echo "  WARNING: Source not found: $source (skipping)\n";

        return;
    }

    if ($isVideo) {
        // Create minimal MOV, then copy metadata from real file
        createMov($dest);
    } else {
        // Create 1x1 JPEG via ffmpeg, then copy metadata from real file
        exec(sprintf('ffmpeg -y -f lavfi -i color=white:s=1x1 -frames:v 1 %s 2>/dev/null', escapeshellarg($dest)));
    }

    exec(sprintf(
        'exiftool -overwrite_original -TagsFromFile %s -all:all %s 2>/dev/null',
        escapeshellarg($source),
        escapeshellarg($dest),
    ));
}

function exiftool(string ...$args): void
{
    $cmd = array_map('escapeshellarg', ['exiftool', '-overwrite_original', ...$args]);
    exec(implode(' ', $cmd) . ' 2>/dev/null');
}

echo "Creating test images in test-images/...\n\n";

// ============================================================================
// 01 - Basic EXIF rename
// ============================================================================
mkdir("$dir/01-basic-rename", 0755, true);
createJpeg("$dir/01-basic-rename/IMG_1234.jpg");
exiftool('-DateTimeOriginal=2024:06:15 14:30:00', '-SubSecTimeOriginal=000', "$dir/01-basic-rename/IMG_1234.jpg");
echo "  01 IMG_1234.jpg              → expects 2024-06-15_14-30-00-000.jpg\n";

// ============================================================================
// 02 - Duplicate detection (byte-identical, same EXIF date)
// ============================================================================
mkdir("$dir/02-duplicates", 0755, true);
createJpeg("$dir/02-duplicates/photo-a.jpg");
exiftool('-DateTimeOriginal=2024:03:20 09:15:30', '-SubSecTimeOriginal=500', "$dir/02-duplicates/photo-a.jpg");
copy("$dir/02-duplicates/photo-a.jpg", "$dir/02-duplicates/photo-b.jpg");
echo "  02 photo-a + photo-b         → one canonical, other -duplicate-001\n";

// ============================================================================
// 03 - Hash sub-grouping (same second, different content)
// ============================================================================
mkdir("$dir/03-hash-subgroups", 0755, true);
createJpeg("$dir/03-hash-subgroups/burst-1.jpg");
exiftool('-DateTimeOriginal=2024:07:04 18:00:00', '-SubSecTimeOriginal=100', "$dir/03-hash-subgroups/burst-1.jpg");
createJpeg("$dir/03-hash-subgroups/burst-2.jpg");
file_put_contents("$dir/03-hash-subgroups/burst-2.jpg", file_get_contents("$dir/03-hash-subgroups/burst-2.jpg") . 'different');
exiftool('-DateTimeOriginal=2024:07:04 18:00:00', '-SubSecTimeOriginal=200', "$dir/03-hash-subgroups/burst-2.jpg");
echo "  03 burst-1 + burst-2         → different hashes, burst-2 gets -002\n";

// ============================================================================
// 04 - Live Photo pair (JPEG + MOV, matching Content Identifier)
// ============================================================================
mkdir("$dir/04-live-photo-pair", 0755, true);
createJpeg("$dir/04-live-photo-pair/IMG_0001.jpg");
exiftool(
    '-DateTimeOriginal=2024:08:10 11:22:33', '-SubSecTimeOriginal=456',
    '-ContentIdentifier=AAAA-BBBB-CCCC-DDDD',
    '-Make=Apple', '-Model=iPhone 12', '-Software=16.0',
    '-GPSLatitude=51.3397', '-GPSLongitude=12.3731', '-GPSLatitudeRef=N', '-GPSLongitudeRef=E',
    "$dir/04-live-photo-pair/IMG_0001.jpg",
);
createMov("$dir/04-live-photo-pair/IMG_0001.mov");
exiftool(
    '-Keys:ContentIdentifier=AAAA-BBBB-CCCC-DDDD',
    '-QuickTime:CreateDate=2024:08:10 11:22:33',
    '-Keys:Make=Apple', '-Keys:Model=iPhone 12', '-Keys:Software=16.0',
    '-Keys:GPSCoordinates=51.3397 12.3731',
    "$dir/04-live-photo-pair/IMG_0001.mov",
);
echo "  04 IMG_0001.jpg + .mov       → Live Photo pair, MOV inherits date\n";

// ============================================================================
// 05 - Fallback date (only ModifyDate, no DateTimeOriginal)
// ============================================================================
mkdir("$dir/05-fallback-date", 0755, true);
createJpeg("$dir/05-fallback-date/scan-001.jpg");
exiftool('-ModifyDate=2023:12:25 08:00:00', "$dir/05-fallback-date/scan-001.jpg");
echo "  05 scan-001.jpg              → [F] fallback date from ModifyDate\n";

// ============================================================================
// 06 - Ambiguous timezone (QuickTime MOV without TZ info)
// ============================================================================
mkdir("$dir/06-ambiguous-timezone", 0755, true);
createMov("$dir/06-ambiguous-timezone/MVI_1234.mov");
exiftool('-QuickTime:CreateDate=2024:02:14 19:30:00', '-QuickTime:ModifyDate=2024:02:14 19:30:00', "$dir/06-ambiguous-timezone/MVI_1234.mov");
echo "  06 MVI_1234.mov              → [W] ambiguous timezone\n";

// ============================================================================
// 07 - No metadata at all
// ============================================================================
mkdir("$dir/07-no-metadata", 0755, true);
createJpeg("$dir/07-no-metadata/screenshot.jpg");
exiftool('-all=', "$dir/07-no-metadata/screenshot.jpg");
echo "  07 screenshot.jpg            → [S] skipped, no metadata\n";

// ============================================================================
// 08 - Date drift (filename date differs from EXIF by >7 days)
// ============================================================================
mkdir("$dir/08-date-drift", 0755, true);
createJpeg("$dir/08-date-drift/2024-01-15_photo.jpg");
exiftool('-DateTimeOriginal=2024:03:20 10:00:00', '-SubSecTimeOriginal=000', "$dir/08-date-drift/2024-01-15_photo.jpg");
echo "  08 2024-01-15_photo.jpg      → [W] drift (filename Jan, EXIF Mar)\n";

// ============================================================================
// 09 - Extension normalization (.JPEG → .jpg)
// ============================================================================
mkdir("$dir/09-extension-normalize", 0755, true);
createJpeg("$dir/09-extension-normalize/photo.JPEG");
exiftool('-DateTimeOriginal=2024:05:01 12:00:00', '-SubSecTimeOriginal=000', "$dir/09-extension-normalize/photo.JPEG");
echo "  09 photo.JPEG                → 2024-05-01_12-00-00-000.jpg\n";

// ============================================================================
// 10 - Already correctly named (idempotent)
// ============================================================================
mkdir("$dir/10-already-correct", 0755, true);
createJpeg("$dir/10-already-correct/2024-10-20_16-45-00-000.jpg");
exiftool('-DateTimeOriginal=2024:10:20 16:45:00', '-SubSecTimeOriginal=000', "$dir/10-already-correct/2024-10-20_16-45-00-000.jpg");
echo "  10 2024-10-20_16-45-00-000   → [O] already correct\n";

// ============================================================================
// 11 - write-date: nodata (date in filename, no metadata)
// ============================================================================
mkdir("$dir/11-write-date-nodata", 0755, true);
createJpeg("$dir/11-write-date-nodata/2024-09-01_10-30-00.jpg");
exiftool('-all=', "$dir/11-write-date-nodata/2024-09-01_10-30-00.jpg");
echo "  11 write-date nodata         → needs DateTimeOriginal written\n";

// ============================================================================
// 12 - write-date: timezone (MOV with ambiguous UTC, date in filename)
// ============================================================================
mkdir("$dir/12-write-date-timezone", 0755, true);
createMov("$dir/12-write-date-timezone/2024-04-20-video.mov");
exiftool('-QuickTime:CreateDate=2024:04:20 15:45:00', '-QuickTime:ModifyDate=2024:04:20 15:45:00', "$dir/12-write-date-timezone/2024-04-20-video.mov");
echo "  12 2024-04-20-video.mov      → [W] timezone, needs Keys:CreationDate\n";

// ============================================================================
// 13 - MP4 video with proper TZ
// ============================================================================
mkdir("$dir/13-mp4-with-tz", 0755, true);
createMp4("$dir/13-mp4-with-tz/VID_20240101.mp4");
exiftool('-QuickTime:CreateDate=2024:01:01 14:00:00', '-Keys:CreationDate=2024:01:01 15:00:00+01:00', "$dir/13-mp4-with-tz/VID_20240101.mp4");
echo "  13 VID_20240101.mp4          → renames using Keys:CreationDate TZ\n";

// ============================================================================
// 14 - HEIC image (real HEIF container via heif-enc)
// ============================================================================
mkdir("$dir/14-heic-image", 0755, true);
createHeic("$dir/14-heic-image/IMG_0042.heic");
exiftool(
    '-DateTimeOriginal=2024:11:30 20:15:45', '-SubSecTimeOriginal=789',
    '-ContentIdentifier=HEIC-LP-1234',
    '-Make=Apple', '-Model=iPhone 14 Pro', '-Software=17.0',
    "$dir/14-heic-image/IMG_0042.heic",
);
echo "  14 IMG_0042.heic             → 2024-11-30_20-15-45-789.heic (no TZ conversion)\n";

// ============================================================================
// 15 - Mac epoch zero (1970-01-01 metadata from corrupt QuickTime)
// ============================================================================
mkdir("$dir/15-epoch-zero", 0755, true);
createMov("$dir/15-epoch-zero/2022-06-05_17-55-00.mp4");
exiftool('-QuickTime:CreateDate=1970:01:01 00:00:00', '-QuickTime:ModifyDate=1970:01:01 00:00:00', "$dir/15-epoch-zero/2022-06-05_17-55-00.mp4");
echo "  15 epoch-zero                → [W] 1970 metadata, drift from 2022 filename\n";

// ============================================================================
// 16 - Re-export drift (filename 2022, metadata 2024 = re-encoded)
// ============================================================================
mkdir("$dir/16-reexport-drift", 0755, true);
createMov("$dir/16-reexport-drift/2022-12-10_14-19-08.mov");
exiftool('-QuickTime:CreateDate=2024:09:19 00:06:04', "$dir/16-reexport-drift/2022-12-10_14-19-08.mov");
echo "  16 re-export drift           → [W] filename 2022, metadata 2024\n";

// ============================================================================
// 17 - Filename without time (date-only name + metadata has real time)
// ============================================================================
mkdir("$dir/17-date-only-filename", 0755, true);
createMov("$dir/17-date-only-filename/2020-02-07-3483.mov");
exiftool('-QuickTime:CreateDate=2020:02:07 21:20:25', "$dir/17-date-only-filename/2020-02-07-3483.mov");
echo "  17 date-only filename        → [W] ambiguous, metadata has time 21:20:25\n";

// ============================================================================
// 18 - Live Photo conflict (mismatched Content IDs)
// ============================================================================
// Use metadata from real iPhone photos (stripped to 1x1 pixel) for proper
// ContentIdentifier that imagemeta can read. Source: /volume1/Fotos/2020/
// The ContentIdentifiers differ (F647D858 vs 990E69E9) despite being a pair.
mkdir("$dir/18-live-photo-conflict", 0755, true);
$lpConflictSource = '/volume1/Fotos/2020/2020-08-18 - JH Schierke (18.08-22.08.2020)';
stripToMetadataOnly("$lpConflictSource/2020-08-19_11-09-34-857.jpg", "$dir/18-live-photo-conflict/2024-08-19_11-09-34-857.jpg");
stripToMetadataOnly("$lpConflictSource/2020-08-19_11-09-34-857.mov", "$dir/18-live-photo-conflict/2024-08-19_11-09-34-857.mov", true);
echo "  18 LP conflict               → [C] mismatched content IDs from real iPhone photos\n";

// ============================================================================
// 19 - write-date: fallback (only ModifyDate, date in filename)
// ============================================================================
mkdir("$dir/19-write-date-fallback", 0755, true);
createJpeg("$dir/19-write-date-fallback/2024-02-14_09-00-00.jpg");
exiftool('-ModifyDate=2024:02:14 09:00:00', "$dir/19-write-date-fallback/2024-02-14_09-00-00.jpg");
echo "  19 write-date fallback       → needs DateTimeOriginal from filename\n";

// ============================================================================
// 20 - write-date: drift (metadata date far from filename date)
// ============================================================================
mkdir("$dir/20-write-date-drift", 0755, true);
createJpeg("$dir/20-write-date-drift/2024-01-15_10-00-00.jpg");
exiftool('-DateTimeOriginal=2024:06:20 10:00:00', '-SubSecTimeOriginal=000', "$dir/20-write-date-drift/2024-01-15_10-00-00.jpg");
echo "  20 write-date drift          → metadata 157 days from filename\n";

echo "\nDone. Run:\n";
echo "  make run CMD=\"rename:exif test-images --dry-run --list-all\"\n";
echo "  make run CMD=\"rename:write-date test-images --dry-run\"\n";
echo "  make run CMD=\"rename:verify test-images\"\n";
