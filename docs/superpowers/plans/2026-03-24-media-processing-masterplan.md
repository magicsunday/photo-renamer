# Media Processing Masterplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Define and implement a complete, testable specification for how every type of media file is handled — from metadata extraction through renaming, deduplication, and Live Photo pairing — including implementation fixes, performance optimizations, and comprehensive test coverage for ALL possible file relationship types.

**Architecture:** Decision matrix approach — every combination of (format x metadata state x timezone state x duplicate state x LP state) maps to exactly one deterministic outcome. Codified as test fixtures and integration scenarios.

**Tech Stack:** PHP 8.5, PHPUnit 12, imagemeta library, Imagick, exiftool, Symfony Console

---

## Core Design Principles

1. **Directory structure is irrelevant for classification.** Whether files are in the same directory or different directories must NOT affect whether they are classified as duplicate, edit, or independent. The pipeline must produce the same classification regardless of directory layout.

2. **Deterministic outcomes.** Given the same set of files with the same metadata, the pipeline always produces the same result — regardless of processing order, directory nesting, or previous runs.

3. **Idempotency.** Running `rename:exif` twice produces identical results. No file changes name on the second run.

4. **Conservative merging.** When in doubt, prefer sub-grouping (`-002`) over duplicate merging (`-duplicate-001`). False duplicate classification risks data loss at the dedup step; false sub-grouping only produces an extra suffix.

5. **Metadata is the source of truth.** File content determines duplicates, metadata determines names. Never assume content from metadata or vice versa.

---

## Part A: Decision Matrices

### A1. File Format Classification

| Format | Container | Metadata Source | Timezone Model | LP Capable | pHash Capable | write-date |
|--------|-----------|-----------------|----------------|------------|---------------|------------|
| JPG/JPEG | JFIF | EXIF (0x9003, 0x9004, 0x0132) | Local time | Yes (still) | Yes (Imagick) | Yes |
| HEIC/HEIF | ISO BMFF | EXIF embedded in BMFF | Local time (like JPEG) | Yes (still) | Yes (Imagick) | Yes |
| MOV | QuickTime | QuickTime atoms + Keys:CreationDate | **UTC** (ambiguous without offset) | Yes (video) | Yes (ffmpeg) | Yes |
| MP4 | ISO BMFF | QuickTime atoms + Keys:CreationDate | **UTC** (ambiguous without offset) | Rare | Yes (ffmpeg) | Yes |
| M4V | ISO BMFF | QuickTime atoms + Keys:CreationDate | **UTC** (ambiguous) | No | Yes (ffmpeg) | Yes |
| AVI | RIFF | RIFF INFO IDIT | **Local time** | No | Yes (ffmpeg) | **No** |

**AVI Caveat:** `rename:exif` reads AVI dates (IDIT). `rename:write-date` cannot write — ExiftoolWriter has no RIFF support.

### A2. Date Extraction Chain

```
1. temporal->original  → EXIF DateTimeOriginal (0x9003) or RIFF IDIT
2. temporal->create    → EXIF CreateDate (0x9004) or Keys:CreationDate
3. capture->dateTime   → QuickTime CreateDate (UTC atom)
4. EXIF 0x0132         → Fallback (isFallbackDateTime=true → [F])
5. Filename pattern    → write-date only (last resort)
```

### A3. Timezone Decision Matrix

| Has EXIF 0x9003? | Has Keys:CreationDate + offset? | QuickTime container? | Result |
|---|---|---|---|
| Yes | * | * | **Reliable** — local time |
| No | Yes | Yes | **Reliable** — has TZ info |
| No | No | Yes (MOV/MP4/M4V) | **Ambiguous** → [W] |
| No | No | No (JPG/AVI) | **Reliable** — local time assumed |
| * | * | HEIC/HEIF + has EXIF | **Reliable** — BMFF exception |

**`--reason=timezone` caveat:** Uses `setTimezone()` (real UTC conversion). Correct for Apple/DJI. Wrong for cameras storing local-as-UTC (Panasonic, Canon). No auto-detection — user must know their camera.

### A4. File Relationship Taxonomy

**Every possible relationship between two media files and the expected pipeline behavior:**

#### Category A: Byte-Identical (same content hash)

| Type | Example | Naming | Tests |
|------|---------|--------|-------|
| Backup copy | Same file in different dir | `-duplicate-NNN` | #02, #22 |
| Re-import | Same file imported again | `-duplicate-NNN` | #02 |
| Cloud sync (no conversion) | Dropbox/Syncthing | `-duplicate-NNN` | #02 |
| Renamed file | Different name, same bytes | `-duplicate-NNN` | #02 |

#### Category B: Format Conversions (different hash, same visual content)

| Type | dHash | Score | Expected | Stage B | Tests |
|------|-------|-------|----------|---------|-------|
| HEIC↔JPG (same photo) | 0-3 | 96-100 | `-duplicate-NNN` | **Must skip when dHash=0** | #28, NEW #42 |
| MOV↔MP4 (container swap) | 0 | 98-100 | `-duplicate-NNN` | N/A (video) | NEW #43 |
| JPEG quality re-save | 0-2 | 95-100 | `-duplicate-NNN` | Skip when dHash=0 | #25 |
| Video re-encode (same duration) | 1-8 | 90-98 | `-duplicate-NNN` | N/A (video) | NEW #44 |
| Cloud re-compress (metadata kept) | 0-5 | 80-99 | `-duplicate-NNN` or `-002` | Varies | Not testable (needs real cloud export) |
| Metadata-only edit (GPS strip) | 0 | 100 | `-duplicate-NNN` | Skip when dHash=0 | Partial #25 |

**BUG FOUND:** HEIC↔JPG in same directory gets `-002` instead of `-duplicate-001` because Stage B detects compression artifacts as "compact retouch" (0.14% changed area > 0.1% threshold). **Fix: Skip Stage B when dHash distance = 0.**

#### Category C: Intentional Edits (different content, same capture moment)

| Type | dHash | Score | Expected | Stage B | Tests |
|------|-------|-------|----------|---------|-------|
| Color/filter edit | 0-8 | 70-98 | `-002` | No blob → **incorrectly merges subtle filters** | #24, #26 |
| Local retouch (spot, red-eye) | 0-3 | 95-100 | `-002` | **Detects** compact blob | Mock only |
| Crop | 5-30 | 50-85 | `-002` | No blob | Not tested |
| Composite (watermark, text) | 1-15 | 80-97 | `-002` | Detects if score≥95 | Not tested |
| Manual rotation (not EXIF) | 30-64 | 0-40 | `-002` | N/A (score<85) | Not tested |

#### Category D: Video Transformations

| Type | dHash | Score | Expected | Tests |
|------|-------|-------|----------|-------|
| Trimmed (temporal subset) | 5-40 | 50-80 | `-002` (duration mismatch) | Not tested |
| Slow-motion export | 5-30 | 60-85 | `-002` | Not tested |
| Different audio track | 0 | 98-100 | `-duplicate-NNN` **incorrectly** (no audio analysis) | Not tested |

#### Category E: Live Photo Pairs

| Type | Pairing | Naming | Tests |
|------|---------|--------|-------|
| Still + MOV (Content ID match) | Content ID | Same basename | #04 |
| Still + MOV (basename fallback) | Basename | Same basename | LP unit tests |
| Still + MOV (video has ambiguous TZ) | Content ID | Same basename (video inherits still's date) | NEW #40 |
| LP + edited still + duplicate | Content ID + pHash | Original + `-002` + `-duplicate-001` | #29 |

#### Category F: Independent Files (same timestamp by coincidence)

| Type | dHash | Outcome | Tests |
|------|-------|---------|-------|
| Burst photos (different SubSecond) | 5-30 | Different basenames | #03 |
| HDR bracketed exposures | 5-20 | `-002`, `-003` | Not tested |
| Same scene from 2 cameras | 3-25 | Different timestamps usually | N/A |

#### Category G: Files Outside Pipeline Scope

| Type | Pipeline Behavior | Tests |
|------|------------------|-------|
| No EXIF metadata | [S] Skipped | #07 |
| Unsupported format (PNG, RAW, MKV) | Filtered by file iterator | NEW #39 |
| Social media re-download (metadata stripped) | [S] Skipped | #07 |
| Corrupted metadata | [E] Error | Not tested |

### A5. Automatic vs Manual Actions

| Scenario | Auto? | Tool |
|----------|-------|------|
| JPEG/HEIC with DateTimeOriginal | 100% auto | `rename:exif` |
| MOV/MP4/M4V with Keys:CreationDate | 100% auto | `rename:exif` |
| AVI with IDIT | 100% auto | `rename:exif` |
| Byte-identical duplicates | 100% auto | `rename:exif` |
| Format conversions (HEIC↔JPG) | 100% auto | `rename:exif` (after Stage B fix) |
| Live Photo pairs (Content ID or basename) | 100% auto | `rename:exif` |
| LP MOV with ambiguous TZ | 100% auto | `rename:exif` (inherits still's date) |
| Ambiguous timezone videos | Manual: `--timezone` | `rename:write-date --reason=timezone` |
| Fallback date (0x0132 only) | Semi-auto: verify first | `rename:write-date --reason=fallback` |
| Fallback date + `--skip-fallback` | Semi-auto | `rename:exif --skip-fallback` (skips [F] files) |
| No metadata | Manual | `rename:write-date --reason=nodata` |
| Date drift >7 days | Manual: investigate | `rename:verify` |
| AVI metadata fix | Manual: exiftool | External |
| Unsupported format | Not possible | `rename:verify` reports |

### A6. Processing Order (11 Steps)

```
Step 1:  rename:verify ~/Photos
Step 2:  rename:write-date --reason=nodata ~/Photos
Step 3:  rename:write-date --reason=fallback ~/Photos
Step 4:  rename:write-date --reason=timezone --timezone=<tz> ~/Photos
Step 5:  rename:write-date --reason=drift ~/Photos
         ⚠ No-op for files without date in filename. Re-run after Step 7.
Step 6:  rename:exif --dry-run ~/Photos
Step 7:  rename:exif ~/Photos
Step 8:  rename:verify ~/Photos  (confirm all issues resolved)
Step 9:  rename:exif --dry-run ~/Photos  (verify idempotency: 0 changes)
Step 10: rename:dedup --dry-run ~/Photos
         ⚠ Same-directory original search only.
Step 11: rename:dedup ~/Photos
```

---

## Part B: Implementation Fixes

### Fix 1: Skip Stage B when dHash distance = 0

**Bug:** HEIC↔JPG format backup in same directory gets `-002` instead of `-duplicate-001`. Stage B detects JPEG/HEIC compression artifacts (0.14% changed area) as "compact retouch".

**Root cause:** `hasLocalRetouchCached()` runs for all `isDuplicateLikely()` pairs (score ≥ 95). At dHash=0, the images are pixel-identical on the 9×8 gradient grid — any Stage B differences are compression noise, not retouches.

**Fix:** In `HashSubGroupingService::mergePerceptuallySimilarGroups()`, skip Stage B when dHash distance = 0:

```php
if ($result->isDuplicateLikely()) {
    $shouldMerge = ($result->dhashDistance === 0)
        || !$this->hasLocalRetouchCached(...);
}
```

**Files:** `src/Service/HashSubGroupingService.php`
**Tests:** New scenario #42 (same-dir HEIC+JPG format backup)

### Fix 2: Guard AVI in WriteDateCommand

**Bug:** ExiftoolWriter writes QuickTime atoms to AVI RIFF container — silently fails.

**Fix:** Check extension before calling ExiftoolWriter, skip AVI with warning.

**Files:** `src/Command/WriteDateCommand.php`

### Fix 3: hasReliableDateTime second-precision

**Bug:** Comparison uses `Y-m-d H:i` (minutes). File `14:30:22` matches metadata `14:30:00`.

**Fix:** Change to `Y-m-d H:i:s`. Verify that `FileHelper::extractDateTimeFromPath()` returns seconds (it does — pattern `(\d{2})[-_.](\d{2})[-_.](\d{2})` captures H:i:s).

**Files:** `src/Metadata/ExifMetadataProvider.php`

### Fix 4: Dedup cross-directory original search

**Bug:** Dedup checks same directory only. Cross-dir duplicates show as "orphaned".

**Fix:** Search source root for original file.

**Files:** `src/Command/DedupCommand.php`

---

## Part C: Test Scenarios

### Existing (31 scenarios, all passing)

Scenarios 01-31 cover: basic rename, duplicates, hash subgroups, LP pairs, fallback dates, ambiguous TZ, no metadata, date drift, extension normalize, already correct, write-date flows, MP4/HEIC/AVI, LP conflicts, cross-dir duplicates, subsecond, cross-dir edits, semantic duplicates, canonical idempotency, duplicate+ambiguous TZ.

### New Scenarios (32-50)

| # | Scenario | Category | Expected | Dir Layout |
|---|----------|----------|----------|------------|
| 32 | HEIC without EXIF DateTimeOriginal | A3 | [W] (ambiguous) | Single file |
| 33 | MOV with Keys:CreationDate + offset | A3 | [R] (not [W]) | Single file |
| 34 | AVI with RIFF IDIT | A2 | [R] or known limitation | Single file |
| 35 | Fallback date + --skip-fallback | A5 | [F] skipped | Single file |
| 36 | JPG [R] + MOV [W] same timestamp | A3 | [W] doesn't infect [R] | Same dir |
| 37 | write-date timezone → rename:exif | A6 | Fix then rename works | WriteDateFlowTest |
| 38 | write-date nodata → rename:exif | A6 | Fix then rename works | WriteDateFlowTest |
| 39 | JPG + PNG in same dir | G | PNG ignored | Same dir |
| 40 | LP MOV with ambiguous TZ + Content ID | E | Both [R], MOV inherits still's date | Same dir |
| 41 | Metadata cache invalidation after write-date | A6 | New metadata used | WriteDateFlowTest |
| **42** | **HEIC + JPG same-dir format backup** | **B** | **`-duplicate-001` (after Stage B fix)** | **Same dir** |
| **43** | **HEIC + JPG cross-dir format backup** | **B** | **`-duplicate-001`** | **Cross dir** |
| **44** | **Same-dir edits (original + edit)** | **C** | **`-002`** | **Same dir** |
| **45** | **Same-dir edit + cross-dir backup** | **C+A** | **Original + `-002` + `-duplicate-001`** | **Mixed** |
| **46** | **Video trimmed (different duration)** | **D** | **`-002` (duration mismatch)** | **Same dir** |
| **47** | **Dedup cross-directory originals** | **A6** | **Original found in root** | **Cross dir** |
| **48** | **HDR bracketed exposures (same second)** | **F** | **`-002`, `-003`** | **Same dir** |
| **49** | **HEIF extension variant** | **A1** | **Same as HEIC** | **Single file** |
| **50** | **Idempotency multi-format** | **Principle 3** | **0 changes on 2nd run** | **Mixed** |

### Test Infrastructure

**Existing:** `TestImageScenariosTest::scenarioProvider()` — single dry-run, output mapping.

**New:** `WriteDateFlowTest` — multi-step workflows (write-date → rename:exif). Uses real exiftool.

**New test method in TestImageScenariosTest:** `testSkipFallbackOption()` for scenario 35. `testIdempotencyAcrossFormats()` for scenario 50.

---

## Part D: Performance Optimizations

### Perf 1: Video frame extraction 5 → 3 frames

**Files:** `src/Service/PerceptualHash/ImagickImageLoader.php`
**Change:** Frames at [25%, 50%, 75%] instead of [10%, 30%, 50%, 70%, 90%]. Timeout 20s → 5s.
**Impact:** ~40% reduction in video processing time.

### Perf 2: dHash early-exit threshold 20 → 16

**Files:** `src/Service/PerceptualHash/PerceptualHashCalculator.php`
**Change:** Exit at dd>16 instead of dd>20.
**Impact:** ~15% fewer full-signal comparisons.

### Perf 3: Stage B resolution 1024 → 512

**Files:** `src/Service/HashSubGroupingService.php`
**Change:** `loadNormalized($file, 512)` — matches `LocalDifferenceAnalyzer::WORK_SIZE`.
**Impact:** ~50% less Imagick memory for Stage B.

---

## Part E: Known Limitations

| Limitation | Can't Fix Because | Workaround |
|------------|------------------|------------|
| PNG/WebP | No EXIF metadata | Convert to JPEG |
| MKV/WebM | imagemeta doesn't parse | Convert to MP4 |
| Nikon AVI MakerNotes | imagemeta#2289 | Wait for upstream |
| AVI metadata writing | RIFF ≠ QuickTime atoms | Use exiftool directly |
| Non-Apple camera TZ | Can't detect camera type | User verifies per camera |
| Videos with different audio | No audio analysis | Incorrectly merged as duplicate |
| Subtle global color filters | Stage B finds no blob, score≥95 → merged | May produce false duplicates |
| HEIC↔JPG with dHash 1-2 | Fix 1 only skips Stage B at dHash=0; dHash 1-2 still triggers Stage B | Rare; conservative sub-grouping per Principle 4 |
| Cloud re-compress + metadata strip | No EXIF = can't group | `rename:write-date --reason=nodata` after manual naming |
| GPS → Timezone | Requires TZ database | User supplies `--timezone` |
| DNG/TIFF | Untested in imagemeta | Test and add to extensions |

---

## Part F: Execution Order

```
Task 1: Fix 1 — Stage B skip when dHash=0 (blocks scenario 42/43)
Task 2: Fix 2 — AVI guard in WriteDateCommand
Task 3: Fix 3 — hasReliableDateTime second-precision
Task 4: Fix 4 — Dedup cross-directory search
Task 5: Test scenarios 32-50 (TDD: test first, then verify/fix)
Task 6: Performance optimizations (3a, 3b, 3c)
Task 7: Decision matrix documentation
Task 8: README workflow update
Task 9: Idempotency validation (scenario 50)
```

All tasks use TDD: failing test first, then implementation, then commit.
