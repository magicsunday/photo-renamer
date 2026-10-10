<h1 align="center">Photo Renamer: CLI Tool for Photo Collections</h1>

<p align="center">
  Self-contained command-line tool for tidying large photo and video collections.
</p>

<!-- Row 1: CI / Quality badges -->
<p align="center">
  <a href="https://github.com/magicsunday/photo-renamer/actions/workflows/ci.yml"><img src="https://github.com/magicsunday/photo-renamer/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

<!-- Row 2: Standards / Tooling badges -->
<p align="center">
  <a href="https://phpstan.org/"><img src="https://img.shields.io/badge/PHPStan-max%20level-brightgreen.svg" alt="PHPStan Max Level"></a>
  <a href="https://phpunit.de/"><img src="https://img.shields.io/badge/PHPUnit-13-blue.svg" alt="PHPUnit 13"></a>
  <a href="https://getrector.com/"><img src="https://img.shields.io/badge/Rector-2.0-orange.svg" alt="Rector 2.0"></a>
  <a href="https://www.php-fig.org/psr/psr-12/"><img src="https://img.shields.io/badge/Code%20Style-PSR--12-blue.svg" alt="PSR-12"></a>
</p>

<!-- Row 3: Compatibility badges -->
<p align="center">
  <a href="composer.json"><img src="https://img.shields.io/badge/php-8.5-blue" alt="PHP Version"></a>
</p>

<!-- Row 4: Project badges -->
<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/github/license/magicsunday/photo-renamer" alt="License"></a>
</p>

---

## 📌 Overview
Photo Renamer is a self-contained CLI tool for batch-renaming and deduplicating large photo and video collections. It reads EXIF/QuickTime metadata via [ImageMeta](https://github.com/magicsunday/imagemeta), pairs Apple Live Photos (still + video sharing a Content Identifier), detects duplicates through content hashing and perceptual similarity analysis, and standardises filenames — all without breaking existing folder structures. The tool compiles to a single binary with no runtime dependencies.

| Key     | Value                                                                                          |
|---------|------------------------------------------------------------------------------------------------|
| Package | `magicsunday/photo-renamer`                                                                    |
| PHP     | `>=8.5`                                                                                        |
| Binary  | Self-contained via [static-php-cli](https://github.com/crazywhalecc/static-php-cli)           |

## ❓ What is this?
Photo Renamer processes directories of photos and videos, generating consistent filenames based on EXIF/QuickTime dates, content hashes, or custom patterns. It automatically detects Apple Live Photo pairs (JPEG/HEIC + MOV sharing the same Content Identifier), identifies duplicates across directories using content hashing and multi-stage perceptual similarity analysis (visual similarity scoring, RMSE zone gating, and chroma-aware merge decisions), and provides metadata quality analysis to find and fix broken timestamps.

## 🎯 Why does this exist?
Large photo collections accumulated from multiple devices and backup sources tend to have inconsistent naming, duplicate files across directories, broken Live Photo pairings, and unreliable metadata. Format conversions (JPG↔HEIC), re-imports, and re-saves produce files that are visually identical but have different content hashes. This tool exists to bring order to such collections in a safe, preview-first workflow (`--dry-run`), with perceptual duplicate detection that goes beyond simple hash comparison.

## 🧭 Scope & Non-Goals

**In scope:**

- Recursive cross-directory scanning with EXIF-based, hash-based, pattern-based, and lowercase renaming.
- Apple Live Photo detection and pairing via Content Identifier metadata.
- Duplicate detection via content hash with per-file-type numbering and idempotent re-runs.
- Perceptual duplicate detection: visually identical files with different content hashes (format conversions, re-imports) are merged using multi-signal similarity scoring (dHash, wHash, HF-energy, color histogram, video duration), RMSE zone gating with dHash-adaptive thresholds, and chroma-aware merge veto to prevent false merges of color→grayscale conversions.
- Hash sub-grouping: different files sharing the same EXIF date receive sequential group numbers (`-002`, `-003`, ...).
- Metadata quality analysis (`rename:verify`) and timestamp repair (`rename:write-date`).
- Dry-run preview, skip-duplicates mode, and dedup cleanup (`rename:dedup`).

**Out of scope:**

- Image editing, transcoding, or lossy metadata modification (except `rename:write-date` which writes date tags to fix broken metadata).
- Cloud storage or network-based file access.
- GUI or interactive mode.

## 🧩 Supported commands

| Command          | Description                                                    |
|------------------|----------------------------------------------------------------|
| `rename:exif`    | Renames files by EXIF date (incl. Apple Live Photos).          |
| `rename:hash`    | Groups identical files by content hash and renames duplicates. |
| `rename:lower`   | Converts filenames to lowercase.                               |
| `rename:pattern` | Renames files using a regular expression pattern.              |
| `rename:date`    | Renames files by extracting date components from filenames.    |
| `rename:verify`  | Analyzes photo/video collections for metadata problems.        |
| `rename:write-date` | Writes dates from filenames into EXIF/QuickTime metadata (requires exiftool). |
| `rename:dedup` | Finds and removes files with `-duplicate-` suffixes (move or delete). |

### Shared options

| Option              | Short | Description                                                                              |
|---------------------|-------|------------------------------------------------------------------------------------------|
| `--dry-run`         | `-d`  | Preview actions without changing any files.                                               |
| `--skip-duplicates` | `-s`  | Leave duplicates untouched.                                                               |
| `--skip-fallback`   |       | Skip files whose date comes from the fallback DateTime tag (0x0132) instead of DateTimeOriginal. |
| `--list-all`        |       | Show all files including originals and duplicates.                                        |
| `--show=TAGS`       |       | Filter output by entry type (comma-separated: `R`=renamed, `F`=fallback, `D`=duplicate, `O`=original, `W`=warning, `S`=skipped, `E`=error, `C`=content ID conflict). |
| `--max-date-drift=N`|       | Maximum allowed date drift in days between source filename date and target date. Files exceeding this are skipped with `[W]`. Default: 7. Set to 0 to disable. |

### `rename:exif` options

| Option                      | Short | Default           | Description                                                                                                                    |
|-----------------------------|-------|-------------------|--------------------------------------------------------------------------------------------------------------------------------|
| `--target-filename-pattern` | `-fp` | `Y-m-d_H-i-s-v`  | PHP [date format](https://www.php.net/manual/en/datetime.format.php) pattern for the target filename (without extension).      |
| `--timezone`                |       |                   | Timezone for video files without timezone metadata (e.g. `Europe/Berlin`). Overrides `TIMEZONE` env var.                        |
| `--merge-threshold`         |       | `0.06`            | Maximum RMSE (0.0–1.0) for merging visually similar files. Internal safe limits cap the effective threshold, so lower values only make the policy stricter. Overrides `MERGE_THRESHOLD` env var. |

Supported file types: `jpg`, `jpeg`, `heic`, `heif`, `avi`, `mov`, `mp4`, `m4v`.

> **Timezone conversion:** QuickTime video files (MOV, MP4, M4V) store timestamps in UTC. When no explicit
> timezone info is found in the file metadata, the `--timezone` option (or the `TIMEZONE`
> environment variable / `.env` setting) converts the UTC timestamp to local time. EXIF
> dates in images are not affected (those are already in local camera time).

### `rename:pattern` / `rename:date` options

| Option          | Short | Description                                                       |
|-----------------|-------|-------------------------------------------------------------------|
| `--pattern`     | `-p`  | Regular expression pattern to match filenames.                    |
| `--replacement` | `-r`  | Replacement pattern applied to matches.                           |

`rename:date` uses date placeholders (`{y}`, `{m}`, `{d}`, `{H}`, `{i}`, `{s}`, ...) that are expanded to regex capture groups automatically.

## 🚀 Installation

Prerequisites: Docker.

```bash
git clone https://github.com/magicsunday/photo-renamer.git
cd photo-renamer
cp .env.dist .env
make runtime-build
```

### Run via Docker (recommended)

Build the runtime image once, and rebuild it after code or dependency changes with `make runtime-build`. The wrapper and `make run` use the isolated production image. Set `MEDIA_DIR` to an existing host directory; the container sees only that directory at `/media`:

```bash
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:exif --dry-run --list-all /media
MEDIA_DIR="$HOME/Photos" MEDIA_READ_ONLY=true ./renamer.sh rename:verify /media
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:dedup --dry-run /media
```

Alternatively, use `MEDIA_DIR="$HOME/Photos" make run CMD="rename:exif /media --dry-run"`. Paths with spaces work through the wrapper's quoted arguments. Host paths outside `MEDIA_DIR` are not exposed. For a single file, mount its parent and pass `/media/filename.mov`. Quarantine destinations must remain within the mounted collection.

The runtime has no Composer authentication, SSH-agent mount, development repository mount or external network interface. Code, dependencies and the compiled DI container are read-only. Media are writable for rename/write-date/dedup; set `MEDIA_READ_ONLY=true` for analysis. The Docker `media-cache` volume stores the current UID's private JSON caches at `/cache/private-<UID>/`. `make runtime-cache-clear` purges these JSON files without removing the immutable DI artifact or mounting host media. Development/install commands retain their separate `buildbox` service and credential access.

Default limits are 2 GiB container RAM with no additional swap, 2 CPUs, 128 processes and a 512 MiB `/tmp` tmpfs. PHP uses a configurable finite 1024 MiB budget. ImageMagick preserves 256 MiB memory, 512 MiB map and 30 seconds, and additionally bounds pixel-cache disk to 256 MiB, width/height to 32768 and list length to 64. Local JPEG/HEIC/HEIF and internal PNG coders are enabled; delegates, filters and other coders are disabled. Videos are decoded through ffmpeg/ffprobe with their existing timeouts. Networking is disabled by Docker; FFmpeg's default protocols remain unchanged, and loopback remains available inside the container. These limits bound resources; they do not promise completion within a fixed time or native-decoder recovery after an OOM kill.

Increase `PHP_MEMORY_LIMIT` and `RUNTIME_MEMORY_LIMIT` together when measured collection needs justify it. Large panoramas/sequences may require editing the reviewed ImageMagick policy and rebuilding. The collection's overall comparison complexity is independent of these resource limits.

Cross-group video pairs are streamed in deterministic duration-bucket/path order, with Live Photo identity and current group membership checked before stream fingerprinting. Planning stores candidates plus one pair at a time. Worst-case comparisons remain quadratic when many distinct videos share a duration, and perceptual comparisons inside a capture group can also be quadratic. `MAX_COMPARISON_PAIRS` defaults to 100000 pair visits per capture group and across the entire cross-group video batch. Conflicting known Live Photo content IDs are excluded through a linear index without walking their pairs or consuming pair budget; unknown IDs still compare against every identity. Visited group/membership exclusions consume budget too; stale left rows and buckets containing only one original group are skipped without walking every pair. A positive integer is required; fractions, overflow and unlimited values are rejected. This is a work bound, not a whole-run time guarantee or a promise about any collection size.

Exhaustion reports incomplete analysis and returns a failing exit status. A cross-group failure aborts before file execution. A capture-group failure blocks mutations in that group; unrelated fully analyzed groups may still proceed. Existing-name no-ops remain safe. Split problematic input into smaller batches or review and increase the finite budget after measuring the workload. Avoid splitting paired Live Photos across batches.

Run `make comparison-benchmark` for a reproducible synthetic planning/cache experiment using 1000 paths of one tiny video, one equal-duration bucket (499500 potential pairs), and the actual ffmpeg stream matcher. The second pass reuses its in-process fingerprint cache; persistent metadata/perceptual caches are not measured. The benchmark uses no original media and runs without networking, with a read-only repository, 384 MiB RAM/no extra swap, 256 MiB PHP memory, 0.5 CPU, 64 PIDs and 16 MiB temporary storage. See [benchmark results and limitations](docs/video-comparison-benchmark.md). Build the development image and install dependencies first.

For a real-file LP-heavy profile, build the current production image with `make runtime-build`, then run `make mixed-collection-benchmark COUNT=70000` or `COUNT=100000`. It generates tiny JPEG/HEIC+MOV media with synthetic dates and IDs, scans/parses them through the active EXIF pipeline, checks complete pairing and names, performs actual synthetic renames and verifies a second pass changes nothing. Fresh workers measure cold/warm persistent metadata-cache behavior, peak RSS/PHP memory, sampled temporary disk, comparison-plan and native-launch counts. It mounts only benchmark scripts and uses finite Docker limits; it does not accept an original collection. See [profile method and limits](docs/mixed-collection-benchmark.md). Tiny files with distinct known IDs measure structural scaling and cheap exclusions, not camera-resolution decoding or arbitrary 70k/100k collections.

The synthetic 70000-file profile exhausts the default 1024 MiB PHP budget during group construction. The complete profile succeeds with an increased 2048 MiB worker budget; 100000 files exhaust that budget on the pass after renaming. Pair streaming does not remove the remaining linear model/cache memory cost. Keep PHP/container limits finite and plan capacity using the documented measurements; default settings do not guarantee processing these collection sizes.

Configure environment variables (timezone, cache directory, etc.) in `.env` — see [Configuration](#-configuration).

### Build a standalone binary (optional)

Compiles a self-contained binary via [static-php-cli](https://github.com/crazywhalecc/static-php-cli) with all dependencies (including PHP, Imagick, and ImageMagick) statically linked. The first build takes several minutes; subsequent builds reuse the cached SPC environment.

```bash
make binary
chmod +x renamer
sudo mv renamer /usr/local/bin/
renamer --version
```

## Recommended Workflow

The recommended 5-phase workflow for tidying a large photo/video collection:

### Phase 1: Fix metadata BEFORE renaming

```bash
# Step 1: Analyse the collection
renamer rename:verify ~/Photos                          # overview with issue counts
renamer rename:verify --detail ~/Photos                 # per-file diagnosis with fix commands
renamer rename:verify --detail /path/to/single-file.mov # check a single file

# Step 2: Fix files with no metadata (writes date extracted from filename)
renamer rename:write-date --reason=nodata ~/Photos

# Step 3: Fix files using only ModifyDate (0x0132) instead of DateTimeOriginal
renamer rename:write-date --reason=fallback ~/Photos

# Step 4: Fix QuickTime videos with ambiguous UTC timestamps
# Apple/DJI cameras (CreateDate is real UTC → convert to local time):
renamer rename:write-date --reason=timezone --timezone=Europe/Berlin ~/Photos

# Android/Panasonic/Canon cameras (CreateDate is already local time stored as "UTC"):
renamer rename:write-date --reason=timezone --timezone=Europe/Berlin --local-as-utc ~/Photos
```

All commands show a post-scan summary before processing, so you can see what will happen without reading documentation first.

The `--detail` flag on `rename:verify` shows per-file diagnostics with copy-pasteable fix commands:

```
  clip.mov (2.3 KB)
     Problem:    Ambiguous timezone — QuickTime UTC without offset
     Metadata:   CreateDate (UTC) = 2025:03:10 14:00:00
     Fix:        rename:write-date --reason=timezone --timezone=Europe/Berlin '/path/to/clip.mov'
```

> **About timestamps:** EXIF `DateTimeOriginal` is always local time (the time shown on the camera display). QuickTime videos (MOV, MP4, M4V) store `CreateDate` in UTC without timezone info — the `[W]` warning flags these. There are two cases:
>
> - **Apple/DJI cameras** store real UTC. `--reason=timezone` converts it to local time: `14:00 UTC` → `16:00+02:00`.
> - **Non-Apple cameras** (Android, Panasonic, Canon) store local time as "UTC". Use `--local-as-utc` to add the offset without converting: `14:00 "UTC"` → `14:00+02:00`.
>
> Both modes write `Keys:CreationDate` with the timezone offset. The original `CreateDate` stays untouched.

### Phase 2: Rename

```bash
# Step 5: Preview
renamer rename:exif --dry-run ~/Photos

# Step 6: Execute (prompts for confirmation)
renamer rename:exif ~/Photos
```

Renames all photos and videos to `YYYY-MM-DD_HH-MM-SS-mmm.ext`. Extensions are normalised automatically (e.g. `JPEG` → `jpg`). Live Photo pairs (still + video) receive the same base name. Files with distinct content sharing the same timestamp get sequential sub-group numbers (`-002`, `-003`, ...), while visually identical files (format conversions, re-imports) are merged via perceptual hashing. True duplicates get `-duplicate-NNN` suffixes.

Classification distinguishes matching content hashes (`exact`), accepted visual similarity (`perceptual`), rejected comparisons (`different`) and unavailable evidence (`uncertain`). Decode/hash failures, color vetoes and the effective pixel-difference threshold cannot be overridden by a matching filename or an identical Live Photo companion clip. Companion identity keeps pairing intact while distinct still images retain separate subgroups. Classification hashes are analysis evidence; permanent deletion still requires the separate fresh SHA-256 check described below.

Use `--list-all` to see classification reasons in the EXIF decision log. Each group retains one example and a comparison count per reason, rather than every pair. `-vvv` reports individual decisions with available measurements; missing signals are reported as unavailable, never as a measured zero distance. The public subgroup service can stream typed `MergeDecision` values through its optional `onDecision` callback for callers that need detailed evidence.

### Phase 3: Post-rename verification and drift fix

```bash
# Step 7: Confirm all metadata issues resolved
renamer rename:verify ~/Photos

# Step 8: Fix metadata that disagrees with the (now reliable) filename dates
renamer rename:write-date --reason=drift ~/Photos

# Step 9: Re-run to apply drift fixes (minimal changes expected)
renamer rename:exif ~/Photos
```

> **Why drift comes after rename:** `--reason=drift` compares filename dates with metadata dates. On fresh collections with camera names (`IMG_1234.jpg`), there is no filename date to compare. Only after `rename:exif` produces date-based names does drift detection work.

### Phase 4: Final verification

```bash
# Step 10: Verify idempotency (must show 0 changes)
renamer rename:exif --dry-run ~/Photos
```

### Phase 5: Cleanup

```bash
# Step 11: Preview which duplicates would be moved
renamer rename:dedup --dry-run ~/Photos

# Step 12: Move duplicates (prompts for confirmation)
renamer rename:dedup ~/Photos

# Or delete freshly verified byte-identical duplicates
renamer rename:dedup --delete ~/Photos
```

Files with `-duplicate-NNN` suffixes are cleanup candidates; a suffix alone does not prove identical content. The default action moves candidates to quarantine for review. `--delete` requires fresh SHA-256 equality with a retained original and unchanged file state during verification, after confirmation. Different contents, cross-format conversions and unavailable evidence block deletion and produce a nonzero exit code. `--delete --dry-run` applies the same verification without deleting files. Orphaned candidates are skipped with a warning. State checks do not provide an atomic transaction against concurrent external writers; keep other media writers stopped during cleanup.

Quarantine uses `_duplicates` by default. `--target=review/duplicates` selects another relative subdirectory of the canonical source directory (the containing directory for a single-file source). Absolute paths, parent traversal and the source root itself are rejected. Existing symlinks in any destination component, including dangling links and links pointing inside the source tree, are rejected. The whole batch is checked before any move, with fresh checks around directory creation and before each rename; dry-run validates the same boundary without creating directories. A rejected boundary produces a nonzero exit code and retains affected source files. If a later boundary changes during execution, earlier successful moves remain in quarantine; there is no automatic rollback.

These path checks detect unsafe paths observed at the check boundaries. They do **not** make mkdir/rename atomic against a hostile process swapping directories between a check and the filesystem call. Run cleanup in a directory tree that other users/processes cannot modify concurrently, and stop import/sync jobs for its duration. Application locking alone would not constrain such external writers.

## 💡 Additional Commands

```bash
# Group identical files by content hash
renamer rename:hash --dry-run --skip-duplicates ~/Photos

# Extract and rewrite date fragments in filenames
renamer rename:date --dry-run -p "/^{y}-{m}-{d}.{H}-{i}-{s}(.+)$/" -r "{Y}-{m}-{d}_{H}-{i}-{s}" ~/Photos

# Lowercase all filenames
renamer rename:lower --dry-run ~/Photos

# Rename files using a regular expression pattern
renamer rename:pattern --dry-run -p "/^(.+)(jpeg)$/" -r "${1}jpg" ~/Photos
```

## 📊 Output indicators

Each file in the output is prefixed with a status indicator:

| Tag   | Meaning                                                                              |
|-------|--------------------------------------------------------------------------------------|
| `[O]` | **Original** -- file already has the correct name; no action taken.                  |
| `[R]` | **Rename** -- file will be moved to a new name.                                      |
| `[D]` | **Duplicate** -- file is a duplicate and receives a suffix. An info line below shows which file it duplicates. |
| `[F]` | **Fallback** -- date derived from DateTime (0x0132) instead of DateTimeOriginal.     |
| `[W]` | **Warning** -- date drift between source filename and target exceeds `--max-date-drift` (default 7 days); file is skipped. |
| `[S]` | **Skipped** -- file has no usable metadata (no capture date found).                   |
| `[E]` | **Error** -- metadata could not be read (parser error).                               |
| `[C]` | **Candidate** -- conflicting Live Photo Content Identifier detected across groups.    |

After processing, a summary table shows scanned files, skipped files (no metadata), read errors, planned moves/skips, Live Photo groups, duplicates found, naming collisions, and total files to process.

> **Debug output:** Use `-vvv` to see detailed merge decisions for each pairwise comparison, including RMSE, chroma difference, dHash distance, and timing.

## 🔒 Behaviour & guarantees

- **Failed subgroup analysis:** `rename:exif` skips mutations in the affected capture group and shows the failure reason as a warning. Coherent existing subgroup names remain unchanged; unrelated groups can still be renamed. Retry after resolving the analysis failure. A proposed duplicate filename in a blocked entry is not proof of duplicate identity.
- **Multi-track videos:** Cross-group exact-video matching supports one video stream and at most one audio stream. A matching primary video with additional AV tracks remains a review candidate with an explicit reason; it is not automatically merged.
- **Dry-run first:** All commands support `--dry-run` to preview changes before touching files.
- **Idempotent:** Running the same command twice produces the same result. Files already carrying the correct name keep their name. Duplicate suffixes and hash sub-group numbers are stable across re-runs.
- **Smart time formatting:** When a file's EXIF date has no time information (midnight with zero subseconds), the time portion is omitted from the filename (e.g. `2011-09-09.jpg` instead of `2011-09-09_00-00-00-000.jpg`). Works with any `--target-filename-pattern`.
- **Live Photo pairing:** Still images (JPEG/HEIC/HEIF) + video companions (MOV/MP4/M4V) sharing the same Apple Content Identifier are treated as a pair. The video companion always receives the same base name as its still image, even when the video has its own (different) EXIF timestamp.
- **Unified grouping:** All files with the same EXIF date are placed into one group regardless of their Live Photo Content Identifier. This ensures consistent numbering across the entire timestamp.
- **Hash sub-grouping:** When multiple distinct files share the same EXIF date, they are grouped by content hash. True duplicates (same hash) receive `-duplicate-NNN` suffixes, while different files get sequential group numbers (`-002`, `-003`, ...).
- **Perceptual duplicate detection:** Files that are the same capture but have different content hashes (e.g., JPG↔HEIC format backups, re-imports, re-saves) are detected via a multi-stage perceptual pipeline: Stage A computes a multi-signal similarity score (dHash, wHash, HF-energy, color histogram, video duration) using Imagick with proper sRGB color normalization; Stage B applies a conservative merge policy with dHash-adaptive RMSE thresholds (permissive for identical gradient structure, strict for any change) and a chroma-aware merge veto that prevents color→grayscale conversions from being absorbed as duplicates. Additionally, if all companion videos in a group share the same hash, the stills are treated as duplicates.
- **Subdirectory ordering:** Parent directory files are processed before subdirectories, so the first file encountered in the top-level directory wins the canonical (unsuffixed) name.
- **Safe renames:** Files are never overwritten. An in-memory disk index tracks all occupied paths during a run, and a fallback to the next available duplicate suffix prevents data loss even when multiple files compete for the same target path.
- **Non-destructive:** Original files are moved (renamed in place), never deleted.

## ⚙️ Configuration

The project uses a `.env` file (loaded by Docker Compose) for environment-specific settings. Copy `.env.dist` as a starting point:

```bash
cp .env.dist .env
```

| Variable   | Default         | Description                                                                 |
|------------|-----------------|-----------------------------------------------------------------------------|
| `USERID`   | `1000`          | User ID for the Docker container.                                           |
| `GROUPID`  | `1000`          | Group ID for the Docker container.                                          |
| `TIMEZONE` | `Europe/Berlin` | Default timezone for video files without timezone metadata (see above).      |
| `MAX_DATE_DRIFT` | `7`     | Maximum date drift in days between source filename date and target date. Set to `0` to disable. |
| `MAX_COMPARISON_PAIRS` | `100000` | Positive finite pair-visit limit per capture group and cross-group video batch; excess reports incomplete analysis and fails. |
| `MERGE_THRESHOLD` | `0.06`  | Maximum RMSE (0.0–1.0) for merging visually similar files. Internal safe limits still cap the effective threshold. See `--merge-threshold`. |
| `CACHE_DIR` | `.build/cache` | Development/standalone JSON cache base. Isolated Docker runtime uses `/cache` in the `media-cache` volume. |
| `MEDIA_DIR` | `./images` | Existing host directory bound at `/media` for runtime commands. |
| `MEDIA_READ_ONLY` | `false` | Read-only media mount for analysis; mutating commands need write access. |
| `PHP_MEMORY_LIMIT` | `1024M` | Positive finite PHP byte quantity (optionally K/M/G); unlimited and malformed values fail before startup. |
| `RUNTIME_MEMORY_LIMIT` | `2g` | Container RAM limit; swap limit equals RAM. |
| `RUNTIME_CPUS` | `2.0` | Container CPU allocation limit. |
| `RUNTIME_PIDS_LIMIT` | `128` | Container process/thread limit. |
| `RUNTIME_TMP_SIZE` | `512m` | Bounded temporary tmpfs shared by native tools and PHP. |
| `FILE_LINK_ROOT` | *(empty)* | Source path as seen inside Docker/NAS (e.g. `/srv/photos`). |
| `FILE_LINK_BASE` | *(empty)* | Same path as seen from the terminal host (e.g. `Z:\Photos`). |
| `FILE_LINK_PROTOCOL` | *(empty)* | URI scheme for clickable links: empty = `file://` (opens directory), `photo-select` = custom protocol (opens Explorer with file selected). |

### Concurrent mutations

Mutating `rename:*` commands take one exclusive Symfony filesystem lock before analysis and hold it through confirmation, execution and cleanup. `rename:verify` and `--dry-run` remain available while a mutating run holds the lock. A second mutating run fails immediately instead of waiting with a potentially stale plan. The shared resource covers parent/child source trees and source-path aliases because it is independent of the selected media path.

Cooperating processes must use the **same persistent state base**: `/cache` in the normal Docker runtime's shared `media-cache` volume, or `CACHE_DIR` in development/standalone (default `.build/cache`). Separate cache bases, independent Docker project volumes or hosts do not coordinate automatically. Share the same volume/base when operating on overlapping media. The dedicated `<state base>/locks` directory is owned by the current process UID with `0700` permissions; foreign ownership and symlink redirects fail closed. Keep its parent under trusted control and use a filesystem with working kernel `flock` semantics.

The kernel releases the lock on normal exit, exceptions and process termination, including a forced kill. The lock file intentionally remains. **Do not delete lock files to unlock a run**: deleting a held inode can allow a second process to acquire a different lock. Cache-clear commands leave lock files intact. A lock does not undo changes already completed before an interruption.

Importers, editors and other applications do not take this lock. Stop those writers while running mutations. The rename executor rejects observed external target leaves, including unreadable files and dangling symlinks, preserving its source; checks followed by Symfony rename do not provide atomic protection against a foreign writer creating or replacing a path between the final check and the operating-system rename.

Normal mutating CLI invocations capture each regular source's device, inode, size, modification/change times and streamed SHA-256 **before command analysis**. The mover, duplicate quarantine/deletion and metadata writer compare a fresh observation immediately before mutation. Replaced or edited files are withheld, including identical-byte inode replacements and equal-size edits with restored modification times. An initially unreadable source or a file added after the initial pass cannot be authorized by a later successful read. Symlink leaves do not provide a verified regular-file identity. This scope is activated by the CLI application; direct internal service calls do not initialize it automatically.

The checks require one full source read before analysis and another before each actual mutation, plus a retained fingerprint per source until cleanup. This adds disk I/O, especially for large videos and warm-cache runs; it is deliberately stronger than a size/mtime-only check. Hash-calculator caching is cleared around these reads, independently of classification caches. Source freshness does not authorize duplicate deletion: the separate fresh SHA-256 comparison with a surviving original remains mandatory. No-op files require no execution-time rehash. These observations do not close a foreign-writer race between the final check and mutation; keep importers/editors stopped.

### Private caches and migration

Metadata caches contain absolute media paths, capture times and possibly GPS/device information. Both JSON caches live in `<cache base>/private-<effective UID>/` (`/cache` in the runtime, `CACHE_DIR` in development/standalone), using the actual container UID (`USERID`) and GID (`GROUPID`). On Unix filesystems the directory is `0700` and files are `0600`, including the populated temporary file used for atomic replacement, independently of `umask 0022`. Existing owned private directories and files have their permissions corrected. Shared parents are not chmodded; each user needs permission to create their own child. Changing UID starts a separate cold cache.

Old flat `metadata-cache.json` and `perceptual-signal-cache.json` files are **not imported**. After updating the code and before processing media, run the updated `make cache-clear` with the old `CACHE_DIR` and original UID to remove owned legacy copies. Repeat for any previous cache bases; merely upgrading does not remove old files. The command also purges the current user's private JSON caches and the owned `.build/cache/DependencyContainer.php`. Other users' private children and unrelated files remain untouched. Foreign-owned files, symlinks and hardlinks are rejected; resolve these explicitly as the owner instead of broadening permissions.

Caches have no automatic expiration. Keep them only while repeated analysis needs them, and run `make runtime-cache-clear` (runtime volume) or `make cache-clear` (development/legacy base) after processing when the retained paths/locations are no longer needed. Purging removes files; it does not promise forensic erasure from disks, snapshots or backups. Cache freshness, growth and concurrent-writer consistency are separate concerns.

The executable DI cache stays in `.build/cache/DependencyContainer.php`, outside the private JSON child, and must be protected like application code. Keep the project and cache parents under trusted control: permission/ownership checks do not provide atomic protection against a hostile process replacing directories. Network filesystems and ACL policies must enforce the same owner-only access. `make cache-permissions-check` verifies synthetic GPS/path isolation between two real unprivileged UIDs in a disposable Docker container; it does not use your media.

### Clickable file paths in terminal output

When `FILE_LINK_ROOT` and `FILE_LINK_BASE` are set, file paths in the output become clickable (Ctrl+Click) in terminals that support OSC 8 hyperlinks.

| Terminal | `file://` links | `photo-select://` links |
|----------|----------------|------------------------|
| PhpStorm | Yes | Yes |
| VS Code | Yes | Yes |
| iTerm2 (macOS) | Yes | n/a |
| Windows Terminal | No (`file://` blocked) | Yes (with protocol handler) |

**Basic setup (opens the file's parent directory):**

```env
FILE_LINK_ROOT=/srv/photos
FILE_LINK_BASE=Z:\Photos
```

**Advanced setup — Windows Explorer with file selected:**

```env
FILE_LINK_ROOT=/srv/photos
FILE_LINK_BASE=Z:\Photos
FILE_LINK_PROTOCOL=photo-select
```

The `photo-select` protocol requires a one-time setup on Windows. Open a PowerShell window (**not** as Administrator) and run:

```powershell
powershell -ExecutionPolicy Bypass -File \\YOUR-NAS-IP\docker\renamer\scripts\windows\install-protocol.ps1 -HandlerPath "\\YOUR-NAS-IP\docker\renamer\scripts\windows\photo-select.ps1"
```

> **Note:** Use the NAS IP address, not hostname, if DNS resolution is unreliable. The entire command must be on one line.

This registers a `photo-select://` URI handler that calls `explorer.exe /select` to highlight the clicked file. A VBS wrapper (`photo-select.vbs`) prevents the PowerShell window from flashing.

To uninstall:

```powershell
powershell -ExecutionPolicy Bypass -File \\YOUR-NAS-IP\docker\renamer\scripts\windows\install-protocol.ps1 -Uninstall
```

**Troubleshooting clickable links:**

| Problem | Cause | Solution |
|---------|-------|----------|
| Links not clickable | Terminal doesn't support OSC 8 | Use PhpStorm, VS Code, or iTerm2 |
| "This link type is not supported" | Windows Terminal blocks `file://` | Set `FILE_LINK_PROTOCOL=photo-select` and install the protocol handler |
| PowerShell window flashes briefly | VBS wrapper not registered | Re-run `install-protocol.ps1` (it auto-detects `photo-select.vbs`) |
| Explorer opens but file not found | Path mapping mismatch | Verify `FILE_LINK_ROOT` matches the Docker/NAS source path and `FILE_LINK_BASE` matches the Windows drive letter or mount point |
| Install script produces no output | NAS hostname not resolvable | Use IP address instead of hostname in the script path |
| "Handler: Microsoft.PowerShell.Core\FileSystem::..." | Old install script | Update to latest version and re-run install |
| Links open photo viewer instead of Explorer | `FILE_LINK_PROTOCOL` not set | Set `FILE_LINK_PROTOCOL=photo-select` in `.env` |

### Documentation

This project follows strict documentation standards. Every class and method must have a DocBlock.
- **Classes:** Purpose, high-level responsibility, and architecture.
- **Methods:** Detailed description, including why certain logic was chosen.
- **Parameters:** All parameters must be documented with `@param`.
- **Tests:** Every test must describe exactly what scenario is being tested. No "Standard-Blah-Blah".

See `AGENTS.md` and `CLAUDE.md` for more details.

## 🛠️ Development

Prerequisites: Docker.

Install dependencies:

```bash
make install
```

Run the mandatory quality gate:

```bash
make test
```

`make test` includes:

- Linting (`phplint`)
- Coding standards dry-run (`php-cs-fixer --dry-run`)
- Refactoring dry-run (`rector --dry-run`)
- Static analysis (`phpstan`)
- Architecture layers (`deptrac`, plus the unassigned-class and layer-cycle checks)
- Template lockstep (`check-consumer-config.php`)
- Unit and integration tests (`phpunit`)
- Copy/paste detection (`jscpd`)

The production dependency tree can be checked separately with `make no-dev-smoke`. It installs the application into a temporary isolated `--no-dev` vendor tree, starts the CLI, exercises the runtime process and video fingerprinting paths, and verifies the Write-Date and missing-`exiftool` diagnostics without inheriting development packages.

The tooling configuration is shared with the other `magicsunday/*` projects through
[`magicsunday/coding-standard`](https://github.com/magicsunday/coding-standard), the only
quality-tool entry in `require-dev` besides Infection: it delivers php-cs-fixer, PHPStan and
its rule packs, Rector, phplint, PHPUnit and Deptrac. `.php-cs-fixer.dist.php`, `phpstan.neon`
and `rector.php` only wrap the shared configs (`php-cs-fixer/base.php`, `phpstan/base.neon`,
`rector/base.php`), and `deptrac.yaml` imports the shared layer ruleset
(`deptrac/layers.yaml`). `phpunit.xml`, `.phplint.yml`, `.editorconfig` and `.jscpd.json` are
adapted copies of the package's templates; `composer ci:test:php:templates` keeps them from
drifting.

### Architecture layers

`deptrac.yaml` maps `src/` onto layers that may only depend downwards (lowest first):

```
Exception, Constants < Regex < Model < Helper < Contract < Metadata < Service < Strategy < Command
```

`Model` (all of `src/Model` plus the `TemporalMetadata` value object) and `Service` (all of
`src/Service`) are the shared layers of the same name; `Contract` is the shared port layer and
holds the strategy interfaces the services program against. `Command` is the composition root
(the console application and its commands); no layer depends on it. The file itself documents
why each edge is allowed.

Test the CLI:

```bash
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:exif --dry-run --list-all /media
```

### Individual CI targets

| Target         | Description                          |
|----------------|--------------------------------------|
| `make lint`    | Run PHP linter only.                 |
| `make cgl-check` | Check code style (dry-run).       |
| `make rector-check` | Check Rector rules (dry-run). |
| `make stan`    | Run PHPStan analysis.                |
| `make deptrac` | Check the architecture layers (Deptrac, unassigned classes, layer cycles). |
| `make templates` | Check the config copies against the coding-standard templates. |
| `make unit`    | Run PHPUnit tests.                   |
| `make coverage` | Run PHPUnit with HTML + Clover coverage report (`.build/coverage/`). |
| `make cpd`     | Run copy-paste detection.            |
| `make no-dev-smoke` | Verify the isolated production vendor tree and runtime dependencies. |
| `make runtime-image-check` | Build the runtime image and verify its native media decoder contract. |

### Test images

Generate synthetic test files covering all renamer scenarios (duplicates, Live Photos, timezone, drift, HEIC, cross-directory, perceptual hashing, etc.):

```bash
docker compose run --rm buildbox php scripts/create-test-images.php
MEDIA_DIR="$PWD/tests/Fixtures/Images" MEDIA_READ_ONLY=true ./renamer.sh rename:exif --dry-run --list-all /media
```

### Fix targets

| Target         | Description                          |
|----------------|--------------------------------------|
| `make cgl`     | Auto-fix code style.                 |
| `make rector`  | Apply Rector rules.                  |

### Build the binary

```bash
make binary         # Init SPC environment + compile the renamer binary
make binary-clean   # Remove SPC build artifacts to free space
```

### Other targets

| Target              | Description                                           |
|---------------------|-------------------------------------------------------|
| `make docker-build` | Build the Docker image.                               |
| `make runtime-build` | Build immutable production code, vendor and DI container. |
| `make runtime-cache-clear` | Purge current-UID media JSON in the runtime volume. |
| `make bash`         | Open a bash shell inside the buildbox container.      |
| `make update`       | Update Composer dependencies.                         |
| `make version`      | Create a new version release.                         |

## 💬 Support

* **Bugs or unexpected behaviour:** [Open an issue](https://github.com/magicsunday/photo-renamer/issues).
* **Releases:** [Download page](https://github.com/magicsunday/photo-renamer/releases/latest).
