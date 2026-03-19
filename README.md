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
  <a href="https://phpunit.de/"><img src="https://img.shields.io/badge/PHPUnit-12-blue.svg" alt="PHPUnit 12"></a>
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
Photo Renamer is a self-contained CLI tool that tidies large photo and video collections. It reads EXIF metadata via [ImageMeta](https://github.com/magicsunday/imagemeta), understands Apple Live Photos (image + video pairs sharing a Content Identifier), spots duplicates by content hash, and standardises file names without breaking existing folder structures. The tool compiles to a single binary with no runtime dependencies.

| Key     | Value                                                                                          |
|---------|------------------------------------------------------------------------------------------------|
| Package | `magicsunday/photo-renamer`                                                                    |
| PHP     | `>=8.5`                                                                                        |
| Binary  | Self-contained via [static-php-cli](https://github.com/crazywhalecc/static-php-cli)           |

## ❓ What is this?
Photo Renamer processes directories of photos and videos, generating consistent filenames based on EXIF dates, content hashes, or custom patterns. Apple Live Photo pairs (JPEG/HEIC + MOV sharing the same Content Identifier) are automatically detected and renamed together.

## 🎯 Why does this exist?
Large photo collections accumulated from multiple devices and backup sources tend to have inconsistent naming, duplicate files, and broken Live Photo pairings. This tool exists to bring order to such collections in a safe, preview-first workflow (`--dry-run`).

## 🧭 Scope & Non-Goals

**In scope:**

- Recursive directory scanning with EXIF-based, hash-based, pattern-based, and lowercase renaming.
- Apple Live Photo detection and pairing via Content Identifier metadata.
- Duplicate detection via content hash with per-file-type numbering and idempotent re-runs.
- Hash sub-grouping: different files sharing the same EXIF date receive sequential group numbers (`-002`, `-003`, ...).
- Dry-run preview and skip-duplicates mode.

**Out of scope:**

- Image editing, transcoding, or metadata modification.
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

### Shared options

| Option              | Short | Description                                                                              |
|---------------------|-------|------------------------------------------------------------------------------------------|
| `--dry-run`         | `-d`  | Preview actions without changing any files.                                               |
| `--skip-duplicates` | `-s`  | Leave duplicates untouched.                                                               |
| `--skip-fallback`   |       | Skip files whose date comes from the fallback DateTime tag (0x0132) instead of DateTimeOriginal. |
| `--list-all`        |       | Show all files including originals and duplicates.                                        |
| `--show=TAGS`       |       | Filter output by entry type (comma-separated: `R`=renamed, `F`=fallback, `D`=duplicate, `O`=original, `W`=warning, `S`=skipped, `E`=error). |
| `--max-date-drift=N`|       | Maximum allowed date drift in days between source filename date and target date. Files exceeding this are skipped with `[W]`. Default: 30. Set to 0 to disable. |

### `rename:exif` options

| Option                      | Short | Default           | Description                                                                                                                    |
|-----------------------------|-------|-------------------|--------------------------------------------------------------------------------------------------------------------------------|
| `--target-filename-pattern` | `-fp` | `Y-m-d_H-i-s-v`  | PHP [date format](https://www.php.net/manual/en/datetime.format.php) pattern for the target filename (without extension).      |
| `--timezone`                |       |                   | Timezone for video files without timezone metadata (e.g. `Europe/Berlin`). Overrides `TIMEZONE` env var.                        |

Supported file types: `jpg`, `jpeg`, `heic`, `mov`, `mp4`.

> **Timezone conversion:** QuickTime/MP4 files store timestamps in UTC. When no explicit
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

Install from the [releases page](https://github.com/magicsunday/photo-renamer/releases/latest):

```bash
chmod +x renamer
sudo mv renamer /usr/local/bin/
renamer --version
```

## 📋 Recommended Workflow

The recommended workflow for tidying a large photo/video collection:

### Step 1: Analyse the collection

```bash
renamer rename:verify ~/Photos
```

Review the report to understand what problems exist: missing metadata, ambiguous timezones, broken Live Photo pairs, unrecognized file types.

### Step 2: Fix broken metadata

```bash
# Preview what would be written
renamer rename:write-date --dry-run ~/Photos

# Write dates from filenames into metadata (requires exiftool)
renamer rename:write-date ~/Photos
```

Fixes files flagged as `[W]` (ambiguous timezone), `[F]` (fallback date), or with date drift. Only touches files where the metadata is missing or unreliable.

### Step 3: Rename by EXIF date

```bash
# Preview
renamer rename:exif --dry-run ~/Photos

# Execute
renamer rename:exif ~/Photos
```

Renames all photos and videos to `YYYY-MM-DD_HH-MM-SS-mmm.ext`. Extensions are normalised automatically (e.g. `JPEG` → `jpg`). Live Photo pairs (JPG + MOV) receive the same base name. Duplicates get `-duplicate-NNN` suffixes.

### Step 4: Review warnings

```bash
# Show only warnings and errors
renamer rename:exif --dry-run --show=W,E ~/Photos
```

Files with `[W]` were skipped because their metadata is ambiguous. Go back to Step 2 for these, or fix them manually with exiftool.

## 💡 Additional Commands

```bash
# Group identical files by content hash
renamer rename:hash --dry-run --skip-duplicates ~/Photos ~/Organised

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
| `[R]` | **Rename** -- file will be moved (or copied) to a new name.                          |
| `[F]` | **Fallback** -- date derived from DateTime (0x0132) instead of DateTimeOriginal.     |
| `[D]` | **Duplicate** -- file is a duplicate and receives a suffix.                          |
| `[O]` | **Original** -- file already has the correct name; no action taken.                  |
| `[W]` | **Warning** -- date drift between source filename and target exceeds `--max-date-drift` (default 30 days); file is skipped. |
| `[S]` | **Skipped** -- file has no usable metadata (no capture date found).                   |
| `[E]` | **Error** -- metadata could not be read (parser error).                               |

After processing, a summary table shows scanned files, skipped files (no metadata), read errors, planned moves/copies/skips, Live Photo groups, duplicates found, naming collisions, and total files to process.

## 🔒 Behaviour & guarantees

- **Dry-run first:** All commands support `--dry-run` to preview changes before touching files.
- **Idempotent:** Running the same command twice produces the same result. Files already carrying the correct name keep their name. Duplicate suffixes and hash sub-group numbers are stable across re-runs.
- **Smart time formatting:** When a file's EXIF date has no time information (midnight with zero subseconds), the time portion is omitted from the filename (e.g. `2011-09-09.jpg` instead of `2011-09-09_00-00-00-000.jpg`). Works with any `--target-filename-pattern`.
- **Live Photo pairing:** JPEG/HEIC + MOV files sharing the same Apple Content Identifier are treated as a pair. The video companion always receives the same base name as its still image, even when the video has its own (different) EXIF timestamp.
- **Unified grouping:** All files with the same EXIF date are placed into one group regardless of their Live Photo Content Identifier. This ensures consistent numbering across the entire timestamp.
- **Hash sub-grouping:** When multiple distinct files share the same EXIF date, they are grouped by content hash. True duplicates (same hash) receive `-duplicate-NNN` suffixes, while different files get sequential group numbers (`-002`, `-003`, ...).
- **Semantic duplicate detection:** Files that are the same capture but have different hashes (e.g., re-saved JPEGs) are detected as duplicates via two heuristics: (1) if all companion videos in a group share the same hash, the stills are treated as duplicates; (2) if the EXIF timestamp includes non-zero subsecond precision, files sharing that exact millisecond are treated as duplicates.
- **Subdirectory ordering:** Parent directory files are processed before subdirectories, so the first file encountered in the top-level directory wins the canonical (unsuffixed) name.
- **Safe renames:** Files are never overwritten. An in-memory disk index tracks all occupied paths during a run, and a fallback to the next available duplicate suffix prevents data loss even when multiple files compete for the same target path.
- **Non-destructive:** Original files are moved or copied, never deleted.

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
| `MAX_DATE_DRIFT` | `30`    | Maximum date drift in days between source filename date and target date. Set to `0` to disable. |
| `CACHE_DIR` | `.build/cache` | Directory for the persistent metadata cache. Speeds up subsequent runs by skipping unchanged files. |

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
- Unit tests (`phpunit`)
- Copy/paste detection (`jscpd`)

Test the CLI:

```bash
make run CMD="rename:exif images --dry-run --list-all"
```

### Individual CI targets

| Target         | Description                          |
|----------------|--------------------------------------|
| `make lint`    | Run PHP linter only.                 |
| `make cgl-check` | Check code style (dry-run).       |
| `make rector-check` | Check Rector rules (dry-run). |
| `make stan`    | Run PHPStan analysis.                |
| `make unit`    | Run PHPUnit tests.                   |
| `make cpd`     | Run copy-paste detection.            |

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
| `make bash`         | Open a bash shell inside the buildbox container.      |
| `make update`       | Update Composer dependencies.                         |
| `make version`      | Create a new version release.                         |

## 💬 Support

* **Bugs or unexpected behaviour:** [Open an issue](https://github.com/magicsunday/photo-renamer/issues).
* **Releases:** [Download page](https://github.com/magicsunday/photo-renamer/releases/latest).
