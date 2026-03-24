# Media Processing Masterplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Define and implement a complete, testable specification for how every type of media file is handled — from metadata extraction through renaming, deduplication, and Live Photo pairing — including implementation fixes, performance optimizations, and comprehensive test coverage.

**Architecture:** Decision matrix approach — every combination of (format x metadata state x timezone state x duplicate state x LP state) maps to exactly one deterministic outcome. Codified as test fixtures and integration scenarios.

**Tech Stack:** PHP 8.5, PHPUnit 12, imagemeta library, Imagick, exiftool, Symfony Console

---

## Part A: Complete Decision Matrix

### A1. File Format Classification

| Format | Container | Metadata Source | Timezone Model | LP Capable | pHash Capable | write-date Support |
|--------|-----------|-----------------|----------------|------------|---------------|-------------------|
| JPG/JPEG | JFIF | EXIF (0x9003, 0x9004, 0x0132) | Local time (no TZ concept) | Yes (still) | Yes (Imagick) | Yes (EXIF tags) |
| HEIC/HEIF | ISO BMFF | EXIF embedded in BMFF | Local time (like JPEG) | Yes (still) | Yes (Imagick) | Yes (EXIF tags) |
| MOV | QuickTime | QuickTime atoms + Keys:CreationDate | **UTC** (ambiguous without Keys:CreationDate) | Yes (video companion) | Yes (ffmpeg frame extraction) | Yes (QuickTime + Keys tags) |
| MP4 | ISO BMFF | QuickTime atoms + Keys:CreationDate | **UTC** (ambiguous without Keys:CreationDate) | Rare | Yes (ffmpeg) | Yes (QuickTime + Keys tags) |
| M4V | ISO BMFF | QuickTime atoms + Keys:CreationDate | **UTC** (ambiguous) | No | Yes (ffmpeg) | Yes (QuickTime + Keys tags) |
| AVI | RIFF | RIFF INFO IDIT field | **Local time** (no TZ concept) | No | Yes (ffmpeg) | **No** (see Caveat) |

**Key insights:**
- HEIC is ISO BMFF but stores EXIF local time — it behaves like JPEG, not like MOV.
- **AVI Caveat:** `rename:exif` reads AVI dates correctly (IDIT → `temporal->original`), but `rename:write-date` cannot write metadata to AVI. `ExiftoolWriter` writes QuickTime atoms that don't exist in RIFF containers. AVI files needing metadata fixes must use exiftool directly.
- **AVI IDIT depends on imagemeta support.** Generic RIFF IDIT parsing works. Nikon cameras storing dates in proprietary `ncdt` MakerNotes are NOT supported (imagemeta#2289).

### A2. Date Extraction Priority Chain

The implementation in `MetadataExtractor` uses imagemeta's `StructuredMetadata`:

```
Implemented (imagemeta → temporal fields):
1. temporal->original  → EXIF DateTimeOriginal (0x9003) or RIFF IDIT
2. temporal->create    → EXIF CreateDate (0x9004) or Keys:CreationDate
3. capture->dateTime   → QuickTime CreateDate (atom, UTC)

Fallback detection (MetadataExtractor flags):
4. EXIF DateTime/ModifyDate (0x0132) → isFallbackDateTime=true, triggers [F]

Not in extraction chain (used by write-date only):
5. Filename date pattern → FileHelper::extractDateTimeFromPath()
```

### A3. Timezone Decision Matrix

| Has EXIF 0x9003? | Has Keys:CreationDate + offset? | QuickTime container? | Result |
|---|---|---|---|
| Yes | * | * | **Reliable** — EXIF date is local time, no conversion |
| No | Yes (offset present) | Yes | **Reliable** — Keys:CreationDate has timezone info |
| No | No | Yes (MOV/MP4/M4V) | **Ambiguous** — UTC without offset → [W] skip |
| No | No | No (JPG/AVI) | **Reliable** — Not QuickTime, local time assumed |
| * | * | HEIC/HEIF + has EXIF | **Reliable** — HEIC with EXIF = local time (BMFF exception) |

**When ambiguous + `--timezone` configured:**
- `rename:exif`: Converts UTC → local time for filename, but still flags [W]
- `rename:write-date --reason=timezone`: Converts CreateDate UTC → Keys:CreationDate with offset

**After `write-date --reason=timezone`:**
- File now has Keys:CreationDate with offset → no longer ambiguous → [O] on next run

**Important caveat for `--reason=timezone`:**
`setTimezone()` treats CreateDate as **real UTC** and converts it. Correct for Apple/DJI. For cameras storing **local time as "UTC"** (some Panasonic, Canon), the conversion produces a wrong result. Users with mixed collections must process cameras separately. No automatic camera-type detection.

### A4. Automatic vs Manual Actions

| Scenario | Automatic? | Tool | Notes |
|----------|-----------|------|-------|
| JPEG/HEIC with DateTimeOriginal | **100% auto** | `rename:exif` | Standard case |
| MOV/MP4/M4V with Keys:CreationDate | **100% auto** | `rename:exif` | Has timezone info |
| AVI with IDIT | **100% auto** | `rename:exif` | Local time, no TZ issue |
| True byte-identical duplicates | **100% auto** | `rename:exif` | Content hash match |
| Perceptual duplicates (JPG↔HEIC) | **Auto, review recommended** | `rename:exif` | Review first run for false positives |
| Live Photo pairs (Content ID) | **100% auto** | `rename:exif` | Content ID match |
| Live Photo pairs (basename fallback) | **100% auto** | `rename:exif` | When Content ID missing |
| LP MOV with ambiguous TZ | **100% auto** | `rename:exif` | Video inherits still's date via LP pairing |
| MOV/MP4/M4V without Keys:CreationDate | **Manual: `--timezone`** | `rename:write-date --reason=timezone` | Then re-run `rename:exif` |
| Fallback date (only 0x0132) | **Semi-auto: verify first** | `rename:verify` → `rename:write-date` | User reviews, then writes |
| No metadata at all | **Manual** | `rename:write-date --reason=nodata` | Writes filename date |
| Date drift >7 days | **Manual: investigate** | `rename:verify` | May be re-export |
| Corrupted metadata | **Manual: exiftool** | External | Beyond tool scope |
| AVI needs metadata fix | **Manual: exiftool** | External | No write-date for AVI |
| Unsupported format (PNG, RAW, MKV) | **Not possible** | N/A | `rename:verify` reports them |

### A5. Duplicate Detection Decision Matrix

| Same content hash? | pHash score ≥ 95? | Same video duration (±1s abs)? | Same date? | Result |
|---|---|---|---|---|
| Yes | N/A | N/A | Yes | **Duplicate** → `-duplicate-NNN` |
| No | Yes | Yes (or both stills) | Yes | **Semantic Duplicate** → merge groups |
| No | Yes | No (videos) | Yes | **Edit/Trim** → sub-group `-002`, `-003` |
| No | 85-95 | * | Yes | **Similar** → sub-group (distinct content) |
| No | < 85 | * | Yes | **Different** → sub-group |
| * | * | * | No | **Different date** → separate groups entirely |

### A6. Live Photo Pairing Decision Matrix

| Still has Content ID? | Video has Content ID? | IDs match? | Same basename? | Result |
|---|---|---|---|---|
| Yes | Yes | Yes | * | **Paired** — Content ID match |
| Yes | Yes | No | * | **Not paired** — Different Live Photos |
| Yes | No | N/A | Yes | **Paired** — Basename fallback (implemented) |
| No | Yes | N/A | Yes | **Paired** — Basename fallback (implemented) |
| No | No | N/A | Yes | **Paired** — Basename fallback (implemented) |
| * | * | * | No | **Not paired** — Independent files |

**Basename fallback is fully implemented** in `LivePhotoPairingService` with ambiguity detection. When 2+ groups share the same basename (e.g. `IMG_0001.jpg` in group A and `IMG_0001.heic` in group B), the match is conservatively rejected to prevent false pairing.

**Critical rule:** A LP video with ambiguous timezone is still paired using the still's date. [W] does NOT prevent LP pairing.

**Conflict detection** (both [C] tagged):
- Still and video have same Content ID but different camera make/model
- Still and video dates differ by >1 hour
- GPS coordinates differ significantly

### A7. Recommended Processing Order

```
Step 1:  rename:verify ~/Photos
         → Understand the collection: format issues, timezone problems, metadata gaps

Step 2:  rename:write-date --reason=nodata ~/Photos
         → Fix files with NO metadata (writes filename date to EXIF)

Step 3:  rename:write-date --reason=fallback ~/Photos
         → Fix files using only ModifyDate (write proper DateTimeOriginal)

Step 4:  rename:write-date --reason=timezone --timezone=<tz> ~/Photos
         → Fix QuickTime videos with ambiguous UTC (writes Keys:CreationDate)

Step 5:  rename:write-date --reason=drift ~/Photos
         → Fix files where metadata date disagrees with filename date
         ⚠ Only detects drift for files whose filenames already contain dates.
           On a fresh collection (IMG_1234.jpg), the drift check is a no-op.
           Run again after Step 7 if needed.

Step 6:  rename:exif --dry-run ~/Photos
         → Preview all renames, check for remaining [W] warnings

Step 7:  rename:exif ~/Photos
         → Execute renames

Step 8:  rename:verify ~/Photos
         → Verify all metadata issues are resolved after Steps 2-7

Step 9:  rename:exif --dry-run ~/Photos
         → Verify idempotency (should show 0 changes)

Step 10: rename:dedup --dry-run ~/Photos
         → Preview duplicate cleanup
         ⚠ Dedup checks same directory only for originals.

Step 11: rename:dedup ~/Photos
         → Move duplicates to _duplicates/ folder
```

**Cache note:** After Steps 2-4, metadata cache auto-invalidates (keys by mtime+size).

---

## Part B: Missing Test Scenarios

| # | Scenario | Test Type | Notes |
|---|----------|-----------|-------|
| 32 | HEIC without EXIF DateTimeOriginal (only QuickTime CreateDate) | Integration | Should be [W] (ambiguous, no EXIF to short-circuit) |
| 33 | MOV with Keys:CreationDate + offset | Integration | Should be [R] not [W] |
| 34 | AVI with RIFF IDIT date | Integration | Depends on imagemeta IDIT support |
| 35 | Fallback date with --skip-fallback | Unit | Separate test method |
| 36 | Mixed [W] and [R] in same timestamp group | Integration | [W] must not infect [R] |
| 37 | write-date --reason=timezone → rename:exif (2-step) | WriteDateFlowTest | End-to-end fix-then-rename |
| 38 | write-date --reason=nodata (filename → metadata) | WriteDateFlowTest | Verify metadata written correctly |
| 39 | Unsupported format alongside supported files | Integration | PNG silently skipped |
| 40 | LP MOV with ambiguous TZ paired via Content ID | Integration | Most common iPhone LP case |
| 41 | Metadata cache invalidation after write-date | WriteDateFlowTest | Cache miss on mtime/size change |

---

## Part C: Implementation Tasks

**Execution order:** Task 1 (Fixes) → Task 2 (Tests) → Task 3 (Performance) → Task 4 (Doku) → Task 5 (README)

### Task 1: Implementation Fixes

#### 1a: Guard AVI in WriteDateCommand

**Files:** `src/Command/WriteDateCommand.php`, `tests/Unit/Command/WriteDateCommandTest.php`

AVI passes `isVideo=true` to ExiftoolWriter which writes QuickTime atoms into RIFF — silently fails.

- [ ] Write failing test: WriteDateCommand skips AVI with warning message
- [ ] Implement guard: check extension, skip AVI with descriptive warning
- [ ] Commit

#### 1b: Fix hasReliableDateTime minute-precision comparison

**Files:** `src/Metadata/ExifMetadataProvider.php`, `tests/Unit/Metadata/ExifMetadataProviderTest.php`

`hasReliableDateTime()` compares `Y-m-d H:i` (minutes only). A file renamed to `..._14-30-22` would match raw metadata `14:30:00` — the 22-second difference is ignored. This masks real mismatches.

- [ ] Write failing test: raw `14:30:00` vs filename `14:30:22` should return false
- [ ] Change comparison from `Y-m-d H:i` to `Y-m-d H:i:s`
- [ ] Verify existing tests still pass (some may rely on minute precision)
- [ ] Commit

#### 1c: Dedup cross-directory original search

**Files:** `src/Command/DedupCommand.php`, `tests/Unit/Command/DedupCommandTest.php`

Currently dedup looks for originals in the same directory only. After rename:exif, cross-directory duplicates like `backup/2024-01-01_12-00-00-000-duplicate-001.jpg` have their original in the parent directory.

- [ ] Write failing test: duplicate in subdir, original in root → NOT orphaned
- [ ] Implement: search source root for original (strip duplicate suffix, check all extensions)
- [ ] Commit

### Task 2: Add Missing Test Scenarios (32-41)

**Files:**
- `scripts/create-test-images.php`
- `tests/Integration/TestImageScenariosTest.php`
- Create: `tests/Integration/WriteDateFlowTest.php`
- Create: `tests/Fixtures/Images/32-*` through `41-*`

**Sub-task 2a: scenarioProvider scenarios (32, 33, 34, 36, 39, 40)**

For each: TDD — add fixture, add yield, generate, run test, fix if needed, commit separately.

- [ ] **Scenario 32** — HEIC without EXIF DateTimeOriginal → [W]
- [ ] **Scenario 33** — MOV with Keys:CreationDate + offset → [R]
- [ ] **Scenario 34** — AVI with RIFF IDIT → [R] (or known limitation if imagemeta fails)
- [ ] **Scenario 36** — JPG [R] + MOV [W] same date → warning doesn't spread
- [ ] **Scenario 39** — JPG + PNG → only JPG processed
- [ ] **Scenario 40** — LP JPG + MOV (no Keys:CreationDate), same Content ID → both [R]

**Sub-task 2b: WriteDateFlowTest (37, 38, 41)**

- [ ] Create `tests/Integration/WriteDateFlowTest.php`
- [ ] **Scenario 37** — write-date timezone → rename:exif
- [ ] **Scenario 38** — write-date nodata → rename:exif
- [ ] **Scenario 41** — cache invalidation across commands

**Sub-task 2c: Skip-fallback option test (35)**

- [ ] Separate test method with `--skip-fallback=true`

### Task 3: Performance Optimizations

#### 3a: Reduce video frame extraction from 5 to 3 frames

**Files:** `src/Service/PerceptualHash/ImagickImageLoader.php`

5 ffmpeg processes per video is the #1 bottleneck. 3 frames at [25%, 50%, 75%] provide sufficient coverage for duplicate detection.

- [ ] Write test: video comparison still works with 3 frames
- [ ] Change frame positions: `[0.25, 0.50, 0.75]` instead of `[0.10, 0.30, 0.50, 0.70, 0.90]`
- [ ] Reduce ffmpeg timeout from 20s to 5s (with retry)
- [ ] Benchmark: measure time for 10 video comparisons before/after
- [ ] Commit

**Expected impact:** ~40% reduction in video processing time.

#### 3b: Tighten dHash early-exit threshold

**Files:** `src/Service/PerceptualHash/PerceptualHashCalculator.php`

Current threshold `dd > 20` (68.75% dissimilar) still loads Imagick and computes wHash/HF/color for clearly different images. Threshold `dd > 16` (75% dissimilar) is sufficient to classify as "different".

- [ ] Write test: verify pairs with dd=17,18,19,20 are still classified correctly
- [ ] Change threshold from 20 to 16
- [ ] Verify no regression in TestImageScenariosTest
- [ ] Commit

**Expected impact:** ~15% fewer full-signal comparisons for non-duplicate groups.

#### 3c: Reduce Stage B resolution from 1024 to 512

**Files:** `src/Service/HashSubGroupingService.php`

`LocalDifferenceAnalyzer` downscales to `WORK_SIZE=512` internally. Passing 1024px wastes the extra resolution.

- [ ] Change `loadNormalized($file, 1024)` to `loadNormalized($file, 512)` in `hasLocalRetouchCached`
- [ ] Verify LocalDifferenceAnalyzer tests still pass
- [ ] Commit

**Expected impact:** ~50% reduction in Imagick memory for Stage B, faster resize.

### Task 4: Create Decision Matrix Documentation

**Files:** Create `docs/decision-matrix.md`

- [ ] Refine Part A into user-facing document
- [ ] Add to README as reference link
- [ ] Commit

### Task 5: Update README Workflow

**Files:** `README.md`

- [ ] Update "Recommended Workflow" to 11 steps with caveats
- [ ] Add AVI write-date limitation note
- [ ] Add non-Apple timezone caveat
- [ ] Add link to decision matrix
- [ ] Commit

### Task 6: Validate Idempotency Across All Formats

**Files:** `tests/Integration/TestImageScenariosTest.php`

- [ ] Add `testIdempotencyAcrossFormats()`: real rename then dry-run → 0 changes
- [ ] Test with: JPG, HEIC, MOV (with TZ), AVI (with IDIT)
- [ ] Commit

---

## Part D: Known Limitations

| Limitation | Reason | Workaround |
|------------|--------|------------|
| PNG/WebP not supported | No EXIF metadata; imagemeta doesn't parse | Convert to JPEG first |
| MKV/WebM containers | imagemeta doesn't parse Matroska | Convert to MP4 |
| Nikon AVI MakerNotes | imagemeta#2289: `ncdt` chunk not parsed | Wait for upstream fix |
| AVI metadata writing | ExiftoolWriter writes QuickTime tags only | Use exiftool directly |
| GPS → Timezone | Requires timezone database dependency | User supplies `--timezone` |
| Non-Apple camera TZ | `--reason=timezone` treats as real UTC | User verifies per camera |
| Non-UTF-8 filenames | Assumed UTF-8 throughout | Rename first |
| DNG/TIFF | Untested — imagemeta may support TIFF-based EXIF | Test and add to extensions |
| Large file pHash (>1GB) | Imagick memory limits | pHash skipped on failure |
| LP multi-extension ambiguity | Basename fallback rejects when 2+ groups share basename | Rare; requires manual pairing |

---

## Part E: Summary

**What this plan delivers:**

1. **3 implementation fixes** — AVI write-date guard, hasReliableDateTime second-precision, dedup cross-directory search
2. **10 new test scenarios** (32-41) with new WriteDateFlowTest class
3. **3 performance optimizations** — video frame reduction, dHash threshold, Stage B resolution
4. **Idempotency validation** across all formats
5. **User-facing decision matrix** documentation
6. **Updated README workflow** (11 steps with caveats)
7. **Corrected known limitations** table

**What was wrong in previous plan versions:**
- ~~"No Live Photo basename fallback"~~ → Already implemented with ambiguity detection
- ~~"No performance optimizations"~~ → 3 concrete, measurable optimizations added
- ~~"No implementation changes"~~ → 3 real bugs/gaps that need fixing

**Execution order:** Task 1 (Fixes) → Task 2 (Tests) → Task 3 (Perf) → Task 4 (Doku) → Task 5 (README) → Task 6 (Idempotenz)
