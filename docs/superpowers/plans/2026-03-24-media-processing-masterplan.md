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

6. **Dry-run is read-only.** `--dry-run` must NOT alter pipeline logic, state, or cached data. It prevents only the actual file rename. The output must accurately reflect what a real run would do — including occupied-path tracking and collision detection.

7. **Filenames are unreliable date sources.** Camera imports produce names like `IMG_1234.jpg`, `MOV_5678.mov`, `DSCF0001.jpg` — no date information. Only after `rename:exif` has run do filenames contain reliable dates. Therefore: `rename:write-date` with filename-as-source (`--reason=nodata/fallback/drift`) is only meaningful AFTER at least one `rename:exif` pass, or when the user has manually named files with dates.

8. **No logic duplication across commands.** Commands sharing the same logic (metadata extraction, timezone detection, date drift, duplicate grouping) must use shared services and traits. Never copy-paste pipeline logic between commands.

9. **Safety confirmation before destructive operations.** Commands that modify files (`rename:exif`, `rename:write-date`, `rename:dedup`) must prompt for user confirmation before executing changes (unless `--dry-run`). Currently missing in `rename:write-date`.

10. **Actionable guidance for unresolvable cases.** When a file is skipped (`[W]`, `[S]`, `[E]`, `[C]`), the output must explain WHY and HOW to fix it. For complex cases, `rename:verify` should provide per-file analysis with specific recommendations (e.g. "use `rename:write-date --reason=timezone --timezone=Europe/Berlin` to fix this file").

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

### A6. Processing Order (12 Steps)

```
Phase 1: Fix metadata BEFORE renaming
Step 1:   rename:verify ~/Photos
          → Understand the collection: what problems exist
Step 2:   rename:write-date --reason=nodata ~/Photos
          → Fix files with NO metadata. Only useful if filenames already
            contain dates (previous rename:exif run or manual naming).
            Camera originals like IMG_1234.jpg have no date to extract.
Step 3:   rename:write-date --reason=fallback ~/Photos
          → Fix files using only ModifyDate (0x0132)
Step 4:   rename:write-date --reason=timezone --timezone=<tz> ~/Photos
          → Fix QuickTime videos with ambiguous UTC

Phase 2: Rename
Step 5:   rename:exif --dry-run ~/Photos  → Preview
Step 6:   rename:exif ~/Photos            → Execute (prompts for confirmation)

Phase 3: Post-rename verification and drift fix
Step 7:   rename:verify ~/Photos
          → Confirm all metadata issues resolved after Phase 1+2
Step 8:   rename:write-date --reason=drift ~/Photos
          → NOW filenames contain reliable dates (from Step 6).
            Detect and fix metadata that disagrees with the filename.
Step 9:   rename:exif ~/Photos
          → Re-run to apply drift fixes. Minimal changes expected.

Phase 4: Final verification
Step 10:  rename:exif --dry-run ~/Photos
          → Verify idempotency (must show 0 changes)

Phase 5: Cleanup
Step 11:  rename:dedup --dry-run ~/Photos   → Preview
Step 12:  rename:dedup ~/Photos             → Execute (prompts for confirmation)
```

**Key insight (Principle 7):** `--reason=drift` is placed AFTER `rename:exif` because drift detection compares filename dates with metadata dates. On fresh collections with camera names (`IMG_1234.jpg`), there is no filename date to compare. Only after `rename:exif` produces date-based names does drift detection work.

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

### Fix 5: Add safety confirmation to rename:write-date (Principle 9)

**Bug:** `rename:write-date` modifies file metadata without confirmation prompt. `rename:exif` and `rename:dedup` already prompt. Inconsistent safety behavior.

**Fix:** Add `$io->confirm()` before executing writes (same pattern as `AbstractRenameCommand::execute()`).

**Files:** `src/Command/WriteDateCommand.php`

### Fix 6: Actionable guidance in skip reasons (Principle 10)

**Current:** `[W]` shows "Ambiguous timezone: QuickTime UTC without offset — use --timezone or rename:write-date --reason=timezone"

**Enhancement:** `rename:verify` should provide per-file recommendations. When a file is flagged, the verify output should show:
- What the problem is (e.g. "No timezone offset in Keys:CreationDate")
- What the raw metadata contains (e.g. "CreateDate: 2025-04-03 16:50:50 UTC")
- Exact command to fix it (e.g. `rename:write-date --reason=timezone --timezone=Europe/Berlin '/path/to/file.mp4'`)

**Files:** `src/Command/VerifyCommand.php`

### Fix 7: Shared pipeline logic audit (Principle 8)

**Audit task:** Verify that all commands sharing logic use the same service methods:
- `ConfiguresMetadataProvider` trait: timezone resolution, cache dir, max-date-drift → used by rename:exif, verify, write-date ✓
- `FileSystemService::collectFiles()` vs `AbstractRenameCommand::createFileIterator()` → two different patterns for the same thing. Consolidate?
- `hasReliableDateTime()` is called from both `DuplicateDetectionService` and `RenameOutputRenderer` → verify same code path
- Date extraction from filename: `FileHelper::extractDateTimeFromPath()` used by write-date AND by `hasReliableDateTime()` → same logic ✓

**Files:** Multiple — audit only, no code changes unless duplication found.

---

## Part C: Complete Test Scenario Registry

**Single source of truth for all scenarios.** Every scenario maps to a taxonomy category and expected behavior.

### Existing Scenarios (01-31, all passing)

| # | Name | Taxonomy Cat. | What it tests | Expected |
|---|------|--------------|---------------|----------|
| 01 | basic-rename | — | Single JPEG with EXIF → rename | [R] |
| 02 | duplicates | A (byte-identical) | Two files, same date, same hash | `-duplicate-001` |
| 03 | hash-subgroups | F (burst) | Two files, same date, different hash | Different basenames |
| 04 | live-photo-pair | E (LP Content ID) | JPG+MOV with Content ID | Same basename |
| 05 | fallback-date | A2 (fallback) | File with only 0x0132 tag | [F] |
| 06 | ambiguous-timezone | A3 (ambiguous) | MOV without timezone → skipped | [W] |
| 07 | no-metadata | G (no data) | No EXIF at all | [S] |
| 08 | date-drift | A3 (drift) | Metadata >7 days from filename | [W] |
| 09 | extension-normalize | — | .JPEG → .jpg | [R] |
| 10 | already-correct | Principle 3 | File already named correctly | [O] |
| 11 | write-date-nodata | G (no data) | write-date: no metadata to read | [S] |
| 12 | write-date-timezone | A3 (ambiguous) | Video with ambiguous TZ → [W] | [W] |
| 13 | mp4-with-tz | A3 (reliable) | MP4 with explicit timezone | [R] |
| 14 | heic-image | A1 (HEIC+EXIF) | HEIC with EXIF DateTimeOriginal | [R] |
| 15 | epoch-zero | G (invalid) | Zero/invalid timestamp | [S] |
| 16 | reexport-drift | A3 (ambiguous) | Video re-export, ambiguous TZ | [W] |
| 17 | date-only-filename | A3 (ambiguous) | Video with date-only name, ambiguous TZ | [W] |
| 18 | live-photo-conflict | E (LP conflict) | Incompatible still/video pair | [C]+[W] |
| 19 | write-date-fallback | A2 (fallback) | Write date from filename to 0x0132 file | [F] |
| 20 | write-date-drift | A3 (drift) | Filename >7 days from metadata | [W] |
| 21 | non-apple-camera | A3 (ambiguous) | Android camera MOV, ambiguous TZ | [W] |
| 22 | cross-dir-duplicates | A (byte-identical) | Backup copy in subdirectory | `-duplicate-001` |
| 23 | subsec-padding | — | Files with millisecond precision | Correct padding |
| 24 | cross-dir-edits | C (color edit) | Original + 2 edits in subdir | `-002`, `-003` |
| 25 | same-dir-semantic-dup | B (re-save) | Same date, different encoding | `-duplicate-001` |
| 26 | same-dir-diff-software | C (edit) | Same date, different software | `-002` |
| 27 | semantic-dup-plus-crossdir | B+A | Semantic dup + cross-dir backup | `-duplicate-001`, `-002` |
| 28 | cross-dir-format-backup | B (HEIC↔JPG) | JPG root + HEIC subdir | `-duplicate-001` |
| 29 | livephoto-edit-duplicate | E+C+A | LP original + edit + backup (6 files) | Complex |
| 30 | cross-dir-canonical-idempotent | Principle 1+3 | Root has `-duplicate-001`, subdir has canonical | Both [O] |
| 31 | duplicate-ambiguous-tz | A3+A (duplicate) | Two videos, ambiguous TZ → both [W] | Both [W] |

### New Scenarios (32-49)

| # | Name | Taxonomy Cat. | What it tests | Expected | Dir Layout |
|---|------|--------------|---------------|----------|------------|
| 32 | heic-without-exif | A3 | HEIC with only QuickTime CreateDate, no EXIF | [W] (ambiguous) | Single |
| 33 | mov-with-timezone | A3 | MOV with Keys:CreationDate + offset | [R] not [W] | Single |
| 34 | avi-with-idit | A2 | AVI with RIFF IDIT date | [R] or limitation | Single |
| 35 | skip-fallback-option | A5 | `--skip-fallback` skips [F] files | Separate test method | Single |
| 36 | mixed-warning-normal | A3 | JPG [R] + MOV [W] same timestamp | [W] doesn't infect [R] | Same dir |
| 37 | write-date-timezone-flow | A6 | write-date TZ → rename:exif (2-step) | WriteDateFlowTest | Single |
| 38 | write-date-nodata-flow | A6 | write-date nodata → rename:exif | WriteDateFlowTest | Single |
| 39 | unsupported-format-skipped | G | JPG + PNG in same dir | PNG ignored | Same dir |
| 40 | lp-mov-ambiguous-tz | E | LP MOV ambiguous TZ + Content ID | Both [R] | Same dir |
| 41 | cache-invalidation | A6 | Cache miss after write-date | WriteDateFlowTest | Single |
| **42** | **same-dir-format-backup** | **B (HEIC↔JPG)** | **HEIC+JPG same dir, same photo** | **`-duplicate-001` (Fix 1)** | **Same dir** |
| 43 | ~~cross-dir-format-backup~~ | ~~B~~ | **DUPLICATE OF #28 — REMOVED** | — | — |
| 44 | same-dir-edit | C (edit) | Original + edit in same dir | `-002` | Same dir |
| 45 | edit-plus-backup | C+A | Original + edit + backup copy | `-002` + `-duplicate-001` | Mixed |
| 46 | video-trimmed | D (trim) | Same video, different duration | `-002` | Same dir |
| 47 | dedup-cross-dir | Fix 4 | Dedup finds original in root | Not orphaned | Cross dir |
| 48 | hdr-bracketed | F (HDR) | 3 exposures, same second | `-002`, `-003` | Same dir |
| 49 | idempotency-multi-format | Principle 3 | JPG+HEIC+MOV+AVI, 2nd run = 0 changes | All [O] | Mixed |

**Removed:** #43 (duplicate of existing #28), #49-HEIF (HEIF is functionally identical to HEIC and shares code path — a separate scenario adds no value), renumbered #50→#49.

### Test Infrastructure

| Infrastructure | Scenarios | Pattern |
|---------------|-----------|---------|
| `scenarioProvider()` | 01-34, 36, 39-42, 44-46, 48 | Single dry-run, output mapping |
| `WriteDateFlowTest` (new class) | 37, 38, 41 | Multi-step: write-date → rename:exif |
| `testSkipFallbackOption()` | 35 | Custom method with `--skip-fallback` |
| `testIdempotencyAcrossFormats()` | 49 | Real rename → dry-run → 0 changes |
| `testDedupCrossDirectory()` | 47 | Test rename:dedup command |

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
Phase A: Implementation Fixes (TDD per fix)
Task 1:  Fix 1 — Stage B skip when dHash=0 (blocks scenario 42)
Task 2:  Fix 2 — AVI guard in WriteDateCommand
Task 3:  Fix 3 — hasReliableDateTime second-precision
Task 4:  Fix 4 — Dedup cross-directory search
Task 5:  Fix 5 — Safety confirmation in rename:write-date
Task 6:  Fix 7 — Shared pipeline logic audit

Phase B: Test Scenarios (TDD per scenario)
Task 7:  Scenarios 32-42, 44-46, 48 (scenarioProvider)
Task 8:  Scenarios 37, 38, 41 (WriteDateFlowTest class)
Task 9:  Scenario 35 (testSkipFallbackOption)
Task 10: Scenario 47 (testDedupCrossDirectory)
Task 11: Scenario 49 (testIdempotencyAcrossFormats)

Phase C: Performance
Task 12: Perf 1 — Video frames 5→3
Task 13: Perf 2 — dHash threshold 20→16
Task 14: Perf 3 — Stage B resolution 1024→512

Phase D: Documentation
Task 15: Decision matrix docs
Task 16: README workflow update (12 steps)
Task 17: Fix 6 — Actionable verify guidance
```

All tasks use TDD: failing test first, then implementation, then commit.
