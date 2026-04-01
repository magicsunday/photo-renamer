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

```
CaptureGroupBuilder.build() → SubgroupClassifier.classify() → RoleAssigner.assign()
→ TargetNameResolver.resolve() → CollisionResolver.resolve() → RenamePlanValidator.validate()
→ ExecutionPlanBuilder.build() → RenameOutputRenderer → FileSystemService.executePlan()
```

Canonical selection uses format-dominant weighted scoring. Subgroup classification happens before role assignment. The pipeline operates on `AssetGroupCollection` / `AssetGroup` / `AssetItem` models. `ExecutionPlanBuilder` projects the group collection into a flat `ExecutionPlan` runtime model consumed by `RenameOutputRenderer` and `FileSystemService.executePlan()`.

### Legacy Execution Path

Commands other than `rename:exif` (`rename:hash`, `rename:pattern`, `rename:date-pattern`, `rename:lower`) use the legacy execution path via `AbstractRenameCommand`:

```
DuplicateDetectionService → FileDuplicateCollection → FileSystemService.renameFiles()
```

This is an intentional bounded exception (End State B). These commands are too simple to benefit from the `ExecutionPlan` runtime model. The legacy path is retained without migration timeline.

### Key Services

| Service | Responsibility |
|---------|---------------|
| `CaptureGroupBuilder` | Steps 1-3: file collection, metadata, capture group formation, LP pairing |
| `SubgroupClassifier` | Content-hash sub-grouping before role assignment (facade over HashSubGroupingService) |
| `CompanionDetector` | Live Photo companion detection (content-ID + basename fallback) |
| `RoleAssigner` | Thin orchestrator: scoring + companion detection + role assignment |
| `CanonicalScorer` | Weighted scoring: format(10000x) > idempotency(1000) > root(50) > LP-ID(25) |
| `TargetNameResolver` | Pure semantic naming from role + group key |
| `CollisionResolver` | Target path deduplication via disk index |
| `RenamePlanValidator` | Pre-execution safety: duplicate targets, case conflicts, circular swaps |
| `ExecutionPlanBuilder` | Projects AssetGroupCollection into ExecutionPlan runtime model |
| `AssetGroupAdapter` | **Deprecated** — retained for differential tests only |
| `DuplicateDetectionService` | Grouping, canonical selection, Live Photo pairing (retained for non-exif commands) |
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
- **Canonical scoring** — format-dominant weighted scoring: format priority (configurable via `CANONICAL_FORMAT_PRIORITY`) dominates all other signals. A preferred format (HEIC) always beats a correctly-named lower-priority format (JPG). Idempotency (1000 pts) only wins within the same format tier.
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
| `CANONICAL_FORMAT_PRIORITY` | Comma-separated format priority for canonical selection | `heic,heif,dng,arw,...` |
