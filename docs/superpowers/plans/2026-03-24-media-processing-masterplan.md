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

6. **Dry-run is read-only.** `--dry-run` must NOT alter pipeline logic, persistent state, or cached data. It prevents only the actual file operation. The output must accurately reflect what a real run would do — including occupied-path tracking and collision detection. Transient simulation state (e.g. in-memory occupied-path index) may be mutated during dry-run to ensure output accuracy.

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
| No | No | HEIC/HEIF (no EXIF) | **Ambiguous** → [W] — ISO BMFF without EXIF fallback |
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
| MOV↔MP4 (container swap) | 0 | 98-100 | `-duplicate-NNN` | N/A (video) | Not tested (needs remuxed pair) |
| JPEG quality re-save | 0-2 | 95-100 | `-duplicate-NNN` | Skip when dHash=0 | #25 |
| Video re-encode (same duration) | 1-8 | 90-98 | `-duplicate-NNN` or `-002` | N/A (video) | Not tested (needs re-encoded pair) |
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

### A5. Metadata Conflict Resolution

When multiple metadata tags provide conflicting dates, the **highest priority wins** (per A2 chain). No averaging, no heuristics.

| Tag 1 (higher priority) | Tag 2 (lower priority) | Winner | Rationale |
|---|---|---|---|
| DateTimeOriginal=Jan 1 | Keys:CreationDate=Jun 15 | **Jan 1** | Camera's original capture date is most authoritative |
| DateTimeOriginal=Jan 1 | QuickTime CreateDate=Jun 15 UTC | **Jan 1** | EXIF tag > QuickTime atom |
| Keys:CreationDate=Jun 15 +02:00 | QuickTime CreateDate=Jun 15 14:00 UTC | **Jun 15 16:00** (local) | Keys has explicit timezone, convert CreateDate UTC to match |
| Only ModifyDate (0x0132)=Jan 1 | (nothing else) | **Jan 1 [F]** | Fallback, flagged for user review |

**Test needed:** Scenario with DateTimeOriginal AND Keys:CreationDate holding different dates → verify highest priority wins.

`rename:verify` should report metadata conflicts as a separate category when tags disagree by >1 hour.

### A5b. Extension Normalization Rules

All extensions are lowercased. Aliases are mapped:

| Input | Output | Rationale |
|-------|--------|-----------|
| `.JPEG`, `.jpeg` | `.jpg` | Standard JPEG alias |
| `.JPG` | `.jpg` | Case normalization |
| `.HEIC` | `.heic` | Case normalization |
| `.HEIF` | `.heif` | Case normalization — NOT converted to .heic (different codec possible) |
| `.MOV`, `.Mov` | `.mov` | Case normalization |
| `.MP4` | `.mp4` | Case normalization |
| `.M4V` | `.m4v` | Case normalization |
| `.AVI` | `.avi` | Case normalization |

HEIF and HEIC are **not** interchangeable. HEIC = HEVC codec in HEIF container (Apple). HEIF = generic container that may use different codecs. Both are parsed identically by imagemeta but the extension reflects the actual codec.

### A5c. Live Photo Pair Atomicity

**Rule:** LP pairs are processed atomically — either both files are renamed, or neither is. The still's metadata quality determines the pair's fate.

| Still Status | Video Status | Pair Outcome | Rationale |
|---|---|---|---|
| [R] reliable | [R] reliable | **Both renamed** — video inherits still's date | Normal case |
| [R] reliable | [W] ambiguous TZ | **Both renamed** — video inherits still's date | Video's own TZ is irrelevant in LP pair |
| [R] reliable | [S] no metadata | **Both renamed** — video inherits still's date | Video has no date but still does |
| [W] ambiguous TZ | [R] reliable | **Both skipped** — still's date is unreliable | Cannot trust the date that video would inherit |
| [W] ambiguous TZ | [W] ambiguous TZ | **Both skipped** | No reliable date source |
| [F] fallback | [R] reliable | **Both [F]** — still's date is fallback quality | Video inherits questionable date; user should fix |
| [F] fallback | [W] ambiguous | **Both [F]** — fallback dominates | Weakest link determines pair quality |
| [S] no metadata | [R] reliable | **Pair broken** — video standalone [R], still [S] | Still has no date; video uses own |
| [S] no metadata | [W] ambiguous | **Pair broken** — video standalone [W], still [S] | Still has no date; video has ambiguous |
| [S] no metadata | [S] no metadata | **Both [S]** | No date anywhere |

**Exception to AGENTS.md rule:** AGENTS.md states "MOV companions always inherit the paired still's date, never their own." This rule only applies when the still has a usable date. When the still has no metadata ([S]), the LP pairing is effectively broken — the video companion is treated as a standalone file despite having a matching Content ID.

**Test needed:** Scenarios for each row in this table, especially "Still [W] + Video [R]" → both must be skipped.

### A5d. Intra-Group Processing Order

Within each duplicate group, processing follows this strict order:

```
Step 1: LP Pairing
  → Identify which files are LP companions (via Content ID or basename fallback)
  → Companions are excluded from hash sub-grouping (compared separately)

Step 2: Canonical Selection
  → Priority: (1) source basename matches target basename (idempotent)
              (2) file has LP Content ID (original capture)
              (3) shallowest directory depth (root wins)
              (4) first encountered file

Step 3: Hash Sub-Grouping
  → Content hash: byte-identical files → same sub-group
  → pHash Stage A: multi-signal scoring for different hashes
  → pHash Stage B: local blob analysis for score ≥ 95 (skip if dHash=0)
  → Union-find merge: visually identical → same sub-group

Step 4: Suffix Assignment
  → Canonical sub-group: base name (no suffix) for canonical, -duplicate-NNN for copies
  → Other sub-groups: -002, -003, etc.
  → Within each sub-group: canonical gets no suffix, duplicates get -duplicate-NNN

Step 5: Companion Inheritance
  → LP video companion inherits its paired still's base name + sub-group suffix
  → Companion's own metadata quality does NOT affect naming (only still's quality matters)
```

**Critical dependency:** Each step's output feeds the next. Errors in Step 1 (wrong pairing) propagate through all subsequent steps. This is why LP pairing must happen first.

### A5e. Cross-Cutting Classification Conflicts

When a file triggers multiple classification systems simultaneously, this priority determines the output tag:

```
Priority (highest to lowest):
1. [C] Content ID conflict      — always wins, always skipped
2. [W] Ambiguous timezone        — metadata quality issue, skipped
3. [F] Fallback date             — metadata quality warning (tag shows action needed)
4. [D] Duplicate                 — content classification
5. [O] Original / no-op         — file already correctly named
6. [R] Rename                    — normal operation

Post-hoc override (applied after initial tag):
   [R] or [F] + date drift > MAX_DATE_DRIFT → promoted to [W]
```

**Key decisions:**
- **[F] > [D]:** The tag shows the most actionable information. Duplicate status is visible in the `-duplicate-NNN` suffix. The user needs to know the date is weak.
- **[O] > [F] and [O] > [W] for no-ops:** If a file is already correctly named (source == target), metadata quality issues ([F] fallback, [W] ambiguous TZ) are `rename:verify` concerns, not `rename:exif` concerns. The file won't be renamed regardless, so showing a warning tag would be confusing and break idempotency.
- **Date drift is a post-hoc promotion**, not a priority level. It only applies to [R] and [F] tags because duplicates and originals have their own naming logic.

**Conflict resolution table:**

| Situation | Tag | Naming | Rationale |
|---|---|---|---|
| Duplicate + Ambiguous TZ | **[W]** | Skipped | Metadata quality > content classification |
| Duplicate + Fallback Date | **[F]** | Shown with `-duplicate-NNN` target | [F] > [D]: tag shows actionable info (fix metadata); suffix shows content relationship |
| Edit (-002) + Fallback Date | **[F]** | Shown with `-002` target | Weak metadata dominates; user should fix date first |
| Edit (-002) + Ambiguous TZ | **[W]** | Skipped | Cannot rename with unreliable date |
| LP Companion + Ambiguous TZ (still is OK) | **[R]** | Renamed (inherits still's date) | Still's metadata quality overrides companion's (per A5c) |
| LP Companion + Ambiguous TZ (still is also [W]) | **[W]** | Both skipped | No reliable date in the pair |
| Original (no-op) + Fallback Date | **[O]** | No rename | Name is already correct; fallback is a verify concern, not a rename concern |

### A5f. rename:verify Diagnostic Output

For each problematic file, `rename:verify` should provide structured diagnostic information. New `--detail` flag for per-file analysis:

```
rename:verify --detail ~/Photos

=== Timezone Issues (3 files) ===

[W] clip.mp4
    Problem:    Ambiguous timezone — QuickTime UTC without offset
    Metadata:   CreateDate=2025:04:03 16:50:50 (UTC)
                Keys:CreationDate=(none)
    Camera:     DJI NEO (DJI FC8671)
    Suggestion: ./renamer.sh rename:write-date --reason=timezone \
                  --timezone=Europe/Amsterdam 'clip.mp4'

=== Fallback Dates (1 file) ===

[F] scan-001.jpg
    Problem:    Only DateTime (0x0132) — no DateTimeOriginal
    Metadata:   DateTime=2023:12:25 08:00:00
                DateTimeOriginal=(none)
    Suggestion: ./renamer.sh rename:write-date --reason=fallback 'scan-001.jpg'

=== No Metadata (2 files) ===

[S] IMG_1234.jpg
    Problem:    No capture date found
    Suggestion: Name file with correct date, then:
                ./renamer.sh rename:write-date --reason=nodata 'IMG_1234.jpg'

=== Metadata Conflicts (1 file) ===

[!] photo.mov
    Problem:    DateTimeOriginal and Keys:CreationDate disagree by 168 days
    Metadata:   DateTimeOriginal=2024:01:01 12:00:00
                Keys:CreationDate=2024:06:15 14:00:00+02:00
    Using:      DateTimeOriginal (highest priority per extraction chain)
    Suggestion: Verify which date is correct. Use exiftool to fix.
```

### A6. Automatic vs Manual Actions

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

### A7. Processing Order (12 Steps)

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

**Fix:** In `HashSubGroupingService::mergePerceptuallySimilarGroups()`, inside the existing `isDuplicateLikely()` check, skip Stage B when dHash distance = 0:

```php
// Existing code structure (preserved):
if ($result->isDuplicateLikely()) {
    // NEW: skip Stage B when dHash=0 (compression noise, not retouch)
    $shouldMerge = ($result->dhashDistance === 0)
        || !$this->hasLocalRetouchCached(...);
}
```

The fix stays INSIDE the `isDuplicateLikely()` guard. Stage B is only skipped for pairs that are already classified as likely duplicates AND have zero dHash distance.

**Files:** `src/Service/HashSubGroupingService.php`
**Tests:** New scenario #42 (same-dir HEIC+JPG format backup)

### Fix 2: Guard AVI in WriteDateCommand

**Bug:** ExiftoolWriter writes QuickTime atoms to AVI RIFF container — silently fails.

**Fix:** Check extension before calling ExiftoolWriter, skip AVI with warning.

**Files:** `src/Command/WriteDateCommand.php`

### Fix 3: hasReliableDateTime second-precision

**Bug:** Comparison uses `Y-m-d H:i` (minutes). File `14:30:22` matches metadata `14:30:00`.

**Fix:** Change to `Y-m-d H:i:s`. Verify that `FileHelper::extractDateTimeFromPath()` returns seconds (it does — pattern `(\d{2})[-_.](\d{2})[-_.](\d{2})` captures H:i:s).

**One-time idempotency impact:** Files whose seconds differ (e.g. metadata `14:30:22` vs filename `14:30:00`) were previously accepted as "reliable" and tagged [O]. After this fix, they will be re-flagged as [W] or [F]. This is a correctness improvement, not a regression. Users may see a one-time batch of [W]/[F] on files that were previously [O].

**Files:** `src/Metadata/ExifMetadataProvider.php`

### Fix 4: Dedup cross-directory original search

**Bug:** `DedupCommand` constructs the original's path by stripping `-duplicate-NNN` and looking in the SAME directory (`$file->getPath() . DIRECTORY_SEPARATOR . $originalBasename`). Cross-directory duplicates (e.g. `backup/photo-duplicate-001.jpg` whose original is `photo.jpg` in root) are flagged as "orphaned" because the original is not found in `backup/`.

**Root cause:** Line 141 of `DedupCommand.php`: `$originalPath = $file->getPath() . DIRECTORY_SEPARATOR . $originalBasename . '.' . $file->getExtension();` — only checks the duplicate's own directory.

**Fix:** Build an index of all non-duplicate files during the initial scan, keyed by `basename + extension`. When looking for a duplicate's original, search this index instead of constructing a same-directory path. This is O(N) total (one scan to build index, O(1) per lookup) instead of O(N*M) filesystem searches.

```php
// During initial scan, build original index:
$originalIndex = []; // 'photo.jpg' => '/root/photo.jpg'
foreach ($files as $file) {
    $basename = FileHelper::basenameWithoutExtension($file);
    if (!str_contains($basename, Constants::DUPLICATE_IDENTIFIER)) {
        $key = $basename . '.' . strtolower($file->getExtension());
        $originalIndex[$key] ??= $file->getPathname();
    }
}

// When checking duplicate:
$originalBasename = FileHelper::stripDuplicateSuffix($basename);
$key = $originalBasename . '.' . strtolower($file->getExtension());
$originalExists = isset($originalIndex[$key]);
```

**Files:** `src/Command/DedupCommand.php`
**Tests:** Scenario #47

### Fix 5: Add safety confirmation to rename:write-date AND rename:dedup (Principle 9)

**Bug:** `rename:write-date` and `rename:dedup` modify files without confirmation prompt. `rename:exif` already prompts. Inconsistent safety behavior.

**Fix:** Add `$io->confirm()` before executing writes/moves/deletes in both commands. Extract pattern into shared `ConfirmableOperationTrait` or inline (3 lines each).

**Files:** `src/Command/WriteDateCommand.php`, `src/Command/DedupCommand.php`

### Fix 8: LP Pair Atomicity — propagate still's quality flags to companion (A5c)

**Bug:** When a LP still has [W] (ambiguous TZ) or [F] (fallback date), the video companion is tagged independently based on its own metadata. The pair is not atomic — the video gets [R] while the still gets [W], producing inconsistent names.

**Root cause:** `DuplicateDetectionService` sets `ambiguousTimezoneFiles` and `fallbackDateFiles` per-file independently. There is no mechanism to propagate a still's quality flags to its LP companion.

**Fix:** After `createDuplicateFilenames()` identifies LP companions (via `detectLivePhotoCompanion()`), add a new LP atomicity pass:
- For each LP pair: if the still is in `ambiguousTimezoneFiles`, add the companion video to `ambiguousTimezoneFiles` too
- For each LP pair: if the still is in `fallbackDateFiles`, add the companion video to `fallbackDateFiles` too
- Exception: if the still is [S] (skipped, no metadata), the pair is broken — video uses its own date independently

This pass runs in `DuplicateDetectionService` so that `RenameResult` carries the correct flags to `RenameOutputRenderer`.

**Files:** `src/Service/DuplicateDetectionService.php`
**Tests:** Scenarios #50, #51, #52, #56, #57

### Fix 9: Tag priority chain — [F] before [D] in RenameOutputRenderer (A5e)

**Bug:** Code checks `isDuplicateTarget` before `fallbackDateFiles`. A duplicate with fallback date shows [D] instead of [F]. The user doesn't see the metadata quality issue.

**Fix:** In `RenameOutputRenderer::buildOutputEntries()`, reorder the if/elseif chain:

**Refactoring approach:** Extract the tag resolution from the inline if/elseif chain into a dedicated `resolveEntryTag()` method on `RenameOutputRenderer`. This makes the priority chain testable, readable, and maintainable.

```php
private function resolveEntryTag(
    string $sourcePathname,
    bool $isDuplicateTarget,
    bool $isNoOp,
    bool $isCanonicalEntry,
    RenameResult $result,
): OutputEntryTag {
    if (isset($result->livePhotoConflictFiles[$sourcePathname])) {
        return OutputEntryTag::Candidate;
    }

    if (isset($result->ambiguousTimezoneFiles[$sourcePathname]) && !$isNoOp) {
        return OutputEntryTag::Warning;
    }

    if (isset($result->fallbackDateFiles[$sourcePathname]) && !$isNoOp) {
        return OutputEntryTag::Fallback;
    }

    if ($isDuplicateTarget && !$isNoOp) {
        return OutputEntryTag::Duplicate;
    }

    if ($isCanonicalEntry || $isNoOp) {
        return OutputEntryTag::Original;
    }

    return OutputEntryTag::Rename;
}
```

Priority chain (top to bottom): `[C] > [W] > [F] > [D] > [O] > [R]`
Exception: `[O]` wins for no-ops (`!$isNoOp` guard on [W], [F], [D]).
Post-hoc override (outside this method): `[R]` or `[F]` + date drift → promoted to `[W]`.

**Files:** `src/Service/RenameOutputRenderer.php`
**Tests:** Scenario #54 (duplicate+fallback), #55 (edit+fallback)

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
| **50** | **lp-still-ambiguous-video-ok** | **A5c** | **LP still [W] + video [R] → both skipped** | **Both [W]** | **Same dir** |
| **51** | **lp-still-fallback** | **A5c** | **LP still [F] + video [R] → both [F]** | **Both [F]** | **Same dir** |
| **52** | **lp-still-no-metadata** | **A5c** | **LP still [S] + video [R] → pair broken** | **Still [S], Video [R]** | **Same dir** |
| **53** | **metadata-conflict** | **A5** | **DateTimeOriginal ≠ Keys:CreationDate → highest priority wins** | **Uses DateTimeOriginal** | **Single** |
| **54** | **duplicate-plus-fallback** | **A5e** | **Duplicate where both have fallback date** | **Both [F]** | **Same dir** |
| **55** | **edit-plus-fallback** | **A5e** | **Edit (-002) with fallback date** | **[F] with `-002` target** | **Same dir** |
| **56** | **lp-still-fallback-video-ambiguous** | **A5c** | **LP still [F] + video [W] → both [F]** | **Both [F]** | **Same dir** |
| **57** | **lp-still-no-metadata-video-ambiguous** | **A5c** | **LP still [S] + video [W] → pair broken** | **Still [S], Video [W]** | **Same dir** |
| **58** | **edit-plus-ambiguous-tz** | **A5e** | **Edit (-002) with ambiguous TZ** | **[W] skipped** | **Same dir** |
| **59** | **interrupted-run-recovery** | **Principle 3** | **Half-renamed collection: some date-named, some camera-named** | **Date-named [O], camera-named [R]** | **Mixed** |

**Removed:** #43 (duplicate of existing #28).

### Test Infrastructure

| Infrastructure | Scenarios | Pattern |
|---------------|-----------|---------|
| `scenarioProvider()` | 01-34, 36, 39-40, 42, 44-46, 48, 51, 53-55 | Single dry-run, output mapping |
| `extractTagAssignments()` (new helper) | 50, 52, 56-58 | Parses `[W]`/`[S]`/`[C]` tags from output for verification |
| `WriteDateFlowTest` (new class) | 37, 38, 41 | Multi-step: write-date → rename:exif |
| `testSkipFallbackOption()` | 35 | Custom method with `--skip-fallback` |
| `testIdempotencyAcrossFormats()` | 49 | Real rename → dry-run → 0 changes |
| `testDedupCrossDirectory()` | 47 | Test rename:dedup command |
| `testInterruptedRunRecovery()` | 59 | Real partial rename → dry-run → correct mixed state |

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
| RAW (CR2, CR3, ARW, NEF, ORF, RW2) | Camera-specific formats, not in SUPPORTED_MEDIA_EXTENSIONS | Use camera manufacturer's converter |
| DNG/TIFF | Untested — imagemeta may support TIFF-based EXIF | Test and add to extensions |
| DST transitions | PHP `DateTimeZone` picks one interpretation during fall-back ambiguity. Spring-forward gaps resolved by PHP default. | No user action needed; inherent in local-time-based EXIF |
| Future-dated files | No date range validation | Processed normally; user should verify camera clock |

---

## Part F: Execution Order

```
Phase A: Implementation Fixes (TDD per fix)
Task 1:  Fix 1 — Stage B skip when dHash=0 (blocks scenario 42)
Task 2:  Fix 8 — LP pair atomicity propagation (blocks scenarios 50-52, 56-57)
Task 3:  Fix 9 — Tag priority chain [F] before [D] (blocks scenarios 54-55)
Task 4:  Fix 2 — AVI guard in WriteDateCommand
Task 5:  Fix 3 — hasReliableDateTime second-precision
Task 6:  Fix 4 — Dedup cross-directory search (index-based, not filesystem scan)
Task 7:  Fix 5 — Safety confirmation in write-date AND dedup
Task 8:  Fix 7 — Shared pipeline logic audit

Phase B: Test Infrastructure
Task 9:  extractTagAssignments() helper for [W]/[S]/[C] tag verification

Phase C: Test Scenarios (TDD per scenario)
Task 10: Scenarios 32-34, 36, 39-42, 44-46, 48 (scenarioProvider)
Task 11: Scenarios 51, 53-55 (scenarioProvider — LP/conflict with mappable output)
Task 11b: Scenarios 50, 52, 56-58 (extractTagAssignments — LP/conflict with [W]/[S] tags)
Task 12: Scenarios 37, 38, 41 (WriteDateFlowTest class)
Task 13: Scenario 35 (testSkipFallbackOption)
Task 14: Scenario 47 (testDedupCrossDirectory)
Task 15: Scenario 49 (testIdempotencyAcrossFormats)
Task 15b: Scenario 59 (testInterruptedRunRecovery)

Phase D: Performance
Task 16: Perf 1 — Video frames 5→3
Task 17: Perf 2 — dHash threshold 20→16
Task 18: Perf 3 — Stage B resolution 1024→512

Phase E: Documentation & Diagnostics
Task 19: Decision matrix docs
Task 20: README workflow update (12 steps)
Task 21: Fix 6 — Actionable verify guidance (--detail flag)
```

**Branch strategy:** All implementation work on a dedicated feature branch (e.g. `feature/masterplan-implementation`). Each task is a separate commit. PR against `main` when all tasks complete.

**TDD strictly enforced:** For every fix and scenario: (1) write the failing test, (2) verify it fails, (3) implement the minimal fix, (4) verify it passes, (5) commit. No implementation without a preceding failing test.

---

## Part G: Definition of Done

The plan is complete when ALL of the following are true:

1. **All 58 scenarios pass** (01-42, 44-59; #43 removed) — `make test` green, zero warnings, zero risky
2. **All 9 fixes implemented** with dedicated tests
3. **Idempotency verified:** Processing an already-processed collection produces 0 changes (scenario #49)
4. **Interrupted run recovery verified:** Half-renamed collection processes correctly (scenario #59)
5. **Performance benchmarks documented:**
   - Perf 1 (video frames): measure before/after for 10 video comparisons
   - Perf 2 (dHash threshold): count full-signal comparisons before/after
   - Perf 3 (Stage B resolution): measure Imagick peak memory before/after
6. **`rename:verify --detail` outputs actionable fix commands** for every skip category ([W], [F], [S], [E], [C])
7. **README "Recommended Workflow" matches A7** (12 steps in 5 phases)
8. **`docs/decision-matrix.md` published** as user-facing reference
9. **`make test` passes** (full CI pipeline: lint, CGL, rector, PHPStan, PHPUnit, jscpd)
10. **Infection mutation score ≥ 55%** (existing baseline maintained)
