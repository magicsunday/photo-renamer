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
make deptrac        # Deptrac architecture layers (+ unassigned classes, layer cycles)
make templates      # Config copies vs. the coding-standard templates
make coverage       # PHPUnit with HTML + Clover coverage (.build/coverage/)
make cgl            # Fix code style
make rector         # Apply rector rules
make install        # Composer install
make no-dev-smoke   # Verify the isolated production vendor tree and runtime dependencies
make binary         # Build SPC binary (always via Docker)
make cache-clear    # Clear persistent metadata cache
```

Local pipeline order of `composer ci:test`: phplint → php-cs-fixer (dry-run) → rector (dry-run) → phpstan → deptrac → templates → phpunit → jscpd

`composer ci:test:php:cpd` runs the installed `node_modules/.bin/jscpd`, so the Node dependencies must be installed first (`make install` runs `npm ci`). jscpd is pinned to an exact version in `package.json`. CI runs it as its own job through the shared `cpd.yml` workflow of the `.github` repository, reported as `cpd / Copy-paste detection`.

`make no-dev-smoke` builds a temporary `--no-dev` Composer tree and starts the CLI with it. The smoke test also exercises the production `symfony/process` dependency, video fingerprinting, Write-Date, and the missing-`exiftool` capability diagnostic without using the repository's normal development vendor tree.

### Focused commands

Run focused checks inside the Docker buildbox:

```bash
# Run one test method or file
docker compose run --rm buildbox .build/bin/phpunit --filter testMethodName tests/Unit/Path/To/TestFile.php

# Run PHPStan for one file
docker compose run --rm buildbox .build/bin/phpstan analyze src/Path/To/File.php --memory-limit=-1

# Inspect Deptrac layer assignments
docker compose run --rm buildbox .build/bin/deptrac debug:unassigned
docker compose run --rm buildbox .build/bin/deptrac debug:layer Metadata

# Run one CLI command
make run CMD="rename:exif /path --dry-run"
```

### Shared tooling (`magicsunday/coding-standard`)

- `require-dev` holds `magicsunday/coding-standard` (delivers php-cs-fixer, PHPStan + rule packs, Rector, phplint, PHPUnit, Deptrac) and `infection/infection` only. Do not add those tools individually.
- `.php-cs-fixer.dist.php`, `phpstan.neon` and `rector.php` wrap the shared `php-cs-fixer/base.php`, `phpstan/base.neon` and `rector/base.php` (PHP floor `80500`). Change a shared rule upstream, not here.
- `phpunit.xml`, `.phplint.yml`, `.editorconfig`, `.jscpd.json` are adapted template copies; `composer ci:test:php:templates` (`check-consumer-config.php .`) fails when a strict flag is dropped.

### Dependency injection container

Symfony DI uses autowiring from `config/Services.yaml`. All `src/` classes are auto-registered except `Renamer.php`, `Dependencies.php`, `Constants.php`, and `Model/`; service interfaces are bound explicitly, and `MetadataReader` is created through its static factory. The compiled container is cached at `.build/cache/DependencyContainer.php`, so remove that file after changing `Services.yaml`.

Constructor parameters must not default to `new Foo()`. New collaborators are wired by the container and supplied explicitly by tests; `tests/Unit/Architecture/ConstructorWiringArchitectureTest` enforces this contract.

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
- **Documentation (DocBlocks):**
    - Every class and method MUST have a DocBlock.
    - **Classes:** Describe the specific purpose, high-level responsibility, and architecture. Explain *why* certain design choices were made (e.g., immutability, transitional mapping).
    - **Methods:** Describe *what* they do and *why* they do it that way. Document all parameters (`@param`) with meaningful descriptions and specify return values (`@return`). Explain any critical implementation details or side effects.
    - **Tests:** Every test method must be analyzed and described in detail (what is specifically tested under which conditions). Avoid generic "tests X" descriptions. Explain the business or technical requirement the test verifies.
    - **Enums & Constants:** Document the meaning and use cases of each case or constant. Provide context on how they influence the application's behavior.

## PHPStan

- Level: `max`, strict-rules, deprecation-rules and phpunit extensions — all from the shared `phpstan/base.neon`
- Checked exceptions (from the base): a method that throws a `MagicSunday\` exception documents it with `@throws`, and a `@throws` names only what the body can raise. `LogicException` subclasses are unchecked. Tests are exempt (`missingType.checkedException` ignored under `tests/`).
- **Never** use `@phpstan-ignore` — fix types properly
- No baseline

## PHPUnit

- PHPUnit 13 with attributes: `#[Test]`, `#[CoversClass]`, `#[UsesClass]` — `requireCoverageMetadata` makes `#[CoversClass]`/`#[CoversNothing]` mandatory
- `tests/Unit/Architecture/` holds source-scanning PHPUnit guards (role boundaries, constructor wiring, shape-array returns); `tests/Architecture/` must stay free of PHPUnit tests (the template gate excludes it)
- CamelCase test method names
- `WorkspaceTrait` for temp directory management in tests
- `StubMetadataExtractor` + `LivePhotoFixtureFactory` for test doubles

## Git & Commits

- Commit directly on `main`, or on a `GH-<N>` branch for issue-tracked work.
- Commit subjects — and the pull-request title — are governed by the shared `commit-convention` gate; the normative rule and its full rationale live in `magicsunday/.github/.github/workflows/commit-convention.yml@main`, which self-tests a decision table before applying it. In short: a `GH-`-prefixed subject must match `^GH-\d+: [A-Z]`, every other subject `^[A-Z]` — a capitalised English imperative — and conventional-commit prefixes (`feat:`, `Fix:`, …) as well as path-like starts (`src/…: …`) are rejected whatever their case. It runs on every pull request via `.github/workflows/commit-lint.yml`, advisory until `commit-convention / Commit convention` is a required context in branch protection.
- Branches for an issue are named exactly `GH-<N>`; the `GH-<N>: ` prefix marks work that belongs to that issue, so a drive-by fix on the branch keeps its own unprefixed subject.
- The pull-request body closes the issue with `Closes #<N>` — the `GH-<N>: ` subject prefix is not a GitHub link and closes nothing.
- Never add a `Co-Authored-By:` trailer or any other AI attribution.
- Granular commits — one concern per commit
- **Always** run `make test` before committing

## Design Principles

KISS, SOLID, DRY, YAGNI, GRASP, Law of Demeter, SoC, CoC — in that order of priority.

## Architecture

### Layers (Deptrac)

`deptrac.yaml` imports the shared layer ruleset and maps `src/` onto one acyclic order (lowest first):

```
Exception, Constants < Regex < Model < Helper < Contract < Metadata < Service < Strategy < Command
```

- `Model` = `src/Model/**` + `Metadata/TemporalMetadata` (+ its trait); it depends on nothing outside itself.
- `Contract` = the strategy interfaces (`src/Strategy/**/*Interface.php`). Services use only these, never a concrete strategy.
- `Metadata`, `Strategy` = the rest of their namespaces. `Strategy` may use `Service` (e.g. `SafeHashCalculatorInterface`), never the reverse.
- `Command` (+ `Application`) is the composition root; nothing depends on it.
- `composer ci:test:php:deptrac` also fails on an unassigned class (`deptrac debug:unassigned`) and on a cycle in the measured layer graph (`check-deptrac-cycles.php`). A new top-level namespace needs a layer.
- The imported ruleset is coding-standard's strict, acyclic 3.0 one; the design holds under it. Widen a layer only with a comment explaining the edge.

### Commands (Symfony Console)

| Command | Purpose |
|---------|---------|
| `rename:exif` | Rename by EXIF date (primary command) |
| `rename:hash` | Group duplicates by content hash |
| `rename:pattern` | Regex-based renaming |
| `rename:date` | Extract date from filename patterns |
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

Commands other than `rename:exif` (`rename:hash`, `rename:pattern`, `rename:date`, `rename:lower`) use the legacy execution path via `AbstractRenameCommand`:

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
  Model/Collection/    # AbstractCollection (keyed), AbstractList (int-keyed, append()), concrete collections
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
deptrac.yaml           # Architecture layers (imports the coding-standard ruleset)
tests/
  Unit/                # Unit tests (mirrors src/ structure)
  Unit/Architecture/   # Source-scanning architecture guards
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
