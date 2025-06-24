[![Latest version](https://img.shields.io/github/v/release/magicsunday/photo-renamer?sort=semver)](https://github.com/magicsunday/photo-renamer/releases/latest)
[![License](https://img.shields.io/github/license/magicsunday/photo-renamer)](https://github.com/magicsunday/photo-renamer/blob/main/LICENSE)
[![CI](https://github.com/magicsunday/photo-renamer/actions/workflows/ci.yml/badge.svg)](https://github.com/magicsunday/photo-renamer/actions/workflows/ci.yml)

# Photo Renamer

> **Warning:** Use at your own risk! Always try `--dry-run` first.

Photo Renamer is a command-line tool designed to help you organize and rename your photo collection consistently. It provides various renaming strategies based on EXIF data, file patterns, hashes, and more.

## Features

- Rename files based on EXIF date information
- Handle Apple Live Photos (image + video pairs)
- Convert filenames to lowercase
- Rename files using regular expression patterns
- Identify and mark duplicate files
- Convert two-digit years to four-digit years in filenames
- Perform dry runs to preview changes before applying them

This tool is written in PHP and relies on a statically linked PHP binary which is built and provided by the project itself. This makes a local installation of PHP unnecessary and allows the tool to be distributed as a single binary containing all dependencies.

## Common Parameters

All commands support the following parameters:

| Parameter           | Short   | Description                                                                                                                                                                              |
|---------------------|---------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `--dry-run`         | `-d`    | Performs a dry run without actually changing any files. Always use this first to preview changes. This parameter is essential for safely testing operations before applying them.        |
| `--copy`            | `-c`    | Copies files to the target directory instead of renaming/moving them. This preserves the original files in their source location while creating renamed copies in the target location.   |
| `--skip-duplicates` | `-s`    | Skips duplicate files from copy/rename action, leaving them unchanged in the source directory. This is useful when you want to process only unique files and leave duplicates untouched. |

Additionally, all commands require these arguments:

| Argument           | Required  | Description                                                                                                                                                                                                                             |
|--------------------|-----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `source-directory` | Yes       | The source directory containing the files to process. This is the root folder where the tool will look for files to rename. All subdirectories will be processed recursively unless otherwise specified.                                |
| `target-directory` | No        | The target directory for processed files. If omitted, operations take place in the source directory. When specified, files will be moved or copied (with `--copy`) to this location while maintaining the original directory structure. |

## Installation and Setup

To build the tool from source, follow these steps:

1. Clone the repository:
   ```bash
   git clone https://github.com/magicsunday/photo-renamer.git
   cd photo-renamer
   ```

2. Initialize the build environment (choose one method):

   Using Docker (recommended):
   ```bash
   # Sets up the build environment using Docker, ensuring a consistent environment across different machines
   make init-with-docker
   ```

   Without Docker:
   ```bash
   # Sets up the build environment directly on your machine
   make init
   ```

   **Note:** The initialization process downloads, builds, and compiles a PHP binary, which:
   - Requires approximately 1.5 GB of disk space
   - Requires approximately 4 GB of RAM
   - Takes about 10 minutes to complete
   - Uses all available CPU resources

3. Install dependencies:
   ```bash
   # Installs all PHP dependencies required by the project
   bin/composer install
   ```

4. Build the binary:
   ```bash
   # Creates a new binary named 'renamer' in the project root
   make build
   ```

This will create a `renamer` binary in the project root directory.

## Basic Usage

The Photo Renamer tool provides several commands to help you manage and organize your files. Here's how to get started:

### Listing Available Commands

To see all available commands and options:

```bash
./renamer list
```

This command displays a comprehensive list of all available commands, grouped by category, along with a brief description of each command's purpose.

### Getting Help for Specific Commands

To get detailed help for a specific command:

```bash
./renamer help [command]
```

For example, to get help for the `rename:exifdate` command:

```bash
./renamer help rename:exifdate
```

This displays detailed information about the command, including all available options, arguments, and usage examples.

### Basic Command Syntax

All commands follow this general syntax:

```bash
./renamer [command] [options] [arguments]
```

Where:
- `[command]` is the specific renaming operation to perform (e.g., `rename:lower`, `rename:exifdate`)
- `[options]` are the command-specific options and common parameters (e.g., `--dry-run`, `--copy`)
- `[arguments]` are the required and optional arguments (e.g., source directory, target directory)

### Example Usage

Here's a basic example that converts all filenames in a directory to lowercase:

```bash
./renamer rename:lower --dry-run ~/Photos
```

This command performs a dry run of the lowercase conversion operation on all files in the ~/Photos directory, showing what changes would be made without actually modifying any files.

## Command Reference

Photo Renamer provides several commands for different renaming strategies:

### rename:lower

Converts all filenames to lowercase. This command is useful for standardizing your file collection and ensuring consistent naming conventions.

```bash
./renamer rename:lower [--dry-run] <source-directory>
```

**Function**: Transforms uppercase letters in filenames to lowercase while preserving the file extension.

**Use cases**:
- Standardizing a mixed-case file collection
- Preparing files for systems that are case-sensitive
- First step in a multi-step file organization workflow

### rename:pattern

Renames files using regular expression patterns. This powerful command allows for complex pattern matching and replacement in filenames.

```bash
./renamer rename:pattern [--dry-run] --pattern "<regex-pattern>" --replacement "<replacement-pattern>" <source-directory>
```

**Function**: Applies a regular expression search and replace operation on filenames.

**Parameters**:
- `--pattern`: The regular expression pattern to search for in filenames
- `--replacement`: The replacement pattern that will be applied to matching filenames

**Example**: Convert files with extension "jpeg" to "jpg":
```bash
./renamer rename:pattern --dry-run --pattern "/^(.+)(jpeg)$/" --replacement "$1jpg" <source-directory>
```

The search and replacement use PHP's regular expression syntax. See [preg_replace documentation](https://www.php.net/manual/en/function.preg-replace.php) for more details.

**Use cases**:
- Standardizing file extensions
- Removing unwanted characters from filenames
- Adding prefixes or suffixes to filenames
- Complex filename restructuring

### rename:date-pattern

Converts date formats in filenames, such as converting two-digit years to four-digit years. This command is specifically designed for handling date components in filenames.

```bash
./renamer rename:date-pattern [--dry-run] --pattern "<date-pattern>" --replacement "<replacement-pattern>" <source-directory>
```

**Function**: Identifies date components in filenames using special placeholders and transforms them according to the specified replacement pattern. The placeholders in the pattern correspond to the formatting characters of PHP's date [format](https://www.php.net/manual/de/datetime.format.php) function. 

**Parameters**:
- `--pattern`: The pattern with date placeholders to search for in filenames
- `--replacement`: The replacement pattern with date placeholders for the new filename format

**Example**: Convert "18-12-31 22-15-00.jpg" to "2018-12-31_22-15-00.jpg":
```bash
./renamer rename:date-pattern --pattern "/^{y}-{m}-{d}.{H}-{i}-{s}(.+)$/" --replacement "{Y}-{m}-{d}_{H}-{i}-{s}" <source-directory>
```

**Supported date format placeholders**:

| Placeholder  | Description                               |
|--------------|-------------------------------------------|
| Y            | Four-digit year representation            |
| y            | Two-digit year representation             |
| m            | Month with leading zeros (01-12)          |
| d            | Day with leading zeros (01-31)            |
| H            | 24-hour format with leading zeros (00-23) |
| i            | Minutes with leading zeros (00-59)        |
| s            | Seconds with leading zeros (00-59)        |

**Use cases**:
- Converting two-digit years to four-digit years
- Standardizing date formats in filenames
- Reorganizing date components in filenames
- Fixing inconsistent date formatting

### rename:hash

Identifies duplicate files based on their content hash and renames them accordingly. This command is essential for detecting and managing duplicate files in your collection.

```bash
./renamer rename:hash [--dry-run] [--skip-duplicates] <source-directory> [<target-directory>]
```

**Function**: Calculates a unique hash for each file based on its content, identifies duplicates, and either renames them with a sequential number or skips them.

**Behavior**:
- Files are compared based on their content, not just filenames
- The first occurrence of a file is considered the original
- Subsequent duplicates are either renamed with a sequential number or skipped
- When a target directory is specified, unique files are moved/copied there

**Use cases**:
- Identifying and managing duplicate files
- Consolidating files from multiple sources
- Creating a clean, duplicate-free collection

### rename:exifdate

Renames files based on their EXIF date information (DateTimeOriginal). This command is particularly useful for organizing photos, including Apple Live Photos (image + video pairs).

```bash
./renamer rename:exifdate [--dry-run] [--target-filename-pattern <pattern>] <source-directory>
```

**Function**: Extracts the original date and time from a photo's EXIF metadata and renames the file using that information. Only images with valid EXIF metadata and their associated video file are processed. All other files remain untouched in the directory.

Please note: Due to incomplete EXIF metadata (e.g., milliseconds not available), different images (which were captured at the same second, for example) may be saved under the same name and then marked as duplicates, even though they are not true duplicates!

**Parameters**:
- `--target-filename-pattern`: Custom pattern for the new filename (default: "Y-m-d_H-i-s-v"). The format for the pattern is expected in the form specified by the PHP method [format](https://www.php.net/manual/en/datetime.format.php) of the [DateTime](https://www.php.net/manual/en/book.datetime.php) object.

**Behavior**:
- The default filename pattern results in filenames like "2024-01-20_12-10-05-555.jpg"
- Files without valid EXIF data will remain unchanged in the source directory
- For Apple Live Photos, both the image and video components are renamed to match
- The command preserves the original file extension

**Use cases**:
- Organizing photos chronologically
- Standardizing photo filenames based on when they were taken
- Preparing photos for chronological browsing
- Handling Apple Live Photos consistently

## Workflow Examples

Here's a recommended workflow for organizing a collection of photos:

1. **Convert all filenames to lowercase**:
   ```bash
   ./renamer rename:lower --dry-run photos/
   ```

2. **Standardize file extensions** (e.g., convert "jpeg" to "jpg"):
   ```bash
   ./renamer rename:pattern --dry-run --pattern "/^(.+)(jpeg)$/" --replacement "$1jpg" photos/
   ```

3. **Convert two-digit years to four-digit years in filenames**:
   ```bash
   ./renamer rename:date-pattern --dry-run --pattern "/^{y}-{m}-{d}.{H}-{i}-{s}(.+)$/" --replacement "{Y}-{m}-{d}_{H}-{i}-{s}" photos/
   ```

4. **Rename files based on EXIF data**:
   ```bash
   ./renamer rename:exifdate --dry-run photos/
   ```

5. **Identify and handle duplicates**:
   ```bash
   ./renamer rename:hash --dry-run --skip-duplicates photos/ organized-photos/
   ```

After verifying the changes, remove the `--dry-run` option to actually perform the renaming operations.

## Development Guidelines

This section provides guidelines and instructions for developing and maintaining the Photo Renamer project.

### Testing

To run tests and code quality checks:

```bash
# Update dependencies
bin/composer update

# Fix code style issues
bin/composer ci:cgl

# Run all tests and code quality checks
bin/composer ci:test

# Run specific checks
bin/composer ci:test:php:lint       # Check for syntax errors
bin/composer ci:test:php:phpstan    # Run static analysis
bin/composer ci:test:php:rector     # Check for potential code improvements
```
