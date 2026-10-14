<!-- FOR AI AGENTS - Human readability is a side effect, not a goal -->
<!-- Managed by agent: keep sections and order; edit content, not structure -->

# AGENTS.md — magicsunday/photo-renamer

**Precedence:** the **closest `AGENTS.md`** to the files you're changing wins. Root holds global defaults only.

## Index of scoped AGENTS.md

This is a single-scope project. All guidance is in this root file.

## What is this?

PHP CLI tool for batch-renaming photos and videos using EXIF/QuickTime metadata. Development, tests, builds and normal media runs use Docker; the optional Linux standalone binary has separately documented external-tool and isolation requirements. Processes JPEG, HEIC, HEIF, AVI, MOV, MP4, and M4V files with Apple Live Photo pairing, duplicate detection, and metadata quality analysis.

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
make mutation       # Infection with configured score gates; failures propagate
make cgl            # Fix code style
make rector         # Apply rector rules
make install        # Composer install
make no-dev-smoke   # Verify the isolated production vendor tree and runtime dependencies
make runtime-image-check # Verify the runtime image and native media decoder contract
make runtime-build # Build immutable production code/vendor/DI artifact
make binary         # Build SPC binary (always via Docker)
make cache-clear    # Purge current-user JSON caches, owned legacy files and DI cache
make runtime-cache-clear # Purge runtime-volume JSON only, preserving immutable DI
make cache-prune    # Prune missing development cache paths, expected media must be accessible
make runtime-cache-prune # Prune runtime cache paths with MEDIA_DIR mounted read-only
make metadata-cache-benchmark COUNT=100000 # Isolated synthetic metadata load/flush/prune profile
make cache-permissions-check # Verify real two-UID cache privacy in disposable Docker
make comparison-benchmark # Compare cold/warm stream-cache planning with 1,000 tiny synthetic videos
make mixed-collection-benchmark COUNT=70000 # Real synthetic JPG/HEIC+MOV: pipeline, cache, mutation, idempotency
```

Local pipeline order of `composer ci:test`: phplint → php-cs-fixer (dry-run) → rector (dry-run) → phpstan → deptrac → templates → phpunit → jscpd

`make test` excludes the separate audit/mutation targets. Composer audit consumes `--locked` (production plus development) and runs inside the required PR `build (8.5)` job; a failed audit fails that context. Security repeats the locked audit on PR/push/schedule/manual events. Its full PCOV mutation job runs only weekly/manual with the unchanged 68% covered-MSI gate, 45-minute timeout and `.github/ci-mutation.compose.yaml` finite 4-GiB/no-extra-swap/4-CPU/256-PID limits. Preserve failure statuses and strict test guards. Mutation is a scheduled/manual report, not a required PR context; document protection separately. Match CI container UID/GID to the checkout owner; do not use root to conceal workspace permission failures.

Quality Make recipes must propagate coverage/mutation tool failures; never prefix them with `-` or suppress their exit codes. The development image includes GNU Make for actual recipe-status regression tests without nested Docker. Keep strict PHPUnit coverage metadata/risky/warning guards. PCOV/Clover measures statements, not branch coverage or mutation safety; actual file-state, negative safety and repeated-execution contracts remain mandatory alongside plan/renderer/architecture checks.

`composer ci:test:php:cpd` runs the installed `node_modules/.bin/jscpd`, so the Node dependencies must be installed first (`make install` runs `npm ci`). jscpd is pinned to an exact version in `package.json`. CI runs it as its own job through the shared `cpd.yml` workflow of the `.github` repository, reported as `cpd / Copy-paste detection`.

`make no-dev-smoke` builds a temporary `--no-dev` Composer tree and starts the CLI with it. The smoke test also exercises the production `symfony/process` dependency, video fingerprinting, Write-Date, and the missing-`exiftool` capability diagnostic without using the repository's normal development vendor tree.

`make cache-permissions-check` uses root only inside a disposable Docker container to launch children that permanently drop to UID/GID 1000 and 1001. It checks actual denied access to synthetic GPS/path JSON and foreign-owner rejection. The runtime-image CI lane runs this check with production dependencies.

`make mixed-collection-benchmark` requires a rebuilt production image. It mounts only `scripts/` read-only, generates real 64×64 JPEG/HEIC and one-second MOV files with synthetic Apple IDs/dates, and profiles the real scanner/parser/pipeline/executor in fresh cold/warm/mutate/idempotency workers. The mutation worker uses the same DI-shared batch lock, source guard and journal as the CLI; renderer measurements use a non-buffering Console sink. Report fixed memory checkpoints for file-list/index, grouping, classification, plan, output and cache load/flush, including live PHP allocations, cumulative PHP/RSS peaks, effective PHP/container limits and tmpfs capacity. Profile limits: 4 GiB container RAM/no extra swap, 1 CPU, 128 PIDs, 1 GiB non-executable media/cache tmpfs, 3072 MiB worker PHP, 20 minutes per worker; a separate 1 MiB executable tmpfs holds only fixed native-launch counters. These increased profiling limits do not change production defaults; retain failed lower-budget measurements. Counts must be multiples of four from 4 to 100000. Report Linux worker peak RSS, PHP peak, 100-ms sampled temporary disk, consumed comparison plans and positively validated native-launch counts. The LP-heavy profile uses distinct known IDs and cannot represent unknown-ID worst cases, camera-size decoding or arbitrary collection throughput. Never run it on original media or mark missing measurements as passed.

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
MEDIA_DIR=/photos make run CMD="rename:exif /media --dry-run"
```

### Shared tooling (`magicsunday/coding-standard`)

- `require-dev` holds `magicsunday/coding-standard` (delivers php-cs-fixer, PHPStan + rule packs, Rector, phplint, PHPUnit, Deptrac) and `infection/infection` only. Do not add those tools individually.
- Infection must use the PHPUnit-13-compatible `^0.35.2` constraint and its locked package reference. Verify the actual mutation initial run and completion; never work around its test-history warning by weakening PHPUnit guards or lowering mutation-score thresholds.
- `.php-cs-fixer.dist.php`, `phpstan.neon` and `rector.php` wrap the shared `php-cs-fixer/base.php`, `phpstan/base.neon` and `rector/base.php` (PHP floor `80500`). Change a shared rule upstream, not here.
- `phpunit.xml`, `.phplint.yml`, `.editorconfig`, `.jscpd.json` are adapted template copies; `composer ci:test:php:templates` (`check-consumer-config.php .`) fails when a strict flag is dropped.

Application installs consume the versioned `composer.lock`; `scripts/check-lock.sh` fails missing, empty, unreadable or symlinked inputs before installers. CI/runtime/binary/no-dev copies include that lock; updates stay explicit through `make update` and require a reviewed diff/full verification. `config/build-inputs.env` pins SPC and PHP source inputs. Verify archive, executable and selected PHP source bytes before extraction/execution/compilation; caller ENV never supplies expected digests. `bootstrap-state.sh` and `native-inputs.sh` record/revalidate owned cache input/output/native source identities, not signatures against hostile owners. Failed caches remain for inspection; explicit `make binary-clean` removes only generated bootstrap outputs/tools. Builder prerequisites live in the pinned image so fresh compile containers work. Use the image's Composer PHAR instead of another installer/packed Composer binary. Download and compile run in distinct containers; compile/package receive empty GITHUB_TOKEN/GH_TOKEN/COMPOSER_AUTH and clear them in the parent. All binary PHP/SPC steps go through `build-with-docker.sh`, never host PHP. Public build manifests contain commit/workspace state, source/lock digests, actual package references, tools/native inputs and pinned bases; never serialize ENV, absolute checkout paths, media/cache/journal data or secrets. Verify actual immutable vendor/lock/package references in the runtime-image CI lane and use harmless local fixtures for integrity/token/changed-cache failures. Do not claim bit-identical builds or missing native measurements as passed.

### Dependency injection container

Symfony DI uses autowiring from `config/Services.yaml`. All `src/` classes are auto-registered except `Renamer.php`, `Dependencies.php`, `Constants.php`, and `Model/`; service interfaces are bound explicitly, `MediaTypeClassifier` owns extension and media-family pair rules directly, and `MetadataReader` is created through its static factory. The compiled container is cached at `.build/cache/DependencyContainer.php` with Symfony resource metadata (`.meta`/`.meta.json`). `ConfigCache(..., true)` validates YAML/class wiring and installed-package resources before normal reuse; never use `debug=false` as a freshness check. Development rebuilds stale resources automatically. Ship the resource files with the immutable runtime image and rebuild that image after code/vendor/config changes; runtime cache-clear preserves all DI artifacts. Only an actually running immutable PHAR (`Phar::running()`) uses its packaged container without checking obsolete external build paths; a missing packaged container fails explicitly. Test a real small archive after deleting its synthetic build tree. Keep executable cache/resource parents trusted.

Collaborators are explicit constructor dependencies wired by the container and supplied by tests. Compiled-container and CLI tests verify actual wiring and its failure behavior.

Normal media runs (`renamer.sh`, `make run`) use the separate `runtime` service: no dev credentials/mounts, network none, read-only root, media at `/media`, private JSON in the `media-cache` volume at `/cache`. Build with `make runtime-build` after code/vendor/config changes; its precompiled DI file is immutable. `buildbox` remains for installation/development. `make runtime-image-check` verifies the actual production image, default resource limits, dummy-host credential isolation, all supported formats and tiny-policy rejection (32 pixels, list length 2, separate 1-KiB disk budget). The check runs on PRs as well as pushes. New shell scripts use `.sh`.

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
    - Local audit working notes in `docs/audit/` are ignored and must never be committed or copied into public issues/artifacts; repository documentation describes supported behavior.
    - README is the concise current entry point: purpose, requirements, quick start, usage/configuration, contribution/help/license links. Keep detailed operating and architecture guidance in linked docs; do not add change histories, removed-symbol inventories or past benchmark runs to README.
    - When deleting or moving code, review adjacent inline comments and the enclosing DocBlocks. Remove orphan section labels, references to removed symbols, and claims that no longer match the implementation. Include comments, examples and current documentation in every code review; retain historical plans only with an explicit historical-status notice.
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

- PHPUnit 13 with attributes: `#[Test]`, `#[CoversClass]`, `#[UsesClass]` — `requireCoverageMetadata` makes `#[CoversClass]`/`#[CoversNothing]` mandatory. Broad CLI/media integration tests use `#[CoversNothing]`; focused rule and cache tests keep class-level coverage metadata.
- Deptrac checks dependency direction; PHPUnit protects observable behavior, file state and actual wiring. Private class names, suffixes, imports and parameter layouts do not need separate source-scanning tests. `tests/Architecture/` stays free of PHPUnit tests (the template gate excludes it).
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
- Merge pull requests exclusively with **squash** (`gh pr merge --squash`); the squash commit subject must satisfy the shared commit convention. Write a dedicated title and body describing the final implemented changes, their purpose and relevant validation; supply the body with `--body-file` rather than concatenating the branch commit messages. Preserve the required DCO `Signed-off-by:` trailer. Never create a "Merge pull request" commit. Merge only after all acceptance criteria from the issue body **and every issue comment, PR review and inline review comment** are fulfilled, individually evidenced and checked, own code review is complete, and relevant GitHub checks pass for the exact reviewed head. Read all comment pages before implementation and again before merge; consolidate additional requirements instead of treating an issue-body checklist or green CI as complete acceptance. Bot UI controls are not acceptance requirements. Do not close an issue with unresolved acceptance work; if splitting implementation across PRs, identify remaining work explicitly and keep the issue open.
- `scripts/create-version` requires clean worktree/index and a configured remote branch, rejects existing local/remote tags before writing, tests the proposed version via `make test`, and rechecks branch/HEAD/version bytes/only-own-change before a signed-off version-only commit. Publish only that branch and tag with one atomic push pinned to the verified release commit, never a moving HEAD; no independent fallback, force, reset or foreign stash. Test against temporary Git/bare repositories with stub Make/controlled Git failures. Failed gates retain the proposed version without commit/tag/push; failed publication retains local commit/tag and requires remote-ref inspection, including lost-acknowledgement ambiguity. Never execute an actual project release as a test.
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

`AbstractMediaRenameCommand` shares only CLI/options/source/confirmation/scan reporting. `AbstractRenameCommand` adds the legacy duplicate execution; EXIF extends the common base directly. The pipeline owns scoring/classification setup and nullable detach/default cleanup in finally, receiving explicit scalar merge/list format values and cache storage separately. Per-group classification cleanup precedes physical mutation. Keep source-mode reset on every invocation, existing single-file/filter/confirmation semantics and truthful result-to-exit propagation; never hide collaborators in a service-locator bundle.

This is an intentional bounded exception (End State B). These commands are too simple to benefit from the `ExecutionPlan` runtime model. The legacy path is retained without migration timeline. The legacy grouping and duplicate candidate factory use the existing shared `TargetFileResolver`/`TargetPathResolver` directly. Keep their filename-only validation, relative layout and strategy-error/skip translation in those shared services; do not reintroduce pass-through LegacyTarget resolver wrappers.

### Key Services

| Service | Responsibility |
|---------|---------------|
| `CaptureGroupBuilder` | Steps 1-3: file collection, metadata, capture group formation, LP pairing |
| `SubgroupClassifier` | Direct source-only content classification before role assignment; atomic cluster projection |
| `CompanionDetector` | Live Photo companion detection (content-ID + basename fallback) |
| `RoleAssigner` | Thin orchestrator: scoring + companion detection + role assignment |
| `CanonicalScorer` | Weighted scoring: format(10000x) > idempotency(1000) > root(50) > LP-ID(25) |
| `TargetNameResolver` | Pure semantic naming from role + group key |
| `CollisionResolver` | Target path deduplication via disk index |
| `RenamePlanValidator` | Pre-execution safety: duplicate targets, case conflicts, circular swaps |
| `ExecutionPlanBuilder` | Projects AssetGroupCollection into ExecutionPlan runtime model |
| `DuplicateDetectionService` | Grouping, canonical selection, Live Photo pairing (retained for non-exif commands) |
| `ContentClassifier` | Shared source-only exact/perceptual membership and streamed evidence (Stage A/B, finite pair budgets) |
| `HashSubGroupingService` | Legacy naming adapter over ContentClassifier; subgroup suffixes, directory conflicts and companion idempotency |
| `PerceptualHashCalculator` | Multi-signal visual similarity scoring (Imagick-based, with decode hints and caching) |
| `LocalDifferenceAnalyzer` | Grayscale RMSE and mean chroma measurements with explicit availability (still-image Stage B) |
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
- **Live Photo pairing** — MOV companions always inherit the paired still's date, never their own. Videos with Content Identifiers are deferred in the first grouping pass. Per-content-ID state stores computed targets as raw pathnames and creates fresh target `SplFileInfo` views only for consumers; pending sources reuse their collected file objects instead of copying native views; preserve first-fallback-wins and pending encounter order. Release completed ID state after pending resolution, before the second-pass bridge. This changes native-object lifetime, never pathname bytes or mutation/deletion authority.
- **Canonical scoring** — format-dominant weighted scoring: format priority (configurable via `CANONICAL_FORMAT_PRIORITY`) dominates all other signals. A preferred format (HEIC) always beats a correctly-named lower-priority format (JPG). Idempotency (1000 pts) only wins within the same format tier.
- **Explicit run configuration** — Resolve selected EXIF timezone/merge threshold/format priority/cache directory once at the command boundary; validate before collaborator/cache setup. The pipeline uses `PerceptualHashCalculatorInterface::setSignalCache()` and `ContentClassifierInterface::setMaxMergeRmse()`; the command passes run inputs and never configures those internals or checks concrete classes. Reset provider timezone (including null) and transient calculator maps when starting another run; Detach EXIF storage and restore the grouping default in finally, even after execution or flush failure, so later legacy workflows cannot inherit its settings. Null on the two setup contracts means detach/default. Complete partial signals during regular cleanup; a cache switch only releases native images/maps, without reading old paths or flushing an aborted snapshot. Infrastructure caches are separate from business settings. Commands resolve immutable `LinkConfig` once and carry it through `RenameOptions`; presenter/renderer inputs are explicit and default to links disabled. Model/renderer/matcher never read ENV for these settings. Dedup indexes retain explicit normalized format ranks per run, without mutable shared-matcher configuration. Test alternate implementations, invalid selected values, repeated runs/cache switches and explicit configurations without global ENV. CLI option precedence, safety caps and fresh mutation/deletion guards stay independent.
- **Execution model invariants** — `ExecutionItem` rejects contradictory raw-path/rename/no-op flags and block reasons on executable/no-op entries. No-ops derive non-executable permission even when the constructor's input defaults to true; withheld renames may omit a reason. Keep quality-policy decisions in `ExecutionPlanBuilder`, not duplicated in Model. `AssetGroup::replaceItem()` requires a current identity and throws `LogicException` for absent/stale predecessors; use the replacement/current group on later updates. Models stay infrastructure-free; never replace fresh filesystem/source guards with these in-memory checks.
- **Degraded classification** — `ExecutionPlanBuilder` blocks all mutations in the affected group with the analysis failure reason and projects unproven duplicate roles as Ambiguous. Existing-name no-ops and unrelated groups remain safe. `SubgroupClassifier` catches runtime exceptions; logic exceptions and PHP errors propagate.
- **Shared content boundary** — `SubgroupClassifier` passes real sources, metadata and actual companion hints directly to `ContentClassifierInterface`. `ContentClassificationResult` carries immutable file groups, original hashes, stable ranks and companion membership, without Rename/FileDuplicate or a naming closure. `HashSubGroupingService` adapts only legacy target naming; both paths use the same conservative core. Preferred source is a rank hint, basename/ID lookup maps excluded companions only, never still equivalence evidence. Preserve encounter ordering, native cleanup, finite decisions and differential negative/repeated-processing tests.
- **Merge evidence** — `AssetItem` carries the assigned execution role; detailed classification evidence belongs to `MergeDecision`. `ContentClassifier::classify()` and its legacy `HashSubGroupingService::apply()` adapter optionally stream immutable `MergeDecision` values through `onDecision`: Exact classification hash, Perceptual accepted analysis, Different veto, or Uncertain unavailable evidence. Matching companion hashes and filenames never override a rejected/unavailable still analysis. `SimilarityResult::analysisAvailable` distinguishes missing signals from measured dissimilarity. The active classifier retains one example/count per finite reason in its Decision Log; never retain a complete pair history. Classification evidence is not permanent-deletion authority; the fresh SHA-256 guard remains independent.
- **Native capability diagnostics** — Docker runtime includes ffmpeg/ffprobe/exiftool; standalone embeds PHP/Imagick and production packages, not these external tools or Docker isolation. Native failures remain unavailable evidence, separate from measured dissimilarity. Cross-group reconciliation reports one example and keeps groups separate; `ImagickImageLoader` uses the explicitly injected progress reporter and retains at most two capability flags. Keep 20-second stream, 5-second frame and 10-second probe deadlines. `Helper/NativeToolError` bounds details and redacts credential-named environment values before truncation, without dumping environment or promising arbitrary-secret detection; Console text safety remains downstream. Missing video tools do not block still-only decoding; missing exiftool fails write-date before writes. README and decision matrix must preserve mandatory still Stage B even at dHash zero, companion veto boundaries, and scoped deletion/foreign-writer guarantees.
- **Video stream identity** — Cross-group exact matching accepts one video stream and at most one audio stream. Additional AV tracks veto automatic merging and produce a review reason when primary video matches. Non-AV container tracks remain ignored.
- **Analysis memory headroom** — `AnalysisMemoryBudget` checks actual allocated PHP pages before growth in source identities, sorted files, capture groups, pairing bridges and execution plans, plus pipeline phase gates and the final pre-mutation command gate. Reserve one eighth of the effective PHP budget, bounded to 8–64 MiB, for reporting/cleanup. Exhaustion is an explicit incomplete-analysis failure before media mutation; EXIF skips optional snapshot serialization on this abort and preserves prior disk caches. Never treat the reserve as a capacity guarantee or a catch for a single large PHP allocation, native decoder memory, kernel OOM or later mutation-time failure. Keep finite runtime defaults and source/journal/deletion guards.
- **Comparison work limits** — `ComparisonWorkLimit` validates positive integer `MAX_COMPARISON_PAIRS` (default 100000), shared by cross-group video analysis and per-capture perceptual analysis with local counters. Cross-group pairs are generated lazily in stable bucket/path order. Count visited inner-loop group/membership exclusions too; index conflicting known content IDs out without pair walks, while unknown IDs retain comparisons against every identity. Merge sorted identifier/unknown index lists to preserve pathname order. Skip stale left rows and single-original-group buckets. Exhaustion is explicit, never duplicate evidence: cross-group failure aborts before execution; capture-group failure blocks that group while safe unrelated groups may proceed. EXIF returns failure for degraded classification, including dry-run. Native Stage B images are released in `finally`. Benchmark only synthetic media under finite Docker resources; document cold/warm measurements and their limits.
- **Idempotency** — preserve EXIF and tested legacy repeated-execution contracts. Arbitrary `rename:pattern` rules are idempotent only when their output filenames are fixed points of that same rule; do not promise stable repeat runs for every user-supplied transformation.
- **Symfony Filesystem** — all file operations (`rename`, `mkdir`, `remove`, `dumpFile`, `readFile`) use `Symfony\Component\Filesystem\Filesystem`. Never use procedural PHP functions for file I/O in production code.
- **Mutation coordination** — `Application::doRunCommand()` holds `BatchRunLock` before analysis through command cleanup for every `rename:*` command except `rename:verify` and parsed `--dry-run`. One non-expiring Symfony `FlockStore` resource under `<CACHE_DIR>/locks` covers overlapping trees only when all cooperating processes share the same persistent base/volume. The directory must be owned, non-symlinked and `0700`; the kernel releases locks on process death. Never delete held lock files, and never purge them with cache-clear. Separate bases/hosts and external importers do not coordinate automatically; trusted parents and quiescent foreign writers remain required. `RuntimeFileMoveExecutor` rejects observed disk target leaves, including unreadable files/dangling links; this is not an atomic foreign-writer guarantee. Use explicit `symfony/lock` production dependency and deterministic process handshakes for contention/recovery tests.
- **Source identity** — The mutating CLI application activates `SourceIdentityGuard` under the batch lock before command analysis and clears it after cleanup. Capture device/inode/size/mtime/ctime and fresh streamed SHA-256 for each source; verify immediately before rename, dedup quarantine/delete and write-date. Initially unverified/new sources and symlink leaves cannot become authorized through a late read. The dedicated `renamer.source_hash_calculator` must neither reuse nor flush classification caches; clear its own cache around every source read. `SourceFileFingerprint` is a pure immutable model. Direct internal service calls do not activate a CLI scope automatically; integration tests must share the same guard between application and executor. Full reads and retained fingerprints have real large-collection cost: document and profile them, keep budgets finite, and never describe these checks as atomic foreign-writer exclusion or duplicate-deletion authority.
- **Mutation journal** — `MutationJournal` shares the application's locked CLI scope and persistent state base. Use unique private `mutation-*.jsonl` files via Symfony Filesystem and `PrivateCacheStorage::append()`, with intents before media actions and receipts afterward; retain no whole-batch history in memory. Paths/details are lossless base64, not encrypted. Record actual runtime targets and both canonical/companion plans before a Live Photo's first move. `ExecutionResult::partialLivePhotoGroups` reaches the observed summary; duplicate-only failures do not flag a complete pair. Compare component basenames rather than parent directories: naming preserves each source directory. A failed post-action receipt must preserve the actual success count, stop subsequent writes and fail the command; initialization/intent failure prevents mutation. Final status failure also fails the application. Source checks occur inside the journal action immediately before the physical mutation. Journals survive cache-clear and are private sensitive artifacts; never publish original paths. Recovery requires reconciliation and a fresh plan, never automatic inverse/replay. Missing receipts/truncated tails are uncertain; no power-loss/fsync durability or automatic retention is promised. Use controlled child-process handshakes for abrupt-exit recovery tests.
- **Private media caches** — `PrivateCacheStorage` creates `CACHE_DIR/private-<effective UID>/` with `0700`, JSON/temporary files with `0600`. Correct only owned dedicated paths, never shared parent modes; reject foreign-owned paths and symlink/hardlink leaves. Flat legacy JSON is not imported: `make cache-clear` purges owned legacy/current-user files and the separate executable DI cache. No automatic TTL or forensic-erasure guarantee. Configured parents must be trusted; checks are not atomic against hostile directory writers.
- **Cache lifecycle** — Metadata JSON requires an interpretation/schema version plus the installed imagemeta reference; bump the version when application-side extraction meaning changes. Old/incompatible/corrupt snapshots start cold with safe diagnostics. Preserve marker-only/index-only metadata and negative results across fresh provider instances. Perceptual JSON validates row/signal types and normalizes JSON numbers to the float contract. Both caches still use path/mtime/size, not content identity. Complete snapshots use last-writer-wins atomic replacement; mutating CLI runs coordinate, verify/dry-run may lose cache work. Stop every cache writer before maintenance. `cache-prune`/`runtime-cache-prune` explicitly remove non-visible regular-file entries under the mutation lock, never media/journals/DI; the expected collection must be mounted or useful cache entries may be discarded. Optional I/O/JSON flush errors warn without changing observed media counts; ownership/link boundary failures remain explicit. `metadata-cache-benchmark` isolates 70k/100k synthetic full-field rows with finite Docker budgets; never claim a full-media capacity guarantee from it.
- **Quarantine boundaries** — `QuarantineTargetGuard` confines `rename:dedup` move destinations to relative subdirectories of the canonical source root; reject traversal, absolute paths and every existing symlink component (including dangling/internal links). Validate all actionable targets before any move and recheck around mkdir/before rename. Dry-run checks the same boundary; delete retains its independent fresh-byte guard. These checks require a tree without concurrent external directory writers and do not promise atomic symlink-race protection or rollback.

- **Perceptual cleanup** — `PerceptualHashCalculator` remembers freshly persisted signals in its existing signal-hit map so `clearCache()` cannot overwrite them with dHash-only entries. Preserve partial early-exit caching, release native images and both local maps, and test calculation → cleanup → flush → new-instance reload with real native fixtures. File mtime/size invalidation remains independent; broader cache versioning/lifecycle is separate.

- **Console text boundaries** — `Helper/ConsoleText` makes untrusted C0/C1/DEL controls visible and escapes Symfony markup before trusted styling/link tags. Preserve ordinary Unicode; invalid UTF-8 uses inert byte escapes. Keep domain/path/mutation/journal values raw. OutputEntry reasons are plain text, never preformatted markup; do not double-escape already linkified paths. Diff matching and width calculations use `neutralize()`, individual rendered segments use `escape()`. Verify detail markup is authorized explicitly by detail mode and category, never inferred from filename newlines. Tests capture bytes and use JSON/boolean assertions so failing hostile-input checks do not emit active terminal payloads. SymfonyStyle error/warning blocks already escape markup: pass only `neutralize()` there; plain text/writeln fragments use `escape()`. The application sanitizes uncaught chains and verbose locations/frames without altering Throwable codes or fields. Default directory scans include legal filename line breaks. Symfony trailing-backslash sentinels are consumed by the normal formatter, not sent via raw output.

### Output Tags (OutputEntryTag enum)

`OutputEntryPresenter` selects plain skip text directly from the exclusive Candidate/Review/Warning/Fallback tags, with a generic default for other tags. Review uses `reason`, Warning uses `warningReason`; null and empty details remain distinct. Escaping and styling follow at the actual output boundary. The existing parameterized output matrix protects every tag and conditional detail.

`[R]` Rename, `[F]` Fallback, `[D]` Duplicate, `[O]` Original, `[W]` Warning, `[S]` Skipped, `[E]` Error, `[C]` Candidate (conflicting content ID)

### Timezone Handling

- QuickTime/MP4 timestamps are stored in UTC (Mac epoch). The `TIMEZONE` env var converts them to local time.
- **Non-Apple cameras** (Panasonic, Canon, etc.) store **local time as UTC** in QuickTime containers. `write-date --reason=timezone` preserves the raw CreateDate and adds `Keys:CreationDate` with the configured TZ offset.
- EXIF dates in JPEG/HEIC are already in local camera time — never convert these.
- `TemporalMetadata` accepts `DateTimeInterface` inputs but stores and exposes only `DateTimeImmutable` timestamps. Normalize both capture/raw dates in the constructor and capture replacements in the wither; preserve null, timezone, microseconds and already immutable input identity. `withCaptureDateTime()` updates only the capture timestamp through native PHP 8.5 clone-with, retaining raw QuickTime provenance and every other field automatically. The constructor is not called again, so normalization must remain explicit in the wither. Keep derived markers and normalized identifiers unchanged, and test all independent fields with synthetic metadata.
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
  Service/LivePhoto/   # Live Photo pairing
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
  Integration/         # Full command integration tests
  Fixtures/            # WorkspaceTrait, StubMetadataExtractor, LivePhotoFixtureFactory
  Fixtures/Images/     # Synthetic media scenarios (verified by TestImageScenariosTest)
.build/
  bin/                 # Compiled binaries
  vendor/              # Composer dependencies
  cache/               # Tool/DI caches + private-<UID>/ media JSON caches
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
| `MAX_COMPARISON_PAIRS` | Finite pair visits per capture group and entire cross-group video batch | `100000` |
| `CACHE_DIR` | Dev/standalone JSON base; runtime uses `/cache` volume (0700/0600); DI separate | `.build/cache` |
| `MEDIA_DIR` / `MEDIA_READ_ONLY` | Existing host collection mounted at `/media`, optional read-only analysis | `./images` / `false` |
| `PHP_MEMORY_LIMIT` | Positive finite PHP budget, invalid/unlimited overrides rejected | `1024M` |
| `RUNTIME_MEMORY_LIMIT` / `RUNTIME_CPUS` | Container RAM (swap equal) and CPU budget | `2g` / `2.0` |
| `RUNTIME_PIDS_LIMIT` / `RUNTIME_TMP_SIZE` | Process/thread bound and temporary tmpfs size | `128` / `512m` |
| `CANONICAL_FORMAT_PRIORITY` | Comma-separated format priority for canonical selection | `heic,heif,dng,arw,...` |


Selected CLI configuration takes precedence over environment values; validate only the selected value. Unsupported explicit named timezones, non-integer/negative/overflowing date drift and non-numeric/non-finite/out-of-range merge thresholds fail before media mutation. Drift `0` explicitly disables its gate; merge accepts finite 0–1 while existing internal safety caps remain. Missing optional values preserve defaults. Forward `CANONICAL_FORMAT_PRIORITY` and `MERGE_THRESHOLD` to runtime and buildbox; test arrival and actual command behavior separately. `scripts/check-configuration-contract.sh` runs in the runtime-image CI check and uses only tiny native synthetic media in an external temporary host directory with spaces; it checks canonical selection, measured merge decisions, CLI precedence, defaults and the wrapper without user configuration or persistent metadata-cache writes. `scripts/init-with-docker` preserves existing `.env` bytes and exports invoking UID/GID only for builder identity; initialize a missing file from `.env.dist` with those initial IDs. Test initialized/failing/fresh paths using isolated temporary configuration and stub Docker/auth, never real user `.env`, credentials or SPC downloads.
