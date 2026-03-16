<h1 align="center">Photo Renamer</h1>

<p align="center">
  Self-contained CLI tool for tidying large photo collections.
</p>

<!-- Row 1: CI / Quality badges -->
<p align="center">
  <a href="https://github.com/magicsunday/photo-renamer/actions/workflows/ci.yml"><img src="https://github.com/magicsunday/photo-renamer/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

<!-- Row 2: Standards / Tooling badges -->
<p align="center">
  <a href="https://phpstan.org/"><img src="https://img.shields.io/badge/PHPStan-level%2010-brightgreen.svg" alt="PHPStan Level 10"></a>
  <a href="https://phpunit.de/"><img src="https://img.shields.io/badge/PHPUnit-12-blue.svg" alt="PHPUnit 12"></a>
  <a href="https://getrector.com/"><img src="https://img.shields.io/badge/Rector-2.0-orange.svg" alt="Rector 2.0"></a>
  <a href="https://www.php-fig.org/psr/psr-12/"><img src="https://img.shields.io/badge/Code%20Style-PSR--12-blue.svg" alt="PSR-12"></a>
</p>

<!-- Row 3: Compatibility badges -->
<p align="center">
  <a href="composer.json"><img src="https://img.shields.io/badge/php-8.4-blue" alt="PHP Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/magicsunday/photo-renamer" alt="License"></a>
</p>

---

## Overview

Photo Renamer reads EXIF metadata, understands Apple Live Photos, spots duplicates, and helps you standardise file names without breaking your existing folder structure. It compiles to a single binary with no runtime dependencies.

| Key     | Value                                            |
|---------|--------------------------------------------------|
| Package | `magicsunday/photo-renamer`                      |
| PHP     | `>=8.4`                                          |
| Binary  | Self-contained via [static-php-cli](https://github.com/crazywhalecc/static-php-cli) |

> **Always start with `--dry-run`.** Preview the changes before touching any files.

## Install

Download the latest binary from the [releases page](https://github.com/magicsunday/photo-renamer/releases/latest):

```bash
chmod +x renamer
sudo mv renamer /usr/local/bin/
renamer --version
```

## Commands

```
 rename
  rename:date      Renames files by extracting date components from filenames.
  rename:exif      Renames files by EXIF date (incl. Apple Live Photos).
  rename:hash      Groups identical files by content hash and renames duplicates.
  rename:lower     Converts filenames to lowercase.
  rename:pattern   Renames files using a regular expression pattern.
```

### Global options

| Option              | Short | Description                                      |
|---------------------|-------|--------------------------------------------------|
| `--dry-run`         | `-d`  | Preview actions without changing any files.       |
| `--copy`            | `-c`  | Copy files instead of moving them.                |
| `--skip-duplicates` | `-s`  | Leave duplicates untouched.                       |
| `--list-all`        |       | Show all files including originals and duplicates. |

| Argument           | Required | Description                                                       |
|--------------------|----------|-------------------------------------------------------------------|
| `source-directory` | Yes      | Folder to scan. Subdirectories are processed recursively.          |
| `target-directory` | No       | Destination for renamed/copied files. Defaults to source directory. |

### `rename:exif`

```bash
renamer rename:exif [--dry-run] [--target-filename-pattern <pattern>] <source-directory>
```

Renames photos based on EXIF `DateTimeOriginal`. Apple Live Photo pairs (image + video sharing the same Content Identifier) are renamed together. The video companion receives the same base name as its still image. Files without usable EXIF data remain untouched.

### `rename:hash`

```bash
renamer rename:hash [--dry-run] [--skip-duplicates] <source-directory> [<target-directory>]
```

Groups files by content hash (xxh128). Unique files keep their name; duplicates receive a numbered suffix per file type.

### `rename:lower`

```bash
renamer rename:lower [--dry-run] <source-directory>
```

Converts filenames to lowercase.

### `rename:pattern`

```bash
renamer rename:pattern [--dry-run] -p "<regex>" -r "<replacement>" <source-directory>
```

Renames files using a regular expression pattern with PHP `preg_replace` syntax.

### `rename:date`

```bash
renamer rename:date [--dry-run] -p "<date-pattern>" -r "<replacement>" <source-directory>
```

Extracts date fragments from filenames using placeholders (`{Y}`, `{m}`, `{d}`, `{H}`, `{i}`, `{s}`) and rewrites them.

## Example workflow

Follow a staged approach when cleaning a large library. Run each step with `--dry-run` first.

```bash
# 1. Lowercase every filename
renamer rename:lower --dry-run ~/Photos

# 2. Normalise extensions
renamer rename:pattern --dry-run -p "/^(.+)(jpeg)$/" -r "${1}jpg" ~/Photos

# 3. Rename based on EXIF metadata (incl. Live Photo pairing)
renamer rename:exif --dry-run ~/Photos

# 4. Separate unique files from duplicates
renamer rename:hash --dry-run --skip-duplicates ~/Photos ~/Organised
```

## Development

Prerequisites: Docker.

```bash
make install       # Install dependencies
make test          # Full CI pipeline (lint, cgl, rector, phpstan, unit, cpd)
make run CMD="rename:exif images --dry-run --list-all"
```

Individual targets:

```bash
make lint          # PHP linter
make cgl-check     # Code style (dry-run)
make rector-check  # Rector (dry-run)
make stan          # PHPStan level 10
make unit          # PHPUnit
make cpd           # Copy-paste detection
make cgl           # Fix code style
make rector        # Apply Rector rules
make bash          # Shell in dev container
```

Build the self-contained binary:

```bash
make init          # Set up SPC build environment
make build         # Compile the renamer binary
```

## Support

* **Bugs:** [Open an issue](https://github.com/magicsunday/photo-renamer/issues)
* **Releases:** [Download page](https://github.com/magicsunday/photo-renamer/releases/latest)
