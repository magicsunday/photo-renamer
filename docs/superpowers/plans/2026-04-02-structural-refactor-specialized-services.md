# Structural Refactor Plan — Specialized Services and Thinner Orchestrators

> **Goal:** Move specialized business logic into clearly named, focused classes while keeping commands thin, preserving the current execution-path decisions, and improving structural alignment with established software design patterns.

**Problem:** The project already has a strong architectural direction, but several classes still carry too many responsibilities or hold specialized logic that would be easier to reason about, test, and evolve in smaller named collaborators. The result is not random chaos, but uneven structural consistency: some areas follow clean policy/resolver/analyzer patterns, while other hotspots still behave like transitional aggregations of multiple concerns.

**Decision:** Keep the current high-level architecture:
- `rename:exif` remains on the AssetGroup + ExecutionPlan pipeline
- the simple legacy rename commands remain on the bounded legacy path
- `rename:verify`, `rename:write-date`, and `rename:dedup` keep their own application flows

The refactor focus is therefore **structural decomposition**, not execution-path migration.

**Primary design rule:** If a block of logic can be described in one specific business sentence, it should live in its own specifically named class.

**Secondary design rule:** Commands orchestrate. Policies decide. Resolvers choose. Analyzers inspect. Renderers render. Coordinators compose.

**Out of scope:**
- migrating legacy commands onto the `rename:exif` runtime pipeline
- broad “service extraction” without a clear responsibility boundary
- splitting stable small classes only for aesthetic reasons
- changing user-visible behavior unless explicitly documented and regression-tested

---

## Architectural target state

The desired structure is:

- commands remain thin application-layer entry points
- large services become orchestrators/facades
- specialized decision logic moves into small collaborators with intention-revealing names
- naming follows role-based semantics (`Analyzer`, `Policy`, `Resolver`, `Planner`, `Renderer`, `Coordinator`)
- each refactor phase is behavior-preserving and independently testable

This is not a generic “more classes” plan. The intent is to reduce hidden coupling and responsibility overlap.

---

## Current assessment

### Already structurally strong

- `src/Strategy/`
  - strategy pattern is explicit and appropriately bounded
- `src/Service/Pipeline/RoleAssigner.php`
  - reasonably clear orchestration role
- `src/Service/MediaCompatibilityPolicy.php`
  - good example of a narrow shared policy
- `src/Service/DateDriftAnalyzer.php`
  - good example of a small single-purpose analyzer
- `src/Service/MetadataQualityFlagResolver.php`
  - good example of secondary-rule extraction without redefining the main authority
- `src/Service/Dedup/DedupOriginalMatcher.php`
  - good example of specialized matching logic separated from the command

### Structural hotspots

- `src/Service/DuplicateDetectionService.php`
  - legacy-path God-service with multiple responsibilities
- `src/Service/Pipeline/TargetNameResolver.php`
  - contains too many different naming modes and recovery rules
- `src/Service/Pipeline/CaptureGroupBuilder.php`
  - mixes collection, grouping, pending companion handling, and quality tracking
- `src/Service/HashSubGroupingService.php`
  - coherent domain, but too many stages in one implementation
- `src/Command/VerifyCommand.php`
  - still contains domain-specific analysis/report assembly
- `src/Command/WriteDateCommand.php`
  - still contains write-candidate reasoning and timezone-specific planning
- `src/Service/Pipeline/SubgroupClassifier.php`
  - still carries specialized orphan-video reconciliation alongside its bridge/adaptation role

---

## Naming model

These suffixes should be used consistently:

- `Command`
  - CLI/application entry point only
- `Analyzer`
  - read-only classification or issue detection
- `Policy`
  - business rule without orchestration or I/O
- `Resolver`
  - choose one result/candidate/target from several possibilities
- `Planner`
  - build a list/plan of intended actions
- `Renderer`
  - output formatting only
- `Coordinator`
  - orchestrates multiple specialized collaborators
- `Facade`
  - compatibility or aggregate boundary over existing internals

Avoid for new business logic:

- vague `Helper`
- broad `Util`
- catch-all `...Service` where a narrower role name exists

---

## Refactor phases

## Phase 0: Lock Behavior Before Structural Cuts

**Goal:** Protect existing semantics before moving responsibilities.

### Tasks

- Add or tighten characterization tests for:
  - `DuplicateDetectionService`
  - `TargetNameResolver`
  - `CaptureGroupBuilder`
  - `HashSubGroupingService`
  - `SubgroupClassifier` orphan-video reconciliation
- Prefer behavior-oriented dry-run and decision-log assertions over internal-state-only assertions.
- For every later phase, start from a green `make test` baseline.

### Acceptance criteria

- The critical behavior of each hotspot is captured by tests before internal restructuring begins.
- Future changes can be evaluated as refactors rather than behavior rewrites.

### Risk

- low

---

## Phase 1: Extract Orphan Live Photo Reconciliation

**Goal:** Make `SubgroupClassifier` a thin bridge again.

### Motivation

`SubgroupClassifier` has a legitimate orchestration role:
- prepare per-group bridge inputs
- call `HashSubGroupingService`
- map results back to `AssetItem`

The orphan-video reconciliation is specialized pre-processing and should no longer live inline.

### New classes

- `src/Service/Pipeline/OrphanLivePhotoVideoReconciler.php`
- optional DTO if needed later:
  - `src/Model/Pipeline/OrphanVideoReconciliationResult.php`

### Responsibilities

`OrphanLivePhotoVideoReconciler`
- collect valid companion video candidates
- collect orphan video singleton groups
- count meaningful comparisons for progress reporting
- apply conservative cross-directory reconciliation rules
- emit merge decisions in the target group

`SubgroupClassifier`
- call the reconciler
- continue subgroup classification
- remain responsible only for adaptation and cluster mapping

### Pattern

- specialized collaborator + orchestrator

### Acceptance criteria

- `SubgroupClassifier` no longer contains orphan-reconciliation-specific collection and comparison logic
- the reconciliation step remains visible and progress-aware in CLI output
- no change in user-visible behavior beyond structural ownership

### Risk

- low to medium

---

## Phase 2: Thin Out Metadata Analysis Commands

**Goal:** Move domain logic out of `VerifyCommand` and `WriteDateCommand`.

### Motivation

These commands are already better than before, but they still combine:
- CLI flow
- issue analysis / reason classification
- report or write-plan assembly
- command-local formatting concerns

### New classes

For verify:
- `src/Service/Verify/MetadataIssueScanner.php`
- `src/Service/Verify/LivePhotoCompletenessAnalyzer.php`
- `src/Service/Verify/VerifyReportRenderer.php`

For write-date:
- `src/Service/WriteDate/WriteDateCandidateAnalyzer.php`
- `src/Service/WriteDate/TimezoneRewritePlanner.php`
- `src/Service/WriteDate/WriteDateEntryRenderer.php`

### Responsibilities

`VerifyCommand`
- parse CLI input
- resolve source
- invoke scanner/analyzer
- hand result to renderer
- return status code

`WriteDateCommand`
- parse CLI input
- resolve source
- invoke candidate analyzer
- optionally confirm writes
- execute planned writes
- hand output rendering to dedicated renderer

### Pattern

- application service + analyzer + presenter/renderer

### Acceptance criteria

- command classes no longer contain business-heavy loops that decide issue categories or write reasons inline
- CLI-only concerns remain in the command
- scanner/analyzer classes are testable without invoking Symfony console machinery

### Risk

- medium

---

## Phase 3: Decompose Target Naming

**Goal:** Turn `TargetNameResolver` into a coordinator over explicit naming modes.

### Motivation

`TargetNameResolver` currently mixes:
- flat naming
- subgroup-aware naming
- companion naming
- degraded-state preservation
- duplicate-number reuse and recovery logic

These are related, but not the same responsibility.

### New classes

- `src/Service/Pipeline/Naming/FlatGroupNameResolver.php`
- `src/Service/Pipeline/Naming/SubgroupNameResolver.php`
- `src/Service/Pipeline/Naming/CompanionNameResolver.php`
- `src/Service/Pipeline/Naming/ExistingNamePreservationPolicy.php`
- optional support object:
  - `src/Model/Pipeline/NamingContext.php`

### Responsibilities

`TargetNameResolver`
- choose naming mode
- coordinate shared context
- delegate actual target generation

`FlatGroupNameResolver`
- simple canonical + duplicate naming

`SubgroupNameResolver`
- cluster-aware subgroup numbering and duplicate suffixes

`CompanionNameResolver`
- companion naming within canonical or non-canonical cluster context

`ExistingNamePreservationPolicy`
- decide when degraded classification should preserve stable existing subgroup names

### Pattern

- strategy composition + policy extraction

### Acceptance criteria

- naming modes are separately testable
- existing behavior for flat groups, subgroup groups, companions, and degraded groups remains unchanged
- `TargetNameResolver` becomes materially shorter and more declarative

### Risk

- high

---

## Phase 4: Decompose Capture Group Building

**Goal:** Split collection/grouping/quality tracking inside `CaptureGroupBuilder`.

### Motivation

`CaptureGroupBuilder` currently acts as:
- file collector
- duplicate-identifier generator
- context quality tracker
- pending Live Photo video holder
- second-pass LP resolver
- final group assembler

That is too much for one class even if the overall concept is coherent.

### New classes

- `src/Service/Pipeline/CaptureCandidateCollector.php`
- `src/Service/Pipeline/CaptureGroupAssembler.php`
- `src/Service/Pipeline/PendingLivePhotoVideoResolver.php`
- `src/Service/Pipeline/CaptureGroupQualityTracker.php`
- optional:
  - `src/Service/Pipeline/DuplicateIdentifierGenerator.php`

### Responsibilities

`CaptureCandidateCollector`
- iterate files
- create initial `AssetItem` candidates from rename strategy + metadata

`CaptureGroupAssembler`
- attach items to capture groups
- create groups when missing

`PendingLivePhotoVideoResolver`
- own deferred/pending LP video handling
- resolve second-pass pairing

`CaptureGroupQualityTracker`
- write fallback/timezone/conflict flags into `PipelineContext`

### Pattern

- staged pipeline decomposition

### Acceptance criteria

- `CaptureGroupBuilder` becomes an orchestrator over clearly named stages
- pending LP resolution is no longer hidden among unrelated grouping code
- quality-flag propagation is structurally separate from group assembly

### Risk

- high

---

## Phase 5: Split Legacy Duplicate Detection Internals

**Goal:** Keep End State B but reduce internal coupling inside `DuplicateDetectionService`.

### Motivation

The legacy execution path is intentionally retained, but that does not require one oversized service implementation.

### New classes

- `src/Service/Legacy/DuplicateGroupBuilder.php`
- `src/Service/Legacy/CanonicalRenameSelector.php`
- `src/Service/Legacy/DuplicateSuffixAssigner.php`
- `src/Service/Legacy/LivePhotoDuplicateCoordinator.php`
- optional:
  - `src/Service/Legacy/LegacyDuplicateReportAccumulator.php`

### Responsibilities

`DuplicateDetectionService`
- stay as the façade used by legacy commands
- delegate grouping, canonical selection, suffix assignment, and LP-specific handling

### Pattern

- façade + specialized workers

### Acceptance criteria

- legacy commands remain on their current path
- internal responsibilities are split without changing user-visible behavior
- the service becomes a coordinator instead of an implementation bucket

### Risk

- medium to high

---

## Phase 6: Optional Deep Split of Hash Subgrouping

**Goal:** Only proceed if earlier phases still leave significant pain in `HashSubGroupingService`.

### Motivation

`HashSubGroupingService` is domain-coherent, so it should be split only when the payoff is real.

### New classes

- `src/Service/HashGrouping/HashGroupBuilder.php`
- `src/Service/HashGrouping/PerceptualHashGroupMerger.php`
- `src/Service/HashGrouping/ExcludedCompanionRenamePlanner.php`
- `src/Service/HashGrouping/SubgroupRenamePlanner.php`

### Pattern

- staged processing pipeline

### Acceptance criteria

- hash grouping, perceptual merge, and target planning are structurally separated
- no loss of current safety behavior around companion exclusions and subgroup naming

### Risk

- high

### Decision rule

Skip this phase if Phases 1–5 already reduce maintenance pain sufficiently.

---

## Commit strategy

Every structural change must land as a narrow, behavior-preserving commit.

Recommended commit sequence:

1. `test: add characterization coverage for structural refactors`
2. `refactor: extract orphan live photo video reconciler`
3. `refactor: extract verify issue scanner`
4. `refactor: extract write-date candidate analyzer`
5. `refactor: extract subgroup naming resolver`
6. `refactor: extract capture group quality tracker`
7. `refactor: split legacy duplicate detection internals`
8. `refactor: extract perceptual hash group merger`

Not every step must happen immediately, but each step should remain independently reviewable.

---

## Guardrails

- No new catch-all `Helper` classes for business logic.
- No new broad `...Service` naming if `Analyzer`, `Policy`, `Resolver`, `Planner`, `Renderer`, or `Coordinator` is more precise.
- Commands must not accumulate new business rules during the refactor.
- Shared authorities stay intact:
  - `ExifMetadataProvider::hasReliableDateTime()` remains the reliability authority
  - End State B remains in force for legacy execution paths
- `make test` must be green after every structural step.
- Refactors that change behavior must be explicitly documented as behavior changes, not hidden inside restructuring.

---

## Acceptance criteria

- Commands are visibly thinner and mostly orchestration-focused
- specialized logic blocks live in specifically named classes
- major hotspots are decomposed into collaborators that can be described in one sentence each
- no execution-path migration is smuggled in under the name of structural cleanup
- test coverage is sufficient to treat the work as a refactor, not a rewrite
- the resulting class names and package structure make responsibilities obvious without opening the implementation first
