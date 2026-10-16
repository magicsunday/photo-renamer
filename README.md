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
  <a href="https://getrector.com/"><img src="https://img.shields.io/badge/Rector-2.x-orange.svg" alt="Rector 2.x"></a>
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

A PHP command-line tool for organizing photo and video collections. It reads EXIF/QuickTime metadata, generates consistent filenames, pairs Apple Live Photos and identifies duplicates while preserving directory structure.

## ✨ Features

- Rename by capture date, content hash, filename pattern or lowercase conversion.
- Pair Live Photo stills and videos through their Apple Content Identifier.
- Identify exact duplicates and visually equivalent format conversions with conservative image analysis.
- Inspect metadata quality and repair timestamps with `exiftool`.
- Preview changes, review duplicate candidates in quarantine and verify repeated runs.

Supported media: JPEG/JPG, HEIC, HEIF, AVI, MOV, MP4 and M4V. Metadata parsing uses [ImageMeta](https://github.com/magicsunday/imagemeta).

## 🧰 Requirements

The recommended setup uses Docker with Docker Compose, GNU Make and Bash. The runtime image includes PHP 8.5, production packages, Imagick/ImageMagick, `ffmpeg`, `ffprobe` and `exiftool`; host PHP is not needed.

An optional Linux standalone binary embeds PHP and production packages. Its host needs external media tools for video analysis and metadata writing, and appropriate operating-system isolation. See [standalone setup](docs/development.md#build-a-standalone-binary-optional).

## 🚀 Quick start

```bash
git clone https://github.com/magicsunday/photo-renamer.git
cd photo-renamer
cp .env.dist .env
```

Set `USERID` and `GROUPID` in `.env` for the host filesystem permissions; `id -u` and `id -g` show your IDs. Then build and preview your collection:

```bash
make runtime-build
MEDIA_DIR="$HOME/Photos" MEDIA_READ_ONLY=true ./renamer.sh rename:exif --list-all /media
```

`MEDIA_DIR` must be an existing host directory. The container sees it at `/media`; use that path in commands. Rebuild the runtime after code, configuration or dependency changes.

Keep a backup, inspect the preview and stop importers/editors before applying changes:

```bash
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:exif --apply /media
```

All file-changing commands preview by default. Add `--apply` to perform changes; applying commands ask for confirmation. Standard renames then count source files and display safety verification with progress and a remaining-time estimate before analysis; this reads each source fully and can take time for large videos. See the [collection workflow](docs/usage.md#collection-workflow) for metadata repair, verification and duplicate cleanup.

## 🧩 Commands

| Command | Purpose |
|---|---|
| `rename:exif` | Rename by capture date and pair Live Photos. |
| `rename:hash` | Group byte-identical media by content hash. |
| `rename:pattern` | Rename with a regular expression and replacement. |
| `rename:date` | Extract date components from filenames. |
| `rename:lower` | Lowercase filenames. |
| `rename:verify` | Read-only metadata quality analysis. |
| `rename:write-date` | Repair metadata timestamps using `exiftool`. |
| `rename:dedup` | Quarantine or verify and delete `-duplicate-` candidates. |

```bash
# Inspect metadata quality without writing media
MEDIA_DIR="$HOME/Photos" MEDIA_READ_ONLY=true ./renamer.sh rename:verify --detail /media

# Preview duplicate quarantine
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:dedup /media

# Show command-specific options
MEDIA_DIR="$HOME/Photos" MEDIA_READ_ONLY=true ./renamer.sh help rename:exif
```

Renaming commands support `--apply`, `--list-all` and `--show=TAGS`. Without `--apply`, they only preview media changes. Summaries distinguish planned, unchanged, withheld and completed renames; display filters do not change the counts. See [output indicators](docs/usage.md#output-indicators) for review hints and comparison links. EXIF defaults to `YYYY-MM-DD_HH-MM-SS-mmm.ext`; `--target-filename-pattern` (`-p`) selects another format. `--timezone`, `--max-date-drift` and `--merge-threshold` control timestamp and comparison policy. CLI values take precedence over environment settings.

See [usage and output indicators](docs/usage.md) and the [media decision matrix](docs/decision-matrix.md) for detailed behavior and examples.

## ⚙️ Configuration

Docker Compose reads `.env`. Common settings are:

| Variable | Default | Purpose |
|---|---|---|
| `USERID` / `GROUPID` | `1000` / `1000` | Container filesystem identity. |
| `MEDIA_DIR` | `./images` | Existing collection mounted at `/media`. |
| `MEDIA_READ_ONLY` | `false` | Read-only mount for analysis. |
| `TIMEZONE` | `Europe/Berlin` | Video timestamp interpretation when an offset is missing. |
| `MAX_DATE_DRIFT` | `7` | Allowed filename/metadata drift in days; `0` disables the gate. |
| `MERGE_THRESHOLD` | `0.06` | Operator RMSE ceiling; conservative safety caps also apply. |
| `MAX_COMPARISON_PAIRS` | `100000` | Finite pair visits per capture group and cross-group video batch. |
| `PHP_MEMORY_LIMIT` | `1024M` | Finite PHP analysis budget. |
| `RUNTIME_MEMORY_LIMIT` | `2g` | Container RAM, with no extra swap. |

The runtime uses a private `/cache` volume. All workflows use `CACHE_DIR` when set. Source execution defaults to the project’s `.build/cache`; the standalone binary uses `$HOME/.cache/renamer` or, without `HOME`, the system temporary directory’s `renamer-cache`. See [configuration](docs/configuration.md) for all limits, cache settings and clickable terminal links.

## 🔒 Safe operation and collection size

- Use a backup and inspect the default preview before applying changes. Ctrl+C interrupts Docker runs; a batch may be partially complete, with its private journal supporting inspection and reconciliation.
- Exact/perceptual classification does not authorize permanent deletion. `rename:dedup --delete --apply` requires fresh SHA-256 equality with a surviving original; distinct format conversions cannot pass that byte-identity check.
- Cooperating mutating runs share a lock and verify source identity. Keep directories trusted and other media writers stopped; separate state volumes/hosts do not coordinate automatically.
- The Docker runtime has no external network access and uses a read-only application filesystem, finite resource limits and a private cache. Standalone execution needs its own isolation.
- Capacity depends on media sizes, grouping and comparison buckets, not file count alone. Some comparison workloads are quadratic. Budget exhaustion reports incomplete analysis; default limits do not guarantee a 70,000- or 100,000-file collection fits.

Read [runtime capabilities, failure handling and cache maintenance](docs/runtime-and-safety.md) before processing a large collection or recovering an interrupted run. [Synthetic collection profiling](docs/mixed-collection-benchmark.md) documents how to measure resource needs and the limits of those measurements.

## 🛠️ Development and contributing

Development and quality checks run in Docker:

```bash
make install
make test
make coverage
make runtime-image-check
```

`make test` runs lint, style and refactoring checks, PHPStan, Deptrac, configuration-template checks, PHPUnit and copy/paste detection. Composer audit and mutation testing have separate targets.

Follow [AGENTS.md](AGENTS.md) for coding, review and commit rules. The [development guide](docs/development.md) covers architecture, focused checks, dependency updates, CI, binary builds and releases. Submit changes through a pull request with relevant tests and current documentation.

## 💬 Help

Use command-specific help as shown above or [open an issue](https://github.com/magicsunday/photo-renamer/issues) for bugs and feature requests. Include a reproducible example and diagnostics with personal paths, GPS data, credentials and other sensitive information removed; use synthetic media when possible.

Maintained by [Rico Sonntag (@magicsunday)](https://github.com/magicsunday).

## 📄 License

[MIT](LICENSE).
