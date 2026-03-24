# Media Processing Masterplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Define and implement a complete, testable specification for how every type of media file is handled — from metadata extraction through renaming, deduplication, and Live Photo pairing.

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
- **AVI Caveat:** `rename:exif` reads AVI dates correctly (IDIT → `temporal->original`), but `rename:write-date` cannot write metadata to AVI because `ExiftoolWriter` writes QuickTime atoms that don't exist in RIFF containers. AVI files that need metadata fixes must use exiftool directly.
- **AVI IDIT depends on imagemeta support.** Generic RIFF IDIT parsing works. Nikon cameras that store dates in proprietary `ncdt` MakerNotes are NOT supported (imagemeta#2289).

### A2. Date Extraction Priority Chain

The actual implementation in `MetadataExtractor` uses imagemeta's `StructuredMetadata`:

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

**Note:** Position 1-3 are resolved by imagemeta's `StructuredMetadata` builder. The renamer uses `temporal->original ?? temporal->create ?? capture->dateTime`. The specific mapping from raw tags to these fields is an imagemeta concern.

### A3. Timezone Decision Matrix

| Has EXIF 0x9003? | Has Keys:CreationDate + offset? | QuickTime container? | Result |
|---|---|---|---|
| Yes | * | * | **Reliable** — EXIF date is local time, no conversion |
| No | Yes (offset present) | Yes | **Reliable** — Keys:CreationDate has timezone info |
| No | No | Yes (MOV/MP4/M4V) | **Ambiguous** — UTC without offset → [W] skip |
| No | No | No (JPG/AVI) | **Reliable** — Not a QuickTime container, local time assumed |
| * | * | HEIC/HEIF + has EXIF | **Reliable** — HEIC with EXIF = local time (BMFF exception) |

**When ambiguous + `--timezone` configured:**
- `rename:exif`: Converts UTC → local time for filename, but still flags [W] (metadata is not fixed)
- `rename:write-date --reason=timezone`: Converts CreateDate UTC → Keys:CreationDate with offset (metadata IS fixed)

**After `write-date --reason=timezone`:**
- File now has Keys:CreationDate with offset → no longer ambiguous → [O] on next run

**Important caveat for `--reason=timezone`:**
The implementation uses `setTimezone()` which treats CreateDate as **real UTC** and converts it. This is correct for cameras that store real UTC (Apple, DJI). For cameras that store **local time as "UTC"** (some Panasonic, Canon models), the conversion would produce a wrong result. Users with mixed collections must process cameras separately or verify the output. There is no automatic camera-type detection — the user takes responsibility for knowing their camera's behavior.

### A4. Automatic vs Manual Actions

| Scenario | Automatic? | Tool | Notes |
|----------|-----------|------|-------|
| JPEG/HEIC with DateTimeOriginal | **100% auto** | `rename:exif` | Standard case |
| MOV/MP4/M4V with Keys:CreationDate | **100% auto** | `rename:exif` | Has timezone info |
| AVI with IDIT | **100% auto** | `rename:exif` | Local time, no TZ issue. Depends on imagemeta IDIT support. |
| True byte-identical duplicates | **100% auto** | `rename:exif` | Content hash match |
| Perceptual duplicates (JPG↔HEIC) | **Auto, review recommended** | `rename:exif` | pHash pipeline — review first run for false positives |
| Live Photo pairs (with content ID) | **100% auto** | `rename:exif` | Content ID match |
| LP MOV with ambiguous TZ | **100% auto** | `rename:exif` | Video inherits still's date via LP pairing, TZ irrelevant |
| MOV/MP4/M4V without Keys:CreationDate | **Manual: user must provide `--timezone`** | `rename:write-date --reason=timezone` | Then re-run `rename:exif` |
| Fallback date (only 0x0132) | **Semi-auto: verify first** | `rename:verify` → `rename:write-date --reason=fallback` | User reviews, then writes |
| No metadata at all | **Manual: user names file correctly, then** | `rename:write-date --reason=nodata` | Writes filename date to metadata |
| Date drift >7 days | **Manual: user investigates** | `rename:verify` | May be re-export or wrong metadata |
| Corrupted/unreadable metadata | **Manual: exiftool repair** | External | Beyond tool scope |
| AVI needs metadata fix | **Manual: exiftool** | External | ExiftoolWriter has no AVI support |
| Unsupported format (PNG, RAW, MKV) | **Not possible** | N/A | `rename:verify` reports as "Unrecognized file types" |

### A5. Duplicate Detection Decision Matrix

| Same content hash? | pHash score ≥ 95? | Same video duration (±1s abs)? | Same date? | Result |
|---|---|---|---|---|
| Yes | N/A | N/A | Yes | **Duplicate** → `-duplicate-NNN` |
| No | Yes | Yes (or both stills) | Yes | **Semantic Duplicate** → merge groups |
| No | Yes | No (videos) | Yes | **Edit/Trim** → sub-group `-002`, `-003` |
| No | 85-95 | * | Yes | **Similar** → sub-group (distinct content) |
| No | < 85 | * | Yes | **Different** → sub-group |
| * | * | * | No | **Different date** → separate groups entirely |

Video duration tolerance: ±1 second absolute.

### A6. Live Photo Pairing Decision Matrix

| Still has Content ID? | Video has Content ID? | IDs match? | Same basename? | Result |
|---|---|---|---|---|
| Yes | Yes | Yes | * | **Paired** — Video inherits still's date |
| Yes | Yes | No | * | **Not paired** — Different Live Photos |
| Yes | No | N/A | Yes | **Paired by basename** — Fallback *(not yet implemented)* |
| No | Yes | N/A | Yes | **Paired by basename** — Fallback *(not yet implemented)* |
| No | No | N/A | Yes | **Paired by basename** — Fallback *(not yet implemented)* |
| * | * | * | No | **Not paired** — Independent files |

**Critical rule:** A Live Photo video companion with ambiguous timezone is still paired and renamed using the still's date. The [W] flag does NOT prevent LP pairing.

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
           On a fresh collection with camera-original names (IMG_1234.jpg),
           the drift check is a no-op. Run again after Step 7 if needed.

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
         ⚠ Dedup checks for originals in the same directory only.
           Cross-directory duplicates may show as "orphaned" if the
           original is in a different directory.

Step 11: rename:dedup ~/Photos
         → Move duplicates to _duplicates/ folder
```

**Note on cache:** After Steps 2-4 (write-date modifies files on disk), the metadata cache keys by pathname+mtime+size. Since exiftool changes mtime and size, cache entries auto-invalidate. No manual cache clear needed.

---

## Part B: Missing Test Scenarios

### B1. Gap Analysis — What's NOT tested

| # | Scenario | Current State | Needed | Test Type |
|---|----------|---------------|--------|-----------|
| 32 | HEIC without EXIF DateTimeOriginal (only CreateDate, no Keys offset) | Not tested (scenario 14 has EXIF) | New fixture: HEIC with CreateDate only → should be [W] or [R] depending on TZ | Integration |
| 33 | MOV with Keys:CreationDate + offset (NOT ambiguous) | Not explicitly tested | New fixture: MOV with `Keys:CreationDate=2024:06:15 16:30:00+02:00` → [R] | Integration |
| 34 | AVI with RIFF IDIT date | Not tested | New fixture with IDIT metadata → [R] rename. **Depends on imagemeta IDIT support.** | Integration |
| 35 | Fallback date with --skip-fallback | Not tested (scenarioProvider has no --skip-fallback) | Separate test method with custom CommandTester options | Unit |
| 36 | Mixed [W] and [R] in same timestamp group | Not tested | JPG (has EXIF) + MOV (ambiguous TZ), same date → JPG [R], MOV [W] | Integration |
| 37 | write-date --reason=timezone → then rename:exif (2-step flow) | Not tested | **WriteDateFlowTest** class: run write-date, then rename:exif on same workspace | Integration (2-step) |
| 38 | write-date --reason=nodata (file with no date, filename has date) | Not tested | **WriteDateFlowTest** class: write metadata from filename, verify via re-read | Integration (2-step) |
| 39 | Unsupported format alongside supported files | Not tested | JPG + PNG in same dir → only JPG processed | Integration |
| 40 | LP MOV with ambiguous TZ paired via Content ID | Not explicitly tested (scenario 04 has Keys:CreationDate) | JPG with EXIF + MOV without Keys:CreationDate, same LP Content ID → both [R] | Integration |
| 41 | Metadata cache invalidation between write-date and rename:exif | Not tested | Write-date changes file, then rename:exif sees updated metadata | Integration (2-step) |

### B2. Test Infrastructure

**Existing:** `TestImageScenariosTest::scenarioProvider()` — single dry-run command, parses output mappings.

**New needed:** `WriteDateFlowTest` — separate integration test class for multi-step workflows:
- Creates temp workspace with fixture files
- Runs `WriteDateCommand` via CommandTester (actual write, not dry-run)
- Runs `RenameByExifDateCommand` via CommandTester (dry-run)
- Asserts the rename mapping reflects the corrected metadata
- Requires: exiftool available in Docker container (already installed)

Scenarios 37, 38, 41 use WriteDateFlowTest. All others fit into scenarioProvider.

---

## Part C: Implementation Tasks

**Execution order:** Task 1 (Tests) → Task 2 (Doku) → Task 3 (Idempotenz) → Task 4 (README)

### Task 1: Add Missing Test Scenarios

**Files:**
- Modify: `scripts/create-test-images.php`
- Modify: `tests/Integration/TestImageScenariosTest.php`
- Create: `tests/Integration/WriteDateFlowTest.php` (new class for 2-step flows)
- Create: `tests/Fixtures/Images/32-*` through `41-*`

**Sub-task 1a: Scenarios for scenarioProvider (32, 33, 34, 36, 39, 40)**

For each: add fixture in create-test-images.php, add yield in scenarioProvider, generate, run test (TDD).

- [ ] **Scenario 32 — HEIC without EXIF DateTimeOriginal:**
  Create HEIC with only QuickTime CreateDate (no EXIF 0x9003, no Keys:CreationDate offset).
  This differs from scenario 14 which has EXIF DateTimeOriginal.
  Expected: [W] skipped (ambiguous TZ, no EXIF to short-circuit).

- [ ] **Scenario 33 — MOV with Keys:CreationDate + offset:**
  Create MOV with `Keys:CreationDate=2024:06:15 16:30:00+02:00`.
  Expected: [R] rename to `2024-06-15_16-30-00-000.mov` (timezone resolved).

- [ ] **Scenario 34 — AVI with RIFF IDIT:**
  Create AVI with RIFF IDIT field. Verify imagemeta extracts the date.
  Expected: [R] rename using IDIT date. If imagemeta returns null, move to known limitations.

- [ ] **Scenario 36 — Mixed [W] and [R] in same group:**
  Create JPG (EXIF DateTimeOriginal) + MOV (QuickTime, no Keys:CreationDate), same date.
  Expected: JPG gets [R], MOV is [W] skipped. Warning does NOT infect the JPG.

- [ ] **Scenario 39 — Unsupported format skipped:**
  Create JPG (with EXIF) + PNG (dummy) in same directory.
  Expected: Only JPG in mapping. PNG silently ignored by file iterator regex.

- [ ] **Scenario 40 — LP MOV with ambiguous TZ paired via Content ID:**
  Create JPG with EXIF + Content ID. Create MOV with QuickTime (no Keys:CreationDate), same Content ID.
  Expected: Both [R] — MOV inherits JPG's date despite ambiguous timezone.
  This is the most common iPhone Live Photo scenario.

- [ ] Run `make test`, verify all new scenarios pass or identify bugs.
- [ ] Commit each scenario separately.

**Sub-task 1b: WriteDateFlowTest (Scenarios 37, 38, 41)**

- [ ] Create `tests/Integration/WriteDateFlowTest.php` with:
  - Helper: `createWorkspace()`, `runWriteDate()`, `runDryRun()`
  - Uses real exiftool (available in Docker)
  - Uses real MetadataExtractor + ExifMetadataProvider

- [ ] **Scenario 37 — write-date timezone then rename:exif:**
  Create MOV with QuickTime CreateDate UTC, no Keys:CreationDate.
  Run `write-date --reason=timezone --timezone=Europe/Amsterdam`.
  Assert Keys:CreationDate written with offset.
  Run `rename:exif --dry-run`.
  Assert file maps to local-time filename and is NOT [W].

- [ ] **Scenario 38 — write-date nodata:**
  Create JPG with no EXIF metadata, filename contains date.
  Run `write-date --reason=nodata`.
  Assert DateTimeOriginal written.
  Run `rename:exif --dry-run`.
  Assert file maps to filename date.

- [ ] **Scenario 41 — Metadata cache invalidation:**
  Run `write-date` to fix metadata on a file.
  Run `rename:exif` with metadata cache enabled.
  Assert rename uses the NEW metadata (cache miss due to mtime/size change).

- [ ] Commit.

**Sub-task 1c: Scenario 35 — skip-fallback option**

- [ ] Add a separate test method in `TestImageScenariosTest` (not scenarioProvider):
  `testSkipFallbackOptionSkipsFallbackFiles()`
  Create fixture with only 0x0132 tag.
  Run with `--skip-fallback=true`.
  Assert file is not in rename mapping (skipped).
- [ ] Commit.

### Task 1b: Guard AVI in WriteDateCommand

**Files:**
- Modify: `src/Command/WriteDateCommand.php`
- Modify: `tests/Unit/Command/WriteDateCommandTest.php`

AVI files pass `isVideo=true` to ExiftoolWriter which writes QuickTime atoms that don't exist in RIFF containers. Add a guard that skips AVI files with a warning.

- [ ] Write failing test: WriteDateCommand skips AVI files with "AVI metadata writing not supported" message
- [ ] Implement guard: check extension before calling ExiftoolWriter, skip AVI with `$io->warning()`
- [ ] Commit

### Task 2: Create Decision Matrix Documentation

**Files:**
- Create: `docs/decision-matrix.md`

- [ ] Refine Part A of this plan into a user-facing document
- [ ] Add to README as reference link
- [ ] Commit

### Task 3: Validate Idempotency Across All Formats

**Files:**
- Modify: `tests/Integration/TestImageScenariosTest.php`

- [ ] Add test method `testIdempotencyAcrossFormats()`:
  Create workspace with: JPG, HEIC, MOV (with TZ), AVI (with IDIT).
  Run `rename:exif` (real execution, not dry-run).
  Run `rename:exif --dry-run` again.
  Assert zero changes (all [O]).
- [ ] Commit

### Task 4: Update README Workflow

**Files:**
- Modify: `README.md`

- [ ] Update "Recommended Workflow" section to match A7 (11 steps)
- [ ] Add link to `docs/decision-matrix.md`
- [ ] Add note about AVI write-date limitation
- [ ] Add note about non-Apple camera timezone caveat
- [ ] Commit

---

## Part D: Known Limitations

| Limitation | Reason | Workaround |
|------------|--------|------------|
| PNG/WebP not supported | No EXIF metadata; imagemeta doesn't parse WebP | Convert to JPEG first |
| MKV/WebM containers | imagemeta doesn't parse Matroska | Convert to MP4 |
| Nikon AVI MakerNotes | imagemeta#2289: proprietary `ncdt` chunk not parsed | Wait for imagemeta upstream fix |
| AVI metadata writing | ExiftoolWriter only writes QuickTime/EXIF tags, not RIFF | Use exiftool directly for AVI metadata fixes |
| GPS → Timezone derivation | Requires timezone database dependency | User supplies `--timezone` |
| Non-Apple camera timezone | `--reason=timezone` treats CreateDate as real UTC; cameras storing local-as-UTC get wrong result | User must know their camera's behavior; process separately |
| Non-UTF-8 filenames | Assumed UTF-8 throughout | Rename files to UTF-8 first |
| DNG/TIFF | Untested — imagemeta may support TIFF-based EXIF but renamer doesn't include these extensions | Add to SUPPORTED_MEDIA_EXTENSIONS if imagemeta confirms support |
| Large file pHash (>1GB) | Imagick may struggle with very large image/video files for perceptual hashing | Content hash still works; pHash skipped on Imagick failure |
| Live Photo basename fallback | LP pairing by Content ID is implemented; basename fallback for cameras without Content ID is NOT implemented | Files without Content ID remain unpaired |

---

## Part E: Summary

**What this plan delivers:**
1. Complete decision matrices covering every format × metadata × timezone × duplicate × LP combination
2. 10 new test scenarios (32-41) filling identified gaps, including a new `WriteDateFlowTest` class for 2-step workflows
3. Idempotency validation across all formats
4. User-facing decision matrix documentation
5. Updated README workflow (11 steps with caveats)
6. Clear documentation of automatic vs manual vs impossible actions
7. Honest known limitations table with workarounds

**What this plan does NOT change:**
- No new format support (requires imagemeta upstream)
- No GPS timezone derivation (future enhancement, parked)
- No changes to the core pipeline architecture
- No AVI write-date support (requires ExiftoolWriter AVI branch; WriteDateCommand should skip AVI with warning)
- No Live Photo basename fallback (requires LivePhotoPairingService changes; A6 rows marked "not yet implemented")
- No performance optimizations (separate effort)

**Execution order:** Task 1 (Tests, TDD-first) → Task 2 (Doku) → Task 3 (Idempotenz) → Task 4 (README)
