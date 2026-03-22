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

function createJpeg(string $path): void
{
    // Create minimal 1x1 JPEG via ffmpeg — clean, no pre-existing metadata
    exec(sprintf(
        'ffmpeg -y -f lavfi -i color=white:s=1x1 -frames:v 1 %s 2>/dev/null',
        escapeshellarg($path),
    ));
}

function createVideo(string $path, string $format = 'mov'): void
{
    exec(sprintf(
        'ffmpeg -y -f lavfi -i color=black:s=16x16:d=0.1 -f lavfi -i anullsrc=r=44100:cl=mono -t 0.1 -c:v libx264 -c:a aac -movflags +faststart -f %s %s 2>/dev/null',
        escapeshellarg($format),
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
        createVideo($dest);
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
echo "  03 burst-1 + burst-2         → different SubSecTime → separate targets (-100, -200)\n";

// ============================================================================
// 04 - Live Photo pair (JPEG + MOV, matching Content Identifier)
// ============================================================================
// Use metadata from real iPhone Live Photo pair for proper ContentIdentifier
// that imagemeta can read. Source: /volume1/Fotos/2025/
mkdir("$dir/04-live-photo-pair", 0755, true);
$lpSource = '/volume1/Fotos/2025/2025-01-01_00-02-20-016';
stripToMetadataOnly("$lpSource.jpg", "$dir/04-live-photo-pair/IMG_0001.jpg");
stripToMetadataOnly("$lpSource.mov", "$dir/04-live-photo-pair/IMG_0001.mov", true);
echo "  04 IMG_0001.jpg + .mov       → Live Photo pair, MOV inherits still's date\n";

// ============================================================================
// 05 - Fallback date (only ModifyDate, no DateTimeOriginal)
// ============================================================================
mkdir("$dir/05-fallback-date", 0755, true);
createJpeg("$dir/05-fallback-date/scan-001.jpg");
exiftool('-ModifyDate=2023:12:25 08:00:00', "$dir/05-fallback-date/scan-001.jpg");
echo "  05 scan-001.jpg              → [F] fallback, only ModifyDate (0x0132)\n";

// ============================================================================
// 06 - Ambiguous timezone (QuickTime MOV without TZ info)
// ============================================================================
mkdir("$dir/06-ambiguous-timezone", 0755, true);
createVideo("$dir/06-ambiguous-timezone/MVI_1234.mov");
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
createVideo("$dir/12-write-date-timezone/2024-04-20-video.mov");
exiftool('-QuickTime:CreateDate=2024:04:20 15:45:00', '-QuickTime:ModifyDate=2024:04:20 15:45:00', "$dir/12-write-date-timezone/2024-04-20-video.mov");
echo "  12 2024-04-20-video.mov      → [W] timezone, needs Keys:CreationDate\n";

// ============================================================================
// 13 - MP4 video with proper TZ
// ============================================================================
mkdir("$dir/13-mp4-with-tz", 0755, true);
createVideo("$dir/13-mp4-with-tz/VID_20240101.mp4", 'mp4');
exiftool('-QuickTime:CreateDate=2024:01:01 14:00:00', '-Keys:CreationDate=2024:01:01 15:00:00+01:00', "$dir/13-mp4-with-tz/VID_20240101.mp4");
echo "  13 VID_20240101.mp4          → renames using Keys:CreationDate TZ\n";

// ============================================================================
// 14 - HEIC image (real HEIF container via heif-enc)
// ============================================================================
mkdir("$dir/14-heic-image", 0755, true);
createHeic("$dir/14-heic-image/IMG_0042.heic");
exiftool(
    '-DateTimeOriginal=2024:11:30 20:15:45', '-SubSecTimeOriginal=789',
    '-Make=Apple', '-Model=iPhone 14 Pro', '-Software=17.0',
    "$dir/14-heic-image/IMG_0042.heic",
);
echo "  14 IMG_0042.heic             → 2024-11-30_20-15-45-789.heic (no TZ conversion)\n";

// ============================================================================
// 15 - Mac epoch zero (1970-01-01 metadata from corrupt QuickTime)
// ============================================================================
mkdir("$dir/15-epoch-zero", 0755, true);
createVideo("$dir/15-epoch-zero/2022-06-05_17-55-00.mp4", 'mp4');
exiftool('-QuickTime:CreateDate=1970:01:01 00:00:00', '-QuickTime:ModifyDate=1970:01:01 00:00:00', "$dir/15-epoch-zero/2022-06-05_17-55-00.mp4");
echo "  15 epoch-zero                → [S] no capture date (0000:00:00 from Mac epoch 0)\n";

// ============================================================================
// 16 - Re-export drift (filename 2022, metadata 2024 = re-encoded)
// ============================================================================
mkdir("$dir/16-reexport-drift", 0755, true);
createVideo("$dir/16-reexport-drift/2022-12-10_14-19-08.mov");
exiftool('-QuickTime:CreateDate=2024:09:19 00:06:04', "$dir/16-reexport-drift/2022-12-10_14-19-08.mov");
echo "  16 re-export drift           → [W] filename 2022, metadata 2024\n";

// ============================================================================
// 17 - Filename without time (date-only name + metadata has real time)
// ============================================================================
mkdir("$dir/17-date-only-filename", 0755, true);
createVideo("$dir/17-date-only-filename/2020-02-07-3483.mov");
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
// Filename says 14:00, but metadata only has ModifyDate at 09:00 → mismatch → fallback detected
createJpeg("$dir/19-write-date-fallback/2024-02-14_14-00-00.jpg");
exiftool('-ModifyDate=2024:02:14 09:00:00', "$dir/19-write-date-fallback/2024-02-14_14-00-00.jpg");
echo "  19 write-date fallback       → [fallback] only ModifyDate, no DateTimeOriginal\n";

// ============================================================================
// 20 - write-date: drift (metadata date far from filename date)
// ============================================================================
mkdir("$dir/20-write-date-drift", 0755, true);
createJpeg("$dir/20-write-date-drift/2024-01-15_10-00-00.jpg");
exiftool('-DateTimeOriginal=2024:06:20 10:00:00', '-SubSecTimeOriginal=000', "$dir/20-write-date-drift/2024-01-15_10-00-00.jpg");
echo "  20 write-date drift          → metadata 157 days from filename\n";

// ============================================================================
// 21 - Non-Apple camera MOV (stores local time as "UTC")
// ============================================================================
mkdir("$dir/21-non-apple-camera", 0755, true);
createVideo("$dir/21-non-apple-camera/MVI_0511.mov");
exiftool(
    '-QuickTime:CreateDate=2024:09:15 14:30:00',
    '-QuickTime:ModifyDate=2024:09:15 14:30:00',
    '-Keys:Make=Panasonic', '-Keys:Model=DMC-GH5',
    "$dir/21-non-apple-camera/MVI_0511.mov",
);
echo "  21 Non-Apple MOV             → [W] ambiguous timezone, local time as UTC\n";

// ============================================================================
// 22 - Cross-directory duplicates (same EXIF in root + sub)
// ============================================================================
mkdir("$dir/22-cross-dir-duplicates", 0755, true);
mkdir("$dir/22-cross-dir-duplicates/backup", 0755, true);
createJpeg("$dir/22-cross-dir-duplicates/original.jpg");
exiftool('-DateTimeOriginal=2024:12:01 09:00:00', '-SubSecTimeOriginal=000', "$dir/22-cross-dir-duplicates/original.jpg");
copy("$dir/22-cross-dir-duplicates/original.jpg", "$dir/22-cross-dir-duplicates/backup/copy.jpg");
echo "  22 cross-dir duplicates      → root [R] canonical, backup/ [D] -duplicate-001\n";

// ============================================================================
// 23 - SubSecTime padding (2-digit vs 3-digit)
// ============================================================================
mkdir("$dir/23-subsec-padding", 0755, true);
// SubSecTime "5" → "500", "50" → "500", "500" → "500" — all the same!
// SubSecTime "5" → "500", "55" → "550" — different targets
createJpeg("$dir/23-subsec-padding/photo-5ms.jpg");
exiftool('-DateTimeOriginal=2024:12:15 10:00:00', '-SubSecTimeOriginal=5', "$dir/23-subsec-padding/photo-5ms.jpg");
createJpeg("$dir/23-subsec-padding/photo-55ms.jpg");
file_put_contents("$dir/23-subsec-padding/photo-55ms.jpg", file_get_contents("$dir/23-subsec-padding/photo-55ms.jpg") . 'different');
exiftool('-DateTimeOriginal=2024:12:15 10:00:00', '-SubSecTimeOriginal=55', "$dir/23-subsec-padding/photo-55ms.jpg");
echo "  23 SubSecTime padding        → -500 vs -550 (different targets from 5 vs 55)\n";

// ============================================================================
// 24 - Cross-directory edits (same timestamp + software, different dirs)
//      Fotostudio scenario: original + retouched versions in subdirectory.
//      All have same EXIF date and software, but different content.
//      Expected: original keeps name, edits get -002, -003 sub-group numbers.
// ============================================================================
mkdir("$dir/24-cross-dir-edits", 0755, true);
mkdir("$dir/24-cross-dir-edits/bearbeitet", 0755, true);
createJpeg("$dir/24-cross-dir-edits/original.jpg");
exiftool(
    '-DateTimeOriginal=2024:07:25 11:27:50', '-SubSecTimeOriginal=100',
    '-Make=NIKON CORPORATION', '-Model=NIKON D100', '-Software=Adobe Photoshop 7.0',
    "$dir/24-cross-dir-edits/original.jpg",
);
createJpeg("$dir/24-cross-dir-edits/bearbeitet/edit-1.jpg");
file_put_contents("$dir/24-cross-dir-edits/bearbeitet/edit-1.jpg", file_get_contents("$dir/24-cross-dir-edits/bearbeitet/edit-1.jpg") . 'edit-version-1');
exiftool(
    '-DateTimeOriginal=2024:07:25 11:27:50', '-SubSecTimeOriginal=100',
    '-Make=NIKON CORPORATION', '-Model=NIKON D100', '-Software=Adobe Photoshop 7.0',
    "$dir/24-cross-dir-edits/bearbeitet/edit-1.jpg",
);
createJpeg("$dir/24-cross-dir-edits/bearbeitet/edit-2.jpg");
file_put_contents("$dir/24-cross-dir-edits/bearbeitet/edit-2.jpg", file_get_contents("$dir/24-cross-dir-edits/bearbeitet/edit-2.jpg") . 'edit-version-2');
exiftool(
    '-DateTimeOriginal=2024:07:25 11:27:50', '-SubSecTimeOriginal=100',
    '-Make=NIKON CORPORATION', '-Model=NIKON D100', '-Software=Adobe Photoshop 7.0',
    "$dir/24-cross-dir-edits/bearbeitet/edit-2.jpg",
);
echo "  24 cross-dir edits           → root canonical, bearbeitet/ gets -002, -003\n";

// ============================================================================
// 25 - Same-directory semantic duplicates (same timestamp + software + dir)
//      iPhone scenario: two JPG captures of same millisecond.
//      Expected: one canonical, other gets -duplicate-001.
// ============================================================================
mkdir("$dir/25-same-dir-semantic-dup", 0755, true);
createJpeg("$dir/25-same-dir-semantic-dup/capture-a.jpg");
exiftool(
    '-DateTimeOriginal=2024:09:21 17:02:07', '-SubSecTimeOriginal=833',
    '-Make=Apple', '-Model=iPhone 13 mini', '-Software=18.0',
    "$dir/25-same-dir-semantic-dup/capture-a.jpg",
);
createJpeg("$dir/25-same-dir-semantic-dup/capture-b.jpg");
file_put_contents("$dir/25-same-dir-semantic-dup/capture-b.jpg", file_get_contents("$dir/25-same-dir-semantic-dup/capture-b.jpg") . 'slightly-different');
exiftool(
    '-DateTimeOriginal=2024:09:21 17:02:07', '-SubSecTimeOriginal=833',
    '-Make=Apple', '-Model=iPhone 13 mini', '-Software=18.0',
    "$dir/25-same-dir-semantic-dup/capture-b.jpg",
);
echo "  25 same-dir semantic dup     → one canonical, other -duplicate-001 (same software+dir)\n";

// ============================================================================
// 26 - Same-directory different software (Photoshop edit of original)
//      Expected: hash sub-grouping → original keeps name, edit gets -002.
// ============================================================================
mkdir("$dir/26-same-dir-diff-software", 0755, true);
createJpeg("$dir/26-same-dir-diff-software/from-camera.jpg");
exiftool(
    '-DateTimeOriginal=2024:11:15 09:30:00', '-SubSecTimeOriginal=450',
    '-Make=Apple', '-Model=iPhone 14 Pro', '-Software=17.2',
    "$dir/26-same-dir-diff-software/from-camera.jpg",
);
createJpeg("$dir/26-same-dir-diff-software/photoshopped.jpg");
file_put_contents("$dir/26-same-dir-diff-software/photoshopped.jpg", file_get_contents("$dir/26-same-dir-diff-software/photoshopped.jpg") . 'photoshop-edit');
exiftool(
    '-DateTimeOriginal=2024:11:15 09:30:00', '-SubSecTimeOriginal=450',
    '-Make=Apple', '-Model=iPhone 14 Pro', '-Software=Adobe Photoshop 2024',
    "$dir/26-same-dir-diff-software/photoshopped.jpg",
);
echo "  26 same-dir diff software    → original keeps name, edit gets -002 (diff software)\n";

// ============================================================================
// 27 - Same-dir semantic duplicates + cross-dir copy
//      Two iPhone captures in root (semantic duplicates) plus a copy in sub/.
//      Expected: root canonical + root -duplicate-001, sub/ keeps unsuffixed.
// ============================================================================
mkdir("$dir/27-semantic-dup-plus-crossdir", 0755, true);
mkdir("$dir/27-semantic-dup-plus-crossdir/backup", 0755, true);
createJpeg("$dir/27-semantic-dup-plus-crossdir/capture-a.jpg");
exiftool(
    '-DateTimeOriginal=2024:09:21 18:15:42', '-SubSecTimeOriginal=617',
    '-Make=Apple', '-Model=iPhone 13 mini', '-Software=18.0',
    "$dir/27-semantic-dup-plus-crossdir/capture-a.jpg",
);
createJpeg("$dir/27-semantic-dup-plus-crossdir/capture-b.jpg");
file_put_contents("$dir/27-semantic-dup-plus-crossdir/capture-b.jpg", file_get_contents("$dir/27-semantic-dup-plus-crossdir/capture-b.jpg") . 'slightly-different');
exiftool(
    '-DateTimeOriginal=2024:09:21 18:15:42', '-SubSecTimeOriginal=617',
    '-Make=Apple', '-Model=iPhone 13 mini', '-Software=18.0',
    "$dir/27-semantic-dup-plus-crossdir/capture-b.jpg",
);
// Copy canonical to subdirectory (same content = same hash)
copy("$dir/27-semantic-dup-plus-crossdir/capture-a.jpg", "$dir/27-semantic-dup-plus-crossdir/backup/copy.jpg");
echo "  27 semantic dup + cross-dir  → root canonical + -duplicate-001, backup/ -duplicate-001\n";

echo "\nDone. Run:\n";
echo "  make run CMD=\"rename:exif test-images --dry-run --list-all\"\n";
echo "  make run CMD=\"rename:write-date test-images --dry-run\"\n";
echo "  make run CMD=\"rename:verify test-images\"\n";
