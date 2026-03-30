<?php

/**
 * Creates test images/videos with metadata for validating all renamer scenarios.
 * Run: docker compose run --rm buildbox php scripts/create-test-images.php
 */

declare(strict_types=1);

$dir = __DIR__ . '/../tests/Fixtures/Images';

// Backup committed Live Photo test files before wiping — they contain real
// iPhone ContentIdentifier metadata that cannot be synthesized.
$backupDir = sys_get_temp_dir() . '/renamer-test-images-backup-' . uniqid();
$backupFiles = [
    '04-live-photo-pair/IMG_0001.jpg',
    '04-live-photo-pair/IMG_0001.mov',
    '18-live-photo-conflict/2024-08-19_11-09-34-857.jpg',
    '18-live-photo-conflict/2024-08-19_11-09-34-857.mov',
    '29-livephoto-edit-duplicate/2025-05-03_14-38-16-939.jpg',
    '29-livephoto-edit-duplicate/2025-05-03_14-38-16-939.mov',
    '29-livephoto-edit-duplicate/2025-05-03_14-38-16-939-002.jpg',
    '29-livephoto-edit-duplicate/2025-05-03_14-38-16-939-002.mov',
    '29-livephoto-edit-duplicate/2025-05-03_14-38-16-939-duplicate-001.heic',
    '29-livephoto-edit-duplicate/2025-05-03_14-38-16-939-duplicate-001.mov',
];

if (is_dir($dir)) {
    foreach ($backupFiles as $relPath) {
        $src = $dir . '/' . $relPath;

        if (is_file($src)) {
            $dest = $backupDir . '/' . $relPath;
            @mkdir(dirname($dest), 0755, true);
            copy($src, $dest);
        }
    }

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

    $ext = strtolower(pathinfo($dest, PATHINFO_EXTENSION));

    if ($isVideo) {
        // Create minimal MOV, then copy metadata from real file
        createVideo($dest);
    } elseif (in_array($ext, ['heic', 'heif'], true)) {
        // Create minimal HEIC via heif-enc, then copy metadata from real file
        createHeic($dest);
    } else {
        // Create 1x1 JPEG via ffmpeg, then copy metadata from real file
        exec(sprintf('ffmpeg -y -f lavfi -i color=white:s=1x1 -frames:v 1 %s 2>/dev/null', escapeshellarg($dest)));
    }

    exec(sprintf(
        'exiftool -overwrite_original -TagsFromFile %s -all:all %s 2>/dev/null',
        escapeshellarg($source),
        escapeshellarg($dest),
    ));

    // Remove GPS data — real coordinates must not be committed to the repo
    exec(sprintf('exiftool -overwrite_original -GPS*= %s 2>/dev/null', escapeshellarg($dest)));
}

function exiftool(string ...$args): void
{
    $cmd = array_map('escapeshellarg', ['exiftool', '-overwrite_original', ...$args]);
    exec(implode(' ', $cmd) . ' 2>/dev/null');
}

echo "Creating test images in tests/Fixtures/Images/...\n\n";

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
// Requires real iPhone Live Photo metadata (ContentIdentifier) that cannot be
// synthesized. On first generation, provide source via PHOTO_SOURCE env var.
// On subsequent runs, re-uses the already-committed test-images as source.
mkdir("$dir/04-live-photo-pair", 0755, true);
$photoSource = getenv('PHOTO_SOURCE') ?: '';

if ($photoSource !== '') {
    $lpSource = $photoSource . '/04-live-photo-pair/IMG_0001';
} else {
    $lpSource = $backupDir . '/04-live-photo-pair/IMG_0001';
}
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
// Requires real iPhone metadata with mismatched ContentIdentifiers.
// On first generation, provide source via PHOTO_SOURCE env var.
// On subsequent runs, re-uses the already-committed test-images as source.
mkdir("$dir/18-live-photo-conflict", 0755, true);
if ($photoSource !== '') {
    $lpConflictSrc = $photoSource . '/18-live-photo-conflict/2024-08-19_11-09-34-857';
} else {
    $lpConflictSrc = $backupDir . '/18-live-photo-conflict/2024-08-19_11-09-34-857';
}

stripToMetadataOnly("$lpConflictSrc.jpg", "$dir/18-live-photo-conflict/2024-08-19_11-09-34-857.jpg");
stripToMetadataOnly("$lpConflictSrc.mov", "$dir/18-live-photo-conflict/2024-08-19_11-09-34-857.mov", true);
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
//      Photo studio scenario: original + retouched versions in subdirectory.
//      All have same EXIF date and software, but different content.
//      Expected: original keeps name, edits get -002, -003 sub-group numbers.
// ============================================================================
mkdir("$dir/24-cross-dir-edits", 0755, true);
mkdir("$dir/24-cross-dir-edits/edited", 0755, true);
// Use visually DIFFERENT images (different colors) so perceptual hashing sees them as distinct
// Use visually DISTINCT patterns (not solid colors — dHash needs gradient differences)
exec(sprintf('ffmpeg -y -f lavfi -i testsrc=s=64x64:rate=1 -frames:v 1 %s 2>/dev/null', escapeshellarg("$dir/24-cross-dir-edits/original.jpg")));
exiftool(
    '-DateTimeOriginal=2024:07:25 11:27:50', '-SubSecTimeOriginal=100',
    '-Make=NIKON CORPORATION', '-Model=NIKON D100', '-Software=Adobe Photoshop 7.0',
    "$dir/24-cross-dir-edits/original.jpg",
);
// Edit 1: horizontally flipped pattern (different gradient = different dHash)
exec(sprintf('ffmpeg -y -f lavfi -i testsrc=s=64x64:rate=1 -vf hflip -frames:v 1 %s 2>/dev/null', escapeshellarg("$dir/24-cross-dir-edits/edited/edit-1.jpg")));
exiftool(
    '-DateTimeOriginal=2024:07:25 11:27:50', '-SubSecTimeOriginal=100',
    '-Make=NIKON CORPORATION', '-Model=NIKON D100', '-Software=Adobe Photoshop 7.0',
    "$dir/24-cross-dir-edits/edited/edit-1.jpg",
);
// Edit 2: inverted pattern (negative = different dHash)
exec(sprintf('ffmpeg -y -f lavfi -i testsrc=s=64x64:rate=1 -vf negate -frames:v 1 %s 2>/dev/null', escapeshellarg("$dir/24-cross-dir-edits/edited/edit-2.jpg")));
exiftool(
    '-DateTimeOriginal=2024:07:25 11:27:50', '-SubSecTimeOriginal=100',
    '-Make=NIKON CORPORATION', '-Model=NIKON D100', '-Software=Adobe Photoshop 7.0',
    "$dir/24-cross-dir-edits/edited/edit-2.jpg",
);
echo "  24 cross-dir edits           → root canonical, edited/ gets -002, -003\n";

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
// Use visually DIFFERENT images so perceptual hashing sees them as distinct
// Use visually DISTINCT patterns (dHash needs gradient differences, not just color)
exec(sprintf('ffmpeg -y -f lavfi -i smptebars=s=64x64:rate=1 -frames:v 1 %s 2>/dev/null', escapeshellarg("$dir/26-same-dir-diff-software/from-camera.jpg")));
exiftool(
    '-DateTimeOriginal=2024:11:15 09:30:00', '-SubSecTimeOriginal=450',
    '-Make=Apple', '-Model=iPhone 14 Pro', '-Software=17.2',
    "$dir/26-same-dir-diff-software/from-camera.jpg",
);
// Edit: vertically flipped pattern (different gradient = different dHash)
exec(sprintf('ffmpeg -y -f lavfi -i smptebars=s=64x64:rate=1 -vf vflip -frames:v 1 %s 2>/dev/null', escapeshellarg("$dir/26-same-dir-diff-software/photoshopped.jpg")));
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

// ============================================================================
// 28 - Cross-directory format backup (JPG in root, HEIC in subdir)
//      Same capture, different format, different directories.
//      Expected: semantic duplicate (format backup), not sub-grouped.
// ============================================================================
mkdir("$dir/28-cross-dir-format-backup", 0755, true);
mkdir("$dir/28-cross-dir-format-backup/backup", 0755, true);
createJpeg("$dir/28-cross-dir-format-backup/photo.jpg");
exiftool(
    '-DateTimeOriginal=2025:11:15 20:26:50', '-SubSecTimeOriginal=647',
    '-Make=Apple', '-Model=iPhone 16 Pro', '-Software=18.1',
    "$dir/28-cross-dir-format-backup/photo.jpg",
);
createHeic("$dir/28-cross-dir-format-backup/backup/photo.heic");
exiftool(
    '-DateTimeOriginal=2025:11:15 20:26:50', '-SubSecTimeOriginal=647',
    '-Make=Apple', '-Model=iPhone 16 Pro', '-Software=18.1',
    "$dir/28-cross-dir-format-backup/backup/photo.heic",
);
echo "  28 cross-dir format backup   → JPG canonical, HEIC gets -duplicate-001 (format backup)\n";

// ============================================================================
// 29 - Complex Live Photo group: original + edit + duplicate
//      Real iPhone Live Photo metadata (ContentIdentifier, LivePhotoVideoIndex).
//      6 files: JPG+MOV (original), JPG+MOV (edited), HEIC+MOV (duplicate).
//      Expected: .jpg/.mov canonical, -002.jpg/-002.mov edit, -duplicate-001.heic/
//      -duplicate-001.mov duplicate.
// ============================================================================
mkdir("$dir/29-livephoto-edit-duplicate", 0755, true);

if ($photoSource !== '') {
    $lp29src = $photoSource . '/29-livephoto-edit-duplicate/2025-05-03_14-38-16-939';
} else {
    $lp29src = $backupDir . '/29-livephoto-edit-duplicate/2025-05-03_14-38-16-939';
}

$lp29files = [
    ''                  => '.jpg',
    ''                  => '.mov',
    '-002'              => '.jpg',
    '-002'              => '.mov',
    '-duplicate-001'    => '.heic',
    '-duplicate-001'    => '.mov',
];

// Can't use same key twice in PHP array — use list instead
$lp29pairs = [
    ['', '.jpg'],
    ['', '.mov'],
    ['-002', '.jpg'],
    ['-002', '.mov'],
    ['-duplicate-001', '.heic'],
    ['-duplicate-001', '.mov'],
];

// Create visually distinct dummy images with real metadata copied on top.
// Original and duplicate are same color (red) → pHash merge.
// Edit is different color (blue) → pHash separate.
// Use ffmpeg test sources that produce different dHash values:
// - 'testsrc' for original (test pattern with text)
// - 'testsrc' + hflip for edit (different gradient = different dHash)
// - 'testsrc' for duplicate (same gradient as original)
$lp29colors = [
    ['' , '.jpg', 'testsrc=s=64x64:rate=1'],                  // Original JPG
    ['', '.mov', null],                                         // Original MOV (video)
    ['-002', '.jpg', 'testsrc=s=64x64:rate=1,hflip'],         // Edited JPG (flipped = different dHash)
    ['-002', '.mov', null],                                     // Edited MOV (video)
    ['-duplicate-001', '.heic', 'testsrc=s=64x64:rate=1'],    // Duplicate HEIC (same visual as original)
    ['-duplicate-001', '.mov', null],                           // Duplicate MOV (video)
];

foreach ($lp29colors as [$suffix, $ext, $pattern]) {
    $srcFile  = $lp29src . $suffix . $ext;
    $destFile = $dir . '/29-livephoto-edit-duplicate/2025-05-03_14-38-16-939' . $suffix . $ext;

    if ($ext === '.mov') {
        // Video: create dummy MOV, copy metadata from real file
        stripToMetadataOnly($srcFile, $destFile, true);
    } elseif ($ext === '.heic') {
        // HEIC: create patterned HEIC dummy, copy metadata
        $tmpJpeg = sys_get_temp_dir() . '/heic-pattern-' . uniqid() . '.jpg';
        exec(sprintf('ffmpeg -y -f lavfi -i %s -frames:v 1 %s 2>/dev/null', escapeshellarg($pattern), escapeshellarg($tmpJpeg)));
        exec(sprintf('heif-enc -o %s %s 2>/dev/null', escapeshellarg($destFile), escapeshellarg($tmpJpeg)));
        @unlink($tmpJpeg);

        if (file_exists($srcFile)) {
            exec(sprintf('exiftool -overwrite_original -TagsFromFile %s -all:all %s 2>/dev/null', escapeshellarg($srcFile), escapeshellarg($destFile)));
            exec(sprintf('exiftool -overwrite_original -GPS*= %s 2>/dev/null', escapeshellarg($destFile)));
        }
    } else {
        // JPG: create patterned dummy, copy metadata
        exec(sprintf('ffmpeg -y -f lavfi -i %s -frames:v 1 %s 2>/dev/null', escapeshellarg($pattern), escapeshellarg($destFile)));

        if (file_exists($srcFile)) {
            exec(sprintf('exiftool -overwrite_original -TagsFromFile %s -all:all %s 2>/dev/null', escapeshellarg($srcFile), escapeshellarg($destFile)));
            exec(sprintf('exiftool -overwrite_original -GPS*= %s 2>/dev/null', escapeshellarg($destFile)));
        }
    }
}

echo "  29 LP edit+duplicate         → .jpg/.mov canonical, -002 edit, -duplicate-001 dup\n";

// ============================================================================
// 30 - Cross-directory canonical idempotency
//      Root has a file with -duplicate-001 suffix, subdirectory has the
//      canonical (unsuffixed) name. Both are identical content.
//      Expected: subdirectory file keeps canonical name, root keeps suffix.
//      Bug scenario: root file processed first → wrongly "wins" canonical.
// ============================================================================
mkdir("$dir/30-cross-dir-canonical-idempotent", 0755, true);
mkdir("$dir/30-cross-dir-canonical-idempotent/album", 0755, true);
createJpeg("$dir/30-cross-dir-canonical-idempotent/2024-08-10_13-22-05-300-duplicate-001.jpg");
exiftool(
    '-DateTimeOriginal=2024:08:10 13:22:05', '-SubSecTimeOriginal=300',
    '-Make=Apple', '-Model=iPhone 14 Pro', '-Software=17.0',
    "$dir/30-cross-dir-canonical-idempotent/2024-08-10_13-22-05-300-duplicate-001.jpg",
);
copy(
    "$dir/30-cross-dir-canonical-idempotent/2024-08-10_13-22-05-300-duplicate-001.jpg",
    "$dir/30-cross-dir-canonical-idempotent/album/2024-08-10_13-22-05-300.jpg",
);
echo "  30 cross-dir canonical idem. → subdir keeps canonical, root keeps -duplicate-001\n";

// ============================================================================
// 31 - Duplicate with ambiguous timezone (Warning takes priority over Duplicate)
//      Two identical MP4 videos with UTC timestamps and no timezone offset.
//      Both should be flagged [W], even the one that would get a -duplicate suffix.
//      Expected: canonical [W] (skipped), duplicate [W] (skipped, not [D]).
// ============================================================================
mkdir("$dir/31-duplicate-ambiguous-tz", 0755, true);
createVideo("$dir/31-duplicate-ambiguous-tz/clip-a.mp4", 'mp4');
exiftool('-QuickTime:CreateDate=2025:06:10 14:30:00', "$dir/31-duplicate-ambiguous-tz/clip-a.mp4");
copy("$dir/31-duplicate-ambiguous-tz/clip-a.mp4", "$dir/31-duplicate-ambiguous-tz/clip-b.mp4");
echo "  31 duplicate + ambiguous tz  → both [W] skipped (not [D])\n";

// ============================================================================
// 42 - Same-directory format backup (HEIC + JPG, same photo)
//      HEIC original + JPG conversion in the same directory.
//      Both have identical visual content but different content hashes.
//      Expected: JPG gets -duplicate-001 (format backup = duplicate, not edit).
//      This tests Fix 1: Stage B must skip when dHash distance = 0.
// ============================================================================
mkdir("$dir/42-same-dir-format-backup", 0755, true);
// Create a textured JPEG (mandelbrot pattern — produces compression artifacts
// that differ between JPEG and HEIC, triggering the Stage B false positive)
exec("ffmpeg -y -f lavfi -i mandelbrot=s=256x256:maxiter=100:rate=1 -frames:v 1 $dir/42-same-dir-format-backup/photo.jpg 2>/dev/null");
exiftool(
    '-DateTimeOriginal=2025:02:20 15:30:00', '-SubSecTimeOriginal=200',
    '-Make=Apple', '-Model=iPhone 15 Pro', '-Software=17.2',
    "$dir/42-same-dir-format-backup/photo.jpg",
);
// Create HEIC from the same source image — same visual content, different codec
$tmpJpeg42 = sys_get_temp_dir() . '/heic-42-' . uniqid() . '.jpg';
exec("ffmpeg -y -f lavfi -i mandelbrot=s=256x256:maxiter=100:rate=1 -frames:v 1 $tmpJpeg42 2>/dev/null");
exec("heif-enc -o $dir/42-same-dir-format-backup/photo.heic $tmpJpeg42 2>/dev/null");
@unlink($tmpJpeg42);
exiftool(
    '-DateTimeOriginal=2025:02:20 15:30:00', '-SubSecTimeOriginal=200',
    '-Make=Apple', '-Model=iPhone 15 Pro', '-Software=17.2',
    "$dir/42-same-dir-format-backup/photo.heic",
);
echo "  42 same-dir format backup    → HEIC canonical, JPG -duplicate-001\n";

// Clean up backup of committed Live Photo files
if (is_dir($backupDir)) {
    exec('rm -rf ' . escapeshellarg($backupDir));
}

echo "\nDone. Run:\n";
echo "  make run CMD=\"rename:exif tests/Fixtures/Images --dry-run --list-all\"\n";
echo "  make run CMD=\"rename:write-date tests/Fixtures/Images --dry-run\"\n";
echo "  make run CMD=\"rename:verify tests/Fixtures/Images\"\n";
