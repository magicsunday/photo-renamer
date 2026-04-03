# Repo-Wide Refactor Wave 2 — Boundary Cleanup, Contract Discipline, and Stronger Architecture Rules

> **Goal:** Bring the remaining parts of `src/` in line with explicit boundaries, explicit contracts, container-driven DI, stronger testability, and stricter architecture enforcement without introducing speculative abstraction.

**Problem:** The first structural refactor wave removed several major hotspots, but the repository still has broad boundary classes, tuple-heavy service internals, permissive dependency injection shortcuts, and architecture rules that are too coarse for the current codebase maturity. The code is already good in many places; the remaining issue is uneven discipline, not general disorder.

**Decision:** Treat this as a second, repo-wide refactor wave composed of several independent workstreams. The focus is not only on the classes touched before, but also on the large or structurally important classes that still define repository-wide quality:

- output boundaries
- filesystem boundaries
- metadata boundaries
- tuple/array-shape service contracts
- default constructor instantiation in production code
- missing virtual pipeline-flow tests
- weak architecture rules for role-specific boundaries

This plan intentionally spans all of `src/`, not just one or two leftover classes.

**Primary design rule:** Business semantics should live in named DTOs, policies, analyzers, planners, resolvers, and coordinators, not in array shapes, hidden tuples, or broad infrastructure buckets.

**Secondary design rule:** Commands orchestrate. Renderers render. Filesystem services perform I/O. Metadata services resolve metadata. Policies decide. Builders construct. Coordinators compose. Helpers stay small and mechanical, or should be removed entirely when a value object or dedicated collaborator is clearer.

**Pattern rule:** Prefer `Strategy`, `Builder`, targeted `Decorator` / `Proxy`, and narrowly scoped `Facade` / `Adapter` only where they remove a visible coupling, branching, or boundary problem. Do not introduce a pattern before the code demonstrates the need.

**Out of scope:**

- changing established user-visible behavior unless explicitly documented and regression-tested
- splitting coherent algorithmic cores purely because they are large
- turning every remaining service into many micro-classes without a real responsibility gain
- replacing practical domain objects with abstract generic frameworks

---

## Architectural target state

The desired repository state after this wave is:

- output concerns live in a dedicated output boundary, not in one monolithic renderer
- filesystem concerns live in dedicated execution and collection boundaries
- metadata concerns live in a cohesive metadata module
- tuple-style return values are replaced by intention-revealing DTOs wherever they cross meaningful domain boundaries
- string literals that encode domain states are replaced by enums, catalogs, or explicit value objects
- production services no longer instantiate collaborators by default in their constructors
- domain services do not depend directly on `SymfonyStyle`
- console-facing progress and reporting flow only through dedicated reporting adapters
- tests cover both:
  - isolated rules and collaborators
  - a virtual full `rename:exif` flow without real file operations
- architecture rules enforce not only layer direction, but also boundary semantics and role discipline

The aim is not maximal indirection. The aim is a repository where responsibilities are easy to locate, hard to misuse, and cheap to test.

---

## Current assessment

### Areas already in good shape

- `src/Strategy/`
  - explicit and bounded strategy usage
- `src/Service/Pipeline/`
  - significantly clearer than before after the first wave
- `src/Service/Verify/`
  - better separation between scan facts and presentation
- `src/Service/WriteDate/`
  - better separation between reasoning and presentation
- many recent DTOs and catalogs
  - moving in the right direction for explicit semantics

### Remaining structural hotspots

- `src/Service/RenameOutputRenderer.php`
  - too broad for one renderer boundary
- `src/Service/FileSystemService.php`
  - still mixes file collection, legacy execution, runtime collision handling, and output coordination
- `src/Command/AbstractRenameCommand.php`
  - too much shared command flow and option handling in one inheritance base
- `src/Metadata/ExifMetadataProvider.php`
  - strong but still carries too much policy/caching/module authority in one class
- `src/Helper/FileHelper.php`
  - mechanical utilities and domain-adjacent logic are mixed together
- `src/Service/LivePhoto/LivePhotoConflictDetector.php`
  - coherent heuristic, but too dependent on shape arrays
- `src/Service/PerceptualHash/PerceptualHashCalculator.php`
  - algorithmically coherent, but tuple-heavy and infrastructure-coupled
- `src/Service/PerceptualHash/LocalDifferenceAnalyzer.php`
  - same issue: good core, weak DTO discipline
- `src/Service/Video/VideoStreamFingerprintMatcher.php`
  - useful service, but still internally tuple-driven and directly responsible for process orchestration

### Structural anti-patterns still present

- constructor defaults that instantiate production collaborators directly
- domain services depending on `SymfonyStyle` outside command, output, or reporting adapters
- shape-array return values used where named DTOs would carry meaning better
- helper classes acting as mechanical and semantic buckets at the same time
- tag-driven reason text selection and near-duplicate render branching living inline inside render/execution services
- architecture rules that validate layer direction only, not role integrity

---

## Directory and namespace target state

The current top-level structure is mostly sound, but a few responsibilities are still in the wrong neighborhood.

### Keep as-is

- `src/Command/`
- `src/Service/Pipeline/`
- `src/Service/LivePhoto/`
- `src/Service/PerceptualHash/`
- `src/Strategy/`
- `src/Model/`
- `src/Regex/`
- `src/Exception/`

### Restructure

- create `src/Service/Output/`
  - move output-specific rendering/projecting/highlighting concerns there
- create `src/Service/Reporting/`
  - hold console-facing progress/reporting adapters that bridge to `SymfonyStyle`
- create `src/Service/Filesystem/`
  - move collection/execution/collision-path-allocation concerns there
- move metadata-adjacent services into `src/Metadata/` where appropriate
  - especially cache and quality-flag logic that conceptually belongs to metadata access
- reduce the size of `src/Helper/`
  - prefer small helper/value abstractions over a broad `FileHelper`

### Planned moves

- `src/Service/RenameOutputRenderer.php`
  - to `src/Service/Output/RenameOutputRenderer.php`
- new output collaborators under `src/Service/Output/`
- `src/Service/FileSystemService.php`
  - either stays as a thin facade or is replaced by narrower `Filesystem` services
- `src/Service/MetadataCache.php`
  - to `src/Metadata/MetadataCache.php`
- `src/Service/MetadataQualityFlagResolver.php`
  - to `src/Metadata/MetadataQualityFlagResolver.php`
- pieces extracted from `src/Helper/FileHelper.php`
  - into narrower helper/value services

The namespace work is not cosmetic. It should make responsibility discoverable by path.

---

## DTO and enum policy

### Required direction

Use DTOs instead of arrays when:

- a return value has more than one field with stable meaning
- tuple position matters today
- callers need to know why a result was returned, not only what it contains
- the same shape appears in more than one class
- a structure crosses a meaningful boundary between services or phases

Do not force DTOs for tiny, ephemeral private-method arrays when the structure is hyper-local and not part of a boundary contract.

Use enums/catalogs/constants instead of string literals when:

- the set of values is closed or near-closed
- a string controls behavior rather than carrying user content
- the same literal appears in more than one class
- the value participates in filtering, classification, or policy decisions

### High-priority DTO candidates

- `src/Metadata/MetadataExtractor.php`
  - replace capture timestamp tuple with `CaptureTimestampExtraction`
- `src/Service/CanonicalScorer.php`
  - replace score tuple with `CanonicalScore`
- `src/Metadata/MetadataQualityFlagResolver.php`
  - replace flag array with `MetadataQualityFlags`
- `src/Service/Video/VideoStreamFingerprintMatcher.php`
  - replace internal fingerprint tuple with `VideoStreamFingerprint`
  - keep `VideoFingerprintMatch` as policy result
- `src/Service/PerceptualHash/PerceptualHashCalculator.php`
  - replace signal arrays with `PerceptualSignals`
- `src/Service/PerceptualHash/LocalDifferenceAnalyzer.php`
  - replace pixel export/blob tuples with `ExportedPixelData` and `BlobStats`
- `src/Service/LivePhoto/LivePhotoConflictDetector.php`
  - replace asset shape arrays with `LivePhotoConflictAsset`
  - replace candidate-map tuple with `LivePhotoConflictCandidateMap`
- `src/Service/Pipeline/CrossGroupVideoDuplicateReconciler.php`
  - replace bucket/plan tuples with DTOs such as `DurationBucketedVideoCandidate`, `CrossGroupVideoComparisonPlan`, or tighter equivalents
- `src/Service/RenameOutputRenderer.php`
  - replace internal output/counter tuples with DTOs
- `src/Service/FileSystemService.php`
  - replace counter arrays with a dedicated execution/report DTO

### High-priority enum/catalog candidates

- stream type in `VideoStreamFingerprintMatcher`
  - `VideoStreamType`
- output summary metrics now represented by free-form keys
  - move to `SummaryMetric` enum or dedicated summary DTOs
- any remaining repeated literal reasons that still leak across multiple classes
  - move into dedicated catalogs

---

## DI and boundary discipline

### Production constructor rule

Outside entry points, caches, and pure value objects:

- no production collaborator should be instantiated via constructor default
- no service should rely on `new Foo()` fallback wiring
- dependencies should be explicit and container-wired

This is a repository-wide cleanup target, not a one-off style preference.

### Console I/O rule

Only commands, output services, and reporting adapters should depend on `SymfonyStyle`.

Services that currently report progress or warnings directly should move to:

- `ProgressReporterInterface`
- `ConsoleProgressReporter`
- `NullProgressReporter`

Primary current candidates:

- `src/Service/Pipeline/CaptureGroupBuilder.php`
- `src/Service/Pipeline/SubgroupClassifier.php`
- `src/Service/Pipeline/OrphanLivePhotoVideoReconciler.php`
- `src/Service/Pipeline/CrossGroupVideoDuplicateReconciler.php`
- `src/Service/DuplicateDetectionService.php`
- `src/Service/HashSubGroupingService.php`

### Process boundary rule

Classes that currently create `Symfony\Component\Process\Process` directly should move toward thinner process boundaries:

- `src/Service/Video/VideoStreamFingerprintMatcher.php`
- `src/Service/PerceptualHash/ImagickImageLoader.php`
- `src/Service/ExiftoolWriter.php`

Preferred direction:

- thin process boundary where semantics are genuinely shared
- otherwise tool-specific runners when semantics differ
- deterministic test doubles without shell/process setup

### Filesystem boundary rule

Do not let filesystem collection, filesystem mutation, and output concerns collapse into a single service boundary.

---

## Test strategy target state

### Required levels

Every major boundary should be covered at three levels:

- rule-level unit tests
- orchestrator collaboration tests
- flow-level tests

### New mandatory test capability

Add a virtual full-flow `rename:exif` test harness that executes the semantic pipeline without real file mutations.

This flow should:

- use stubbed metadata
- use fake or stubbed hash/perceptual/fingerprint collaborators
- use the current orchestration boundary via `AssetGroupPipeline -> ExecutionPlanBuilder -> output projection`
- avoid actual rename operations
- still validate final targets, tags, review entries, summary counters, and validation results

This is distinct from the existing temp-directory integration tests. The repository needs both:

- realistic integration tests with workspace files
- virtual flow tests that fail fast and isolate special-case semantics

### Mandatory future test categories

- golden/snapshot-style output projections for renderer/output boundary
- result-level characterization
  - names
  - grouping
  - tags
  - counts
  - execution flags
- reason-level characterization
  - why a file is fallback/warning/candidate/review
  - why a canonical item won
  - why a merge was blocked
- invariant/property-style tests
  - idempotency
  - stable ordering
  - no duplicate targets
  - companion inheritance consistency
- architecture tests
  - boundary rules, not just namespace direction

---

## Architecture rule expansion

The current PHPat rules mostly validate broad layer direction. That is no longer sufficient.

### New rules to add

- services outside `Command`, `Service/Output`, and `Service/Reporting` must not depend on `SymfonyStyle`
- renderers must not depend on metadata extractors, strategies, or process execution
- analyzers must not mutate domain collections
- planners must not perform file I/O
- policies must not depend on console or filesystem boundaries
- helpers must remain mechanical and must not depend on services or metadata
- reporting adapters may depend on `SymfonyStyle`, but domain services must only depend on `ProgressReporterInterface`
- classes with suffix:
  - `Renderer`
  - `Analyzer`
  - `Planner`
  - `Policy`
  - `Builder`
  - `Coordinator`
  should have explicit allowed-dependency envelopes
- product classes must not use constructor-default collaborator instantiation
- tuple-style return values at public/protected `Service`, `Metadata`, and `Helper` boundaries should be forbidden or tightly allowlisted

### Optional stronger rule

Add an architectural assertion that commands must not execute filesystem mutations directly. They should delegate to filesystem/execution services.

---

## Workstream groups

This wave is easier to reason about when split into three kinds of work:

- `Safety-net tracks`
  - behavior locking, virtual flows, architecture hardening
- `Boundary tracks`
  - output, filesystem, metadata, reporting/console, command-base boundaries
- `Contract discipline tracks`
  - DI cleanup, DTO/enum cleanup, helper ownership cleanup

The tracks below are not all the same kind of work and should not be reviewed as if they were.

## Safety-net tracks

## Track 0: Lock Remaining Behavior Before Broad Boundary Work

**Goal:** Freeze the behavior of still-untouched broad boundaries before cutting them apart.

### Scope

- `RenameOutputRenderer`
- `FileSystemService`
- `AbstractRenameCommand`
- `ExifMetadataProvider`
- `FileHelper`
- `LivePhotoConflictDetector`
- `PerceptualHashCalculator`
- `LocalDifferenceAnalyzer`
- `VideoStreamFingerprintMatcher`

### Required tests

- result-level characterization
- reason-level characterization
- output projection characterization
- minimal safety scenarios for `rename:exif` where needed before structural movement

Track 0 is not the place to build the reusable virtual-flow harness itself. That belongs to Track 9a.

### Acceptance criteria

- broad boundaries have explicit characterization tests before structural movement begins
- no remaining large untouched class relies only on indirect coverage

### Risk

- low

---

## Boundary tracks

## Track 1: Output Boundary Cleanup

**Goal:** Turn the current broad output boundary into a dedicated output module.

### Motivation

`RenameOutputRenderer` currently does too many things in one class:

- build output entries
- interpret execution and skip counters
- format summary tables
- highlight filename diffs
- project review entries
- provide output-related helper logic
- choose skip-reason text by tag via inline `match`
- branch between near-identical render layouts inline instead of projecting a presentation model first

That is still output-related work, but too much for one implementation bucket.

### Proposed structure

- `src/Service/Output/RenameOutputRenderer.php`
- `src/Service/Output/OutputEntryProjector.php`
- `src/Service/Output/OutputEntryPresenter.php`
- `src/Service/Output/SkipReasonResolver.php`
- `src/Service/Output/SummaryTableRenderer.php`
- `src/Service/Output/DiffHighlighter.php`
- optional `src/Service/Output/ReviewEntryProjector.php`
- DTOs such as:
  - `RenderedOutputBatch`
  - `SummarySection`
  - `OutputCounters`
  - `PresentedOutputLine` or equivalent presentation DTO

### Acceptance criteria

- the renderer remains the visible boundary
- projection, highlighting, and summary assembly no longer live in one monolith
- tuple returns inside the output boundary are replaced with DTOs
- skip-reason resolution no longer happens via repeated inline `match` blocks in renderer or execution services
- render branches that differ only in presentation form are projected once and rendered once, rather than duplicated with small string-template variations

### Risk

- medium

---

## Track 2: Filesystem Boundary Cleanup

**Goal:** Separate file collection, plan execution, and runtime collision fallback into narrower services.

### Motivation

`FileSystemService` currently still acts as:

- file collector
- legacy rename executor
- execution-plan executor
- runtime collision allocator
- output coordinator

Those concerns are adjacent but not identical.

### Proposed structure

- `src/Service/Filesystem/FileCollector.php`
- `src/Service/Filesystem/LegacyRenameExecutor.php`
- `src/Service/Filesystem/ExecutionPlanExecutor.php`
- `src/Service/Filesystem/RuntimeCollisionPathAllocator.php`
- `src/Service/Filesystem/FileSystemService.php`
  - optional thin facade only if still useful

### Acceptance criteria

- no single filesystem service owns both collection and mutation strategy and output orchestration
- runtime collision fallback becomes directly unit-testable
- filesystem mutation remains isolated

### Risk

- medium

---

## Track 3: Metadata Module Consolidation

**Goal:** Make metadata concerns discoverable and cohesive by module and path.

### Motivation

Metadata logic is conceptually strong, but currently split across:

- `src/Metadata/`
- `src/Service/MetadataCache.php`
- `src/Service/MetadataQualityFlagResolver.php`

The result is workable but not structurally clean enough.

### Proposed structure

- keep:
  - `MetadataExtractor`
  - `ExifMetadataProvider`
  - `TemporalMetadata`
- move:
  - `MetadataCache`
  - `MetadataQualityFlagResolver`
- add DTOs:
  - `CaptureTimestampExtraction`
  - `MetadataQualityFlags`

### Acceptance criteria

- metadata retrieval, metadata caching, and metadata-quality rules live in one coherent module area
- tuple-return metadata extraction is replaced by explicit DTOs

### Risk

- medium

---

## Track 6: Legacy Command Base Cleanup

**Goal:** Reduce `AbstractRenameCommand` from a broad inheritance bucket to a smaller command-base boundary, but only if it is still actively causing coupling or growth pressure.

### Motivation

`AbstractRenameCommand` is still one of the remaining broad classes and currently centralizes:

- shared CLI option registration
- option parsing and defaults
- source resolution and normalization
- dry-run confirmation flow
- legacy execution orchestration

An abstract command base is not automatically a design problem. It only becomes one when it accumulates business decisions, execution-specific branches, or new rule pressure.

### Proposed structure

- keep `AbstractRenameCommand` as a thin template boundary if it remains stable
- otherwise extract collaborators such as:
  - `LegacyCommandOptionResolver`
  - `LegacyCommandSourceResolver`
  - `LegacyRenameExecutionCoordinator`

### Acceptance criteria

- `AbstractRenameCommand` is either significantly thinner or explicitly retained as a minimal stable template boundary
- shared command concerns stop accumulating inline in the inheritance base

### Risk

- medium

---

## Track 7: Progress and Console Boundary Cleanup

**Goal:** Remove direct console dependency from domain services.

### Proposed structure

- `ProgressReporterInterface`
- `src/Service/Reporting/ConsoleProgressReporter.php`
- `src/Service/Reporting/NullProgressReporter.php`

### Scope

Refactor pipeline and legacy services that currently depend on `SymfonyStyle` directly.

### Acceptance criteria

- only commands, output services, and reporting adapters know `SymfonyStyle`
- domain services report progress through a narrow boundary
- tests can use silent/null reporters instead of constructing console IO

### Risk

- medium to high

---

## Contract discipline tracks

## Track 4: Helper Decomposition

**Goal:** Clarify ownership around `FileHelper` after metadata and filesystem boundaries are cleaner.

### Motivation

`FileHelper` currently mixes:

- env reading
- path relativization
- extension normalization
- duplicate suffix stripping
- date extraction and drift calculations

That is too broad for a single helper if the project wants strict responsibility boundaries, but helper cleanup should follow the earlier ownership decisions in metadata and filesystem work rather than pre-empt them.

### Proposed structure

- `PathHelper`
- `ExtensionNormalizer`
- `FilenameDateParser`
- `DateDriftCalculator` or route all drift semantics through the existing analyzer where appropriate
- possibly `EnvReader` if still justified

### Acceptance criteria

- `FileHelper` is either slimmed to a narrow mechanical helper or removed
- semantic date parsing/drift logic does not stay hidden in a catch-all helper
- helper ownership follows earlier metadata/filesystem boundary decisions rather than creating temporary duplicate homes
- helper extraction must not create pseudo-domain services under `Helper/`

### Risk

- medium

---

## Track 5: DI Purification

**Goal:** Remove constructor fallback wiring and make collaborator graphs explicit.

### Scope

Apply this opportunistically while touching affected boundaries or modules, not as a blind repository-wide mechanical pass.

Typical targets:

- constructor default collaborator instantiation
- implicit `new` fallback injection
- production code using service locators or container-like behavior indirectly

### Acceptance criteria

- production collaborators are explicit constructor dependencies
- tests use dedicated factories/builders rather than leaning on production defaults
- `config/Services.yaml` becomes the authoritative wiring source
- DI cleanup commits stay locally meaningful and are cut alongside touched boundaries/modules rather than as broad cross-repo noise

### Risk

- medium

---

## Track 8: DTO and Enum Sweep Across Remaining Broad Services

**Goal:** Replace the remaining meaningful tuple/array contracts with named objects.

### Scope

Apply this opportunistically while touching affected modules or boundaries, not as a repository-wide churn sweep.

### High-priority targets

- `MetadataExtractor`
- `CanonicalScorer`
- `VideoStreamFingerprintMatcher`
- `PerceptualHashCalculator`
- `LocalDifferenceAnalyzer`
- `LivePhotoConflictDetector`
- `CrossGroupVideoDuplicateReconciler`
- `RenameOutputRenderer`
- `FileSystemService`

### Explicit policy modeling requirement for priority-driven decisions

Where a boundary contains multiple ordered decision rules with stronger and weaker
signals, the repository should prefer an explicit rule/decider structure over
inline branching in an orchestrator.

This is not a call for one generic rule engine across the whole codebase. It is
a call for boundary-local ordered rule stacks where priority and fallback behavior
must stay visible and testable.

Preferred structure:

- `<Boundary>RuleInterface`
  - each rule answers whether it applies and what decision or evidence it yields
- `<Boundary>EligibilityDecider`
  - runs preconditions and stop-rules in strict priority order
- `<Boundary>DecisionDecider`
  - converts evidence into the final decision DTO
- `<Boundary>ActionPolicy` or `<Boundary>Coordinator`
  - applies the already-made decision without re-deciding inline

Required decision DTO direction:

- `<Boundary>EligibilityDecision`
- `<Boundary>PolicyDecision`
- `<Boundary>Action`

Priority handling must be explicit:

- rule priority must not depend on accidental service-registration order
- each priority-driven rule set should define its precedence as either:
  - an explicit ordered list owned by the decider, or
  - a dedicated priority value / enum exposed by each rule
- stop-rules must be modeled intentionally
  - first-applicable-wins only where the boundary truly represents mutually exclusive precedence
  - additive pipelines should stay additive and must not be forced into stop-on-first-match semantics
- tie handling should be deterministic and covered by tests

The rule/decider split is specifically meant to make priority visible:

- which rule outranks which other rule
- whether a rule blocks further evaluation
- whether a rule only contributes evidence for a later decision

High-priority candidates for this pattern:

- `CrossGroupVideoDuplicateReconciler`
- `WriteDate` reason/eligibility policy
- `Verify` issue classification where multiple ordered checks compete
- output skip/review reason selection where tags and state currently drive inline branching

Concrete first application: cross-group video reconciliation

- `CrossGroupVideoComparisonRuleInterface`
- `CrossGroupVideoEligibilityDecider`
- `CrossGroupVideoDecisionDecider`
- `CrossGroupVideoMergePolicy`

Minimum ordered rule set there:

1. `ConflictingContentIdentifierRule`
   - if both items expose non-null content identifiers and they differ, the pair is ignored
2. `MatchingContentIdentifierRule`
   - if both items expose the same non-null content identifier, stream comparison may proceed
3. `MissingContentIdentifierFallbackRule`
   - if one or both identifiers are missing, the stream-based fallback may proceed
4. `VideoFingerprintEvidenceRule`
   - maps `VideoStreamFingerprintMatcher` evidence to `exact duplicate`, `review`, or `ignore`

This must make policy priority explicit:

- stronger identity signals outrank weaker fallback signals
- fallback evidence only applies when stronger evidence is absent or agreeing
- orchestrators should run ordered rules and apply decisions, not encode the whole priority tree inline

### Acceptance criteria

- no broad service still exposes unstable tuple-style contracts where a DTO would clarify intent
- stream types, summary metrics, and similar closed sets no longer float as raw string literals
- DTO/enum cleanup remains locally meaningful and boundary-driven, not a mechanical repo-wide rewrite of every private helper shape
- priority-driven policies use explicit ordered rules and decision DTOs where inline branching currently hides the precedence model

### Risk

- medium

---

## Safety-net continuation

## Track 9: Virtual `rename:exif` Flow Harness

**Goal:** Add a deterministic, mock/stub-driven full semantic flow for `rename:exif`.

### Why this is mandatory

The repository already has strong real integration tests. What is still missing is a fast, virtual end-to-end harness that makes special-case regressions visible before they require workspace files or the user’s real photo set.

### Proposed subtracks

- `Track 9a: Pipeline virtual flow`
- `Track 9b: Command orchestration virtual flow`

`Track 9b` depends on `Track 7` so the command-level harness can use the final reporting boundary instead of a transitional console workaround.

### Proposed test harnesses

This track should explicitly add two complementary virtual-flow levels:

- `Track 9a: Pipeline virtual flow`
  - focuses on semantic pipeline behavior through `AssetGroupPipeline -> ExecutionPlanBuilder -> output projection`
- `Track 9b: Command orchestration virtual flow`
  - executes `RenameByExifDateCommand` with fake/stubbed collaborators and no real file mutation, so command-specific review/preview/summary branches are covered too

`Track 9a` uses:

- fake file iterator or explicit file list adapter
- stub metadata extractor/provider inputs
- fake hash calculator
- fake perceptual calculator
- fake video fingerprint matcher
- a silent progress/reporting boundary if needed
- real `AssetGroupPipeline`
- real `ExecutionPlanBuilder`
- output projection without real filesystem mutation

`Track 9b` uses:

- fake/stubbed collaborators around `RenameByExifDateCommand`
- the final `ProgressReporterInterface` / `Service/Reporting` setup from `Track 7`
- a fake/spy `FileSystemServiceInterface` or equivalent non-mutating execution double
- the command should reach the execution call boundary, but real file operations must not happen

### What it should assert

- pipeline-level grouping and naming semantics
- final target names
- role assignment
- duplicates and companions
- warnings/fallbacks/review findings
- validation results
- projected output tags and summaries
- command-level orchestration behavior, including:
  - review-entry mapping
  - `RenameResult` construction
  - preview/summary branches
  - `--list-all` behavior
  - empty/no-op/review-only execution paths

### Acceptance criteria

- reusable virtual-flow fixtures exist for both pipeline and command orchestration
- special cases can be encoded without temporary directories or real renames

### Risk

- medium

---

## Track 10: Architecture Rule Hardening

**Goal:** Turn architectural intent into enforceable rules, not only conventions.

### Scope

- extend PHPat rules
- possibly add lightweight reflection/static assertions where PHPat is insufficient
- cover role-based restrictions and constructor anti-patterns

### Acceptance criteria

- architecture rules enforce role discipline in addition to namespace direction
- future drift toward broad helpers, console-aware services, or tuple-heavy service APIs becomes test-visible

### Risk

- low to medium

---

## Delivery workflow

Every track in this phase-2 plan should be executed as a sequence of small reviewed subtasks:

1. add or sharpen tests first
2. implement the smallest structural slice
3. run `make cgl`
4. run `make rector`
5. repeat `make cgl` and `make rector` until stable
6. run `make test`
7. review the exact diff
8. commit only that reviewed slice

This workflow exists to keep refactors behavior-safe and feature-like test harness additions explicitly controlled and continuously reviewable.

---

## Recommended order

Recommended order for this second wave:

1. Track 0
2. Track 9a
3. Track 1
4. Track 3
5. Track 2
6. Track 7
7. Track 9b
8. Track 6
9. Track 4
10. Track 5
11. Track 8
12. Track 10

### Why this order

- lock behavior before cutting broad boundaries
- add the virtual flow harness early so later refactors have faster safety nets
- split the virtual flow harness so semantic pipeline coverage arrives early, while command-level harnessing waits for the final reporting boundary
- clean output and metadata boundaries first because they are the clearest ownership cuts
- do filesystem cleanup after metadata because it has more operational coupling
- establish the reporting boundary before the command-level virtual harness
- helper cleanup should follow clarified ownership, not race ahead of it
- DI and DTO/enum cleanup should land later as boundary-aware consolidation work, not early broad sweeps
- harden architecture rules after the new boundaries exist, not before

---

## Definition of Done for Each Track

Each track is only complete when:

- tests are green
- the affected orchestrator or boundary is shorter, clearer, or more declarative than before
- any extracted collaborator can be explained in one clear business sentence
- no new tuple/array contract or fallback-constructor shortcut was introduced without explicit justification

---

## Decision rule for algorithmic cores

The following classes are large, but should not be split automatically in this wave:

- `src/Service/HashSubGroupingService.php`
- `src/Service/PerceptualHash/PerceptualHashCalculator.php`
- `src/Service/PerceptualHash/LocalDifferenceAnalyzer.php`

They should only be split when one of these conditions is true:

- behavior changes there become frequent
- tests around them become fragile or overly indirect
- new policies need to be recombined independently of the current algorithmic flow

Until then, boundary cleanup, DTO discipline, DI cleanup, and stricter tests yield the better cost-benefit ratio.

---

## Success criteria for the repository

This phase is successful when:

- untouched broad boundaries from the first wave are no longer weak spots
- `src/` layout reflects actual responsibility clusters more clearly
- broad services stop returning tuples when named DTOs would clarify meaning
- domain services stop knowing console IO directly
- constructor DI is explicit and container-driven
- the repository has both real integration tests and virtual semantic flow tests
- architecture rules actively prevent regression into broad buckets and hidden coupling

At that point, the repository will not only be functionally solid, but structurally much closer to the standard implied by strict PHP best practices, deliberate design patterns, and long-term maintainability.
