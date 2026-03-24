<!-- FOR AI AGENTS - Human readability is a side effect, not a goal -->
<!-- Managed by agent: keep sections and order; edit content, not structure -->

# AGENTS.md — magicsunday/photo-renamer

**Precedence:** the **closest `AGENTS.md`** to the files you're changing wins. Root holds global defaults only.

## Index of scoped AGENTS.md

This is a single-scope project. All guidance is in this root file.

## What is this?

PHP CLI tool for batch-renaming photos and videos using EXIF/QuickTime metadata. Runs exclusively inside Docker. Processes JPEG, HEIC, HEIF, AVI, MOV, MP4, and M4V files with Apple Live Photo pairing, duplicate detection, and metadata quality analysis.

- **Namespace:** `MagicSunday\Renamer`
- **PHP:** `^8.5`
- **Framework:** Symfony Console + DI (autowiring via `config/Services.yaml`), Symfony Filesystem for file operations
- **Metadata:** `magicsunday/imagemeta` library (dev-main from GitHub)

## Running CI

All commands run inside Docker via `make`. **Never run PHP, composer, or phpunit directly on the host.**

```bash
make test           # Full CI pipeline (MANDATORY before any commit)
make unit           # PHPUnit only
make stan           # PHPStan only
make coverage       # PHPUnit with HTML + Clover coverage (.build/coverage/)
make cgl            # Fix code style
make rector         # Apply rector rules
make install        # Composer install
make binary         # Build SPC binary (always via Docker)
make cache-clear    # Clear persistent metadata cache
```

CI pipeline order: phplint → php-cs-fixer (dry-run) → rector (dry-run) → phpstan → phpunit → jscpd

## Code Style

- `declare(strict_types=1)` in every PHP file
- PSR-12 + `@Symfony` ruleset via php-cs-fixer
- `use function` imports for all PHP built-in functions (no inline `\strlen()`)
- `final readonly class` for value objects and leaf classes
- One class per file
- `++$i` pre-increment style
- Non-Yoda comparisons (`$x === null`, not `null === $x`)
- In compound conditions (`&&`/`||`), parenthesize `instanceof`/comparison operands: `if (($x instanceof Foo) && ($y === null))`
- `self` in PHP return types, full class name in `@return` PHPDoc
- No `mixed` type, no `empty()`, no nested ternaries

## PHPStan

- Level: `max`
- Includes strict-rules, deprecation-rules, phpunit extensions
- **Never** use `@phpstan-ignore` — fix types properly

## PHPUnit

- PHPUnit 12 with attributes: `#[Test]`, `#[CoversClass]`, `#[UsesClass]`
- CamelCase test method names
- `WorkspaceTrait` for temp directory management in tests
- `StubMetadataExtractor` + `LivePhotoFixtureFactory` for test doubles

## Git & Commits

- Commit directly on `main`
- Conventional Commits format: `feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`
- Granular commits — one concern per commit
- **No** `Co-Authored-By` trailers — never add them
- **Always** run `make test` before committing

## Design Principles

KISS, SOLID, DRY, YAGNI, GRASP, Law of Demeter, SoC, CoC — in that order of priority.

## Architecture

### Commands (Symfony Console)

| Command | Purpose |
|---------|---------|
| `rename:exif` | Rename by EXIF date (primary command) |
| `rename:hash` | Group duplicates by content hash |
| `rename:pattern` | Regex-based renaming |
| `rename:date-pattern` | Extract date from filename patterns |
| `rename:lower` | Lowercase filenames |
| `rename:verify` | Read-only metadata quality analysis |
| `rename:write-date` | Fix metadata timestamps via exiftool (`--reason=nodata,fallback,timezone,drift`) |
| `rename:dedup` | Move/delete `-duplicate-` files |

### Pipeline (rename:exif)

scan → group by target basename (cross-directory) → defer video companions → Live Photo pairing → hash sub-grouping → assign filenames → execute renames

Duplicate detection is **cross-directory**: files with the same EXIF date in different subdirectories land in one group. The canonical is in the shallowest directory. Files stay in their original directory after renaming.

### Key Services

| Service | Responsibility |
|---------|---------------|
| `DuplicateDetectionService` | Grouping, canonical selection, Live Photo pairing |
| `HashSubGroupingService` | Content-hash sub-groups + 2-stage perceptual hash merge (dHash/wHash/HF/color/duration scoring + local blob analysis) |
| `PerceptualHashCalculator` | Multi-signal visual similarity scoring (Imagick-based, with decode hints and caching) |
| `LocalDifferenceAnalyzer` | Pixel-level blob detection for near-identical pairs (Stage B) |
| `FileSystemService` | File I/O, collision resolution |
| `RenameOutputRenderer` | Output formatting, LCS diff highlighting, summary tables |
| `ExifMetadataProvider` | Caching metadata layer, timezone conversion, reliability checks |
| `MetadataExtractor` | Extract EXIF/QuickTime data via imagemeta library |
| `LivePhotoPairingService` | Pair still + MOV by Apple Content Identifier |
| `LivePhotoConflictDetector` | Heuristic detection of mismatched content ID pairs |
| `PerceptualSignalCache` | Persistent JSON disk cache for dHash/wHash/HF/color signals (cross-run reuse) |
| `MetadataCache` | Persistent JSON disk cache for EXIF metadata, keyed by pathname+mtime+size |

### Strategy Pattern

- `RenameStrategyInterface` → filename generation (ExifDate, Pattern, DatePattern, LowerCase, Inherit)
- `MetadataAwareRenameStrategyInterface` → adds `isFallbackDateTime()`, `isAmbiguousTimezone()`, `hasReliableDateTime()`
- `LivePhotoAwareRenameStrategyInterface` → adds `getLivePhotoContentIdentifier()`
- `DuplicateIdentifierStrategyInterface` → grouping (ContentHash, TargetBasename, TargetPathname)

### Critical Patterns

- **`hasReliableDateTime()`** — single source of truth for metadata quality. Used by rename:exif, rename:verify, rename:write-date. A date is reliable when: (a) not fallback AND not ambiguous, OR (b) raw metadata matches filename date.
- **Live Photo pairing** — MOV companions always inherit the paired still's date, never their own. Videos with Content Identifiers are deferred in the first grouping pass.
- **Canonical selection** — already correctly named files (`source basename == target basename`) are always preferred as canonical.
- **Idempotency** — re-running any command on already-processed files produces identical results.
- **Symfony Filesystem** — all file operations (`rename`, `mkdir`, `remove`, `dumpFile`, `readFile`) use `Symfony\Component\Filesystem\Filesystem`. Never use procedural PHP functions for file I/O in production code.

### Output Tags (OutputEntryTag enum)

`[R]` Rename, `[F]` Fallback, `[D]` Duplicate, `[O]` Original, `[W]` Warning, `[S]` Skipped, `[E]` Error, `[C]` Candidate (conflicting content ID)

### Timezone Handling

- QuickTime/MP4 timestamps are stored in UTC (Mac epoch). The `TIMEZONE` env var converts them to local time.
- **Non-Apple cameras** (Panasonic, Canon, etc.) store **local time as UTC** in QuickTime containers. `write-date --reason=timezone` preserves the raw CreateDate and adds `Keys:CreationDate` with the configured TZ offset.
- EXIF dates in JPEG/HEIC are already in local camera time — never convert these.
- Ambiguous timezone detection uses metadata structure (`QuickTimeMeta` presence + no `temporal->tz` + no offset tags), **not** file extensions.
- The PHP container runs in UTC — EXIF dates only appear UTC but are local.

## Project Layout

```
src/
  Command/             # Symfony Console commands
  Command/Concern/     # Shared traits (ConfiguresMetadataProvider)
  Exception/           # Domain exceptions
  Helper/              # FileHelper (path utils, extension normalization, date extraction)
  Metadata/            # ExifMetadataProvider, MetadataExtractor, TemporalMetadata
  Model/               # DTOs: Rename, RenameResult, RenameOptions, OutputEntryTag, FileDuplicate
  Regex/               # SafeRegex wrapper
  Service/             # Core services (see table above)
  Service/LivePhoto/   # Live Photo pairing (7 classes)
  Strategy/            # Rename + duplicate identifier strategies
  Application.php      # Symfony Console app bootstrap
  Constants.php        # Shared constants (DUPLICATE_IDENTIFIER, SUPPORTED_MEDIA_EXTENSIONS)
  Dependencies.php     # DI container builder
  Renamer.php          # CLI entry point
config/
  Services.yaml        # Symfony DI configuration (autowiring)
tests/
  Unit/                # Unit tests (mirrors src/ structure)
  Integration/         # Full command integration tests
  Fixtures/            # WorkspaceTrait, StubMetadataExtractor, LivePhotoFixtureFactory
  Fixtures/Images/     # 29 test image scenarios (verified by TestImageScenariosTest)
.build/
  bin/                 # Compiled binaries
  vendor/              # Composer dependencies
  cache/               # PHPUnit cache, metadata cache
  spc/                 # SPC build environment
Make/                  # Modular Makefile targets
scripts/               # Build and utility scripts
```

## Environment (.env)

| Variable | Purpose | Default |
|----------|---------|---------|
| `USERID` / `GROUPID` | Docker container UID/GID mapping | `1000` |
| `TIMEZONE` | Convert UTC video timestamps to local time | `Europe/Berlin` |
| `MAX_DATE_DRIFT` | Max days drift between filename and metadata date | `7` |
| `CACHE_DIR` | Persistent cache directory | `.build/cache` |
