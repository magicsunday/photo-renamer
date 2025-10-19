[![Latest version](https://img.shields.io/github/v/release/magicsunday/photo-renamer?sort=semver)](https://github.com/magicsunday/photo-renamer/releases/latest)
[![License](https://img.shields.io/github/license/magicsunday/photo-renamer)](https://github.com/magicsunday/photo-renamer/blob/main/LICENSE)
[![CI](https://github.com/magicsunday/photo-renamer/actions/workflows/ci.yml/badge.svg)](https://github.com/magicsunday/photo-renamer/actions/workflows/ci.yml)

# Photo Renamer

> **Always start with `--dry-run`.** Preview the changes before touching any files.

Photo Renamer is a self-contained command-line tool that tidies large photo collections. It reads EXIF metadata, understands Apple Live Photos, spots duplicates, and helps you standardise file names without breaking your existing folder structure.

## Install from a release

1. Download the latest binary for your platform from the [releases page](https://github.com/magicsunday/photo-renamer/releases/latest).
2. Make it executable:
   ```bash
   chmod +x renamer
   ```
3. (Optional) Move it somewhere on your `PATH` so you can call it from anywhere:
   ```bash
   sudo mv renamer /usr/local/bin/
   ```
4. Run the binary to confirm it works:
   ```bash
   renamer --version
   ```

Windows users can download the `.exe`, place it in a folder of choice (for example `C:\Tools`), and add that folder to the `PATH` environment variable.

Need to build from source or contribute? See [CONTRIBUTING.md](CONTRIBUTING.md).

## Quick start

List everything the tool can do:
```bash
renamer list
```

Show full help for a command:
```bash
renamer help exif:date
```

Run a command (always start with `--dry-run` to preview changes):
```bash
renamer lowercase --dry-run ~/Photos
```

## Global options and arguments

All commands share a set of options and arguments:

| Option | Short | Description |
| --- | --- | --- |
| `--dry-run` | `-d` | Preview the actions without changing any files. |
| `--copy` | `-c` | Copy files instead of moving them. Original files stay put. |
| `--skip-duplicates` | `-s` | Leave duplicates untouched when copying or renaming. |

| Argument | Required | Description |
| --- | --- | --- |
| `source-directory` | Yes | Folder to scan. Subdirectories are processed recursively. |
| `target-directory` | No | Destination for renamed/copied files. Defaults to the source directory. |

## Command catalogue

Each command focuses on a different aspect of tidying your library. The synopsis below shows the most common flags—run `renamer help <command>` for the complete list.

### `lowercase`

```
renamer lowercase [--dry-run] <source-directory>
```

Converts filenames to lowercase while preserving extensions. Handy for normalising mixed-case collections or preparing files for case-sensitive systems.

### `pattern`

```
renamer pattern [--dry-run] --pattern "<regex>" --replacement "<replacement>" <source-directory>
```

Searches filenames with a regular expression and renames them using a replacement pattern. Perfect for cleaning stray characters or harmonising extensions (for example converting `.jpeg` to `.jpg`). Patterns follow PHP regular-expression syntax. Alias: `rename:pattern`.

### `pattern:date`

```
renamer pattern:date [--dry-run] --pattern "<date-pattern>" --replacement "<replacement>" <source-directory>
```

Targets date fragments inside filenames and rewrites them using placeholders such as `{Y}` (year) or `{H}` (hour). Use it to expand two-digit years, adjust separators, or align file names with your preferred date format. Alias: `rename:date-pattern`.

### `hash`

```
renamer hash [--dry-run] [--skip-duplicates] <source-directory> [<target-directory>]
```

Detects duplicates by content hash. Unique files are moved or copied to the target directory (if provided); duplicates can be skipped or left in place. Great for merging multiple archives while avoiding duplicate clutter.

### `exif:date`

```
renamer exif:date [--dry-run] [--target-filename-pattern <pattern>] <source-directory>
```

Renames photos based on EXIF `DateTimeOriginal`. Apple Live Photo pairs (image + video) are renamed together. Set `--target-filename-pattern` to customise the date/time layout (default `Y-m-d_H-i-s-v`). Files without usable EXIF data remain untouched.

## Example workflows

Follow a staged approach when cleaning a large library. Run each step with `--dry-run`, review the output, then rerun without it once satisfied.

1. Lowercase every filename:
   ```bash
   renamer lowercase --dry-run ~/Photos
   ```
2. Normalise extensions:
   ```bash
   renamer pattern --dry-run --pattern "/^(.+)(jpeg)$/" --replacement "\\1jpg" ~/Photos
    ```
3. Expand date fragments inside filenames:
   ```bash
   renamer pattern:date --dry-run --pattern "/^{y}-{m}-{d}.{H}-{i}-{s}(.+)$/" --replacement "{Y}-{m}-{d}_{H}-{i}-{s}" ~/Photos
   ```
4. Rename based on EXIF metadata:
   ```bash
   renamer exif:date --dry-run ~/Photos
   ```
5. Separate unique files from duplicates:
   ```bash
   renamer hash --dry-run --skip-duplicates ~/Photos ~/Organised
   ```

## Support

* **Bugs or unexpected behaviour:** [Open an issue](https://github.com/magicsunday/photo-renamer/issues).
* **New ideas:** Search existing issues first; feel free to file a feature request if yours is not already covered.
* **Need a fresh binary?** Revisit the [releases page](https://github.com/magicsunday/photo-renamer/releases/latest) for the newest download.
