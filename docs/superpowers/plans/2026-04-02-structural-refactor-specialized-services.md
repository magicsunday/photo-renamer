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

**Pattern rule:** Prefer `Strategy`, `Chain of Responsibility`, `Builder`, and targeted `Decorator`/`Proxy` as decomposition patterns only where they remove a concrete branching, coupling, or boundary problem already visible in the code. `Facade` and `Adapter` remain valid boundary patterns under the guardrails below.

**Out of scope:**
- migrating legacy commands onto the `rename:exif` runtime pipeline
- broad “service extraction” without a clear responsibility boundary
- splitting stable small classes only for aesthetic reasons
- changing user-visible behavior unless explicitly documented and regression-tested

---

## Architectural target state

The desired structure is:

- commands remain thin application-layer entry points
- large implementations become orchestrators/facades
- specialized decision logic moves into small collaborators with intention-revealing names
- naming follows role-based semantics (`Analyzer`, `Policy`, `Resolver`, `Planner`, `Renderer`, `Coordinator`)
- each refactor phase remains behavior-preserving, and each feature track is explicitly behavior-changing and independently testable

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
  - domain-coherent, but implementation-heavy with too many stages in one class
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
- `Builder`
  - stepwise constructs a result, projection, or aggregate output
- `Renderer`
  - output formatting only
- `Coordinator`
  - orchestrates multiple specialized collaborators
- `Facade`
  - compatibility or aggregate boundary over existing internals

Builder and Coordinator must stay distinct:

- `Builder`
  - stepwise constructs a result, projection, or aggregate output
- `Coordinator`
  - steers specialized collaborators without itself being the stepwise
    construction mechanism for the target object

Avoid for new business logic:

- vague `Helper`
- broad `Util`
- catch-all `...Service` where a narrower role name exists
- `Coordinator` classes that still contain most business decisions inline
- `Facade` classes that merely rename an existing God-object without shrinking responsibility

Role names are not a free pass for broad ownership. A well-named class that still
accumulates the underlying decision complexity has not improved the design.

### Pattern guardrails

Prefer when they solve a real structural problem:

- `Strategy`
  - for alternative selection, naming, or matching policies
- `Chain of Responsibility`
  - for reason classification, issue detection, and rule pipelines
- `Builder`
  - for projection and plan-assembly boundaries
- `Decorator` / `Proxy`
  - for caches or wrappers around expensive collaborators, without changing
    domain ownership or policy semantics
- `Adapter`
  - only at legacy/new-model boundaries
- `Facade`
  - only as a thin boundary over already-separated collaborators, not as a
    renamed implementation bucket

Avoid unless a concrete need emerges:

- `Singleton`
- `Abstract Factory`
- `Observer`
- `Mediator`
- `Visitor`
- `State`

Patterns should clarify an existing responsibility boundary, not add abstraction
for its own sake.

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

Characterization must happen on two levels:

- result-level characterization
  - target names
  - grouping outcomes
  - output tags
  - summary counts
  - write/skip decisions
- reason-level characterization
  - decision logs
  - review reasons
  - preservation decisions
  - skip/block reasons

Both levels are required. End-state-only assertions are not sufficient when
different wrong intermediate paths can accidentally converge on the same result.

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

- command orchestration + analyzer/planner + renderer
- prefer `Chain of Responsibility` inside analyzers/planners when issue or write
  reasons would otherwise turn into long conditional ladders

### Acceptance criteria

- command classes no longer contain business-heavy loops that decide issue categories or write reasons inline
- CLI-only concerns remain in the command
- scanner/analyzer classes are testable without invoking Symfony console machinery

### Risk

- medium

### Guardrail

Keep the separation strict:

- `Analyzer`
  - reports domain facts and detected problems
- `Planner`
  - turns domain facts into actionable decisions under explicit policy
- `Renderer`
  - formats output only
- `Command`
  - handles CLI interaction and exit code only

Do not introduce new mini-orchestrators that simply recreate command logic
outside Symfony Console under a different class name.

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
- prefer focused internal strategies/resolvers over a single mode-heavy resolver
  with widening conditional branches

### Acceptance criteria

- naming modes are separately testable
- existing behavior for flat groups, subgroup groups, companions, and degraded groups remains unchanged
- `TargetNameResolver` becomes materially shorter and more declarative

### Risk

- high

### Guardrail

This phase is identity-sensitive. Even small structural mistakes can produce
different visible names and therefore immediate user-facing behavior changes.

Required safety net:

- golden-master-style decision snapshots for representative naming cases
- explicit coverage for:
  - flat groups
  - subgroup numbering
  - companion naming
  - degraded/preservation paths

### Stop condition

If 2-3 extractions already make `TargetNameResolver` materially shorter and more
declarative, stop there. Do not force finer splits once the remaining code reads
cleanly and each extracted collaborator already owns a distinct naming sentence.

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
- keep `CaptureGroupBuilder` as the outer builder/orchestrator, but prefer
  ordered rule-pipeline collaborators for quality, deferral, and
  pending-companion rules when those checks would otherwise stay inline

### Acceptance criteria

- `CaptureGroupBuilder` becomes an orchestrator over clearly named stages
- pending LP resolution is no longer hidden among unrelated grouping code
- quality-flag propagation is structurally separate from group assembly
- the pipeline has a clear extension point for cross-group video reconciliation
  before strict target-basename grouping becomes final

### Risk

- high

### Guardrail

This phase is temporal/order-sensitive. Changes in buffering, deferred handling,
or second-pass timing can silently alter grouping behavior even when the final
class layout looks cleaner.

Required safety net:

- fixture-based end-to-end pipeline tests
- intentionally ugly mixed-media sets
- explicit coverage for:
  - deferred Live Photo videos
  - second-pass pairing
  - skipped/no-date files
  - mixed still/video ordering
  - cross-directory edge cases

### Stop condition

If extracting pending Live Photo resolution and quality tracking already leaves
`CaptureGroupBuilder` understandable as an orchestrator, stop there. Do not push
to a full stage explosion unless the remaining responsibilities are still hard to
describe cleanly.

---

## Feature Track A: Cross-Group Video Reconciliation

**Goal:** Cover exact-content video duplicates that diverge in metadata time,
container structure, or Live Photo identifiers before basename-based grouping
locks them into separate capture groups.

### Motivation

The current blind spot is not primarily inside `HashSubGroupingService`, but one
phase earlier. `rename:exif` groups by target basename, so two videos with the
same visual payload but different metadata timestamps never reach the normal
subgroup/perceptual merge path together.

This is therefore a behavior track, not just a structural cleanup:

- it addresses a real missed-duplicate case
- it must run as a dedicated post-build, pre-classification phase
- it needs explicit tests for group-crossing reconciliation

Treat this as a dedicated product/domain track, not as a routine sub-step of the
`CaptureGroupBuilder` refactor. It introduces:

- new matching policy
- new pipeline state
- new output semantics
- new summary semantics

### Pattern

- explicit behavior track over the existing pipeline
- `CrossGroupVideoDuplicateReconciler` as a focused coordinator
- `VideoStreamFingerprintMatcher` as a specialized video-domain matcher
- start with an explicit policy result model for `exact duplicate`,
  `candidate`, and `no match` outcomes; only introduce interchangeable
  `Strategy` implementations if multiple classification policies actually emerge
- use `Decorator` / `Proxy` if fingerprint or perceptual sub-steps need caching;
  keep cache concerns out of the core matcher implementation

### New classes

- `src/Service/Pipeline/CrossGroupVideoDuplicateReconciler.php`
- `src/Service/Video/VideoStreamFingerprintMatcher.php`
- `src/Service/Pipeline/PipelineReviewMapper.php`
- support DTOs:
  - `src/Model/Pipeline/VideoFingerprintMatch.php`
  - `src/Model/Pipeline/VideoDuplicateCandidate.php`

### Responsibilities

`CrossGroupVideoDuplicateReconciler`
- run as an explicit regrouping step after `CaptureGroupBuilder::build()`
  and before `SubgroupClassifier::classify()`
- inspect candidate videos across existing groups
- use cheap guards first:
  - same normalized duration
  - same media family
  - conservative candidate filtering
- ask `VideoStreamFingerprintMatcher` for exact-content evidence
- merge groups only for exact-duplicate cases allowed by policy
- write non-merged review findings only as structured `VideoDuplicateCandidate`
  facts into `PipelineContext`

`VideoStreamFingerprintMatcher`
- extract/cache stream-level fingerprints for:
  - video
  - optional audio
- ignore container-level metadata differences
- return structured evidence, not grouping decisions

### Policy

The policy for exact-content video reconciliation must be explicit:

- `video stream match + audio stream match`
  - qualifies as an exact-content duplicate signal
- `video stream match + no audio on both sides`
  - qualifies as an exact-content duplicate signal
- `video stream match + missing audio on one side`
  - conservative candidate only, not auto-merge by default
- `video stream match + audio mismatch`
  - conservative candidate only, not auto-merge by default
- `video/audio policy satisfied, non-A/V data tracks differ`
  - still treat as container noise, not as a merge blocker
- `video stream mismatch`
  - not an exact-content duplicate

This keeps the current safety bar:

- identical picture track alone is not enough for silent auto-merge
- cases like the Zoo MOV pair remain visible for follow-up analysis
- true container-only rewrites still become detectable
- extra QuickTime `data` tracks do not prevent otherwise valid matches

### Required UX decisions

The implementation must not stop at internal classification. It needs explicit
user-visible behavior for non-exact-but-suspicious matches:

- `exact duplicate`
  - safe to auto-merge into the same duplicate/canonical flow
- `candidate`
  - surfaced in CLI output as a reviewable finding, not silently merged
- `no match`
  - no extra output beyond existing pipeline behavior

For the initial rollout, `candidate` should stay conservative:

- show both paths involved
- show why the pair stopped short of auto-merge
  - for example: `video stream identical, audio differs`
- avoid reusing the existing duplicate tag for these entries
- prefer a distinct warning/candidate-style output category over implicit merge behavior

That makes the feature operationally useful even before the project is ready to
auto-merge every stream-level near-match.

Ownership rule:

- `CrossGroupVideoDuplicateReconciler`
  - classifies and records candidate facts into `PipelineContext`
- `PipelineContext`
  - owns a structured `videoDuplicateCandidates` collection
- `PipelineReviewMapper`
  - projects `VideoDuplicateCandidate` entries into output-ready review entries
- `RenameResult`
  - carries the projected review entries and their summary counts across the
    execution/output boundary
- `ExecutionPlanBuilder`
  - remains focused on projecting grouped execution items only
- `RenameOutputRenderer`
  - remains the single output channel for rendering both:
    - execution-plan entries
    - review entries supplied via `RenameResult`
  - may format them, but must not invent the underlying duplicate policy

Recommended transport contract:

- `PipelineReviewMapper` produces review entries before `PipelineContext::toRenameResult()`
- `RenameResult` stores those review entries explicitly
- `RenameOutputRenderer::buildOutputEntriesFromPlan()` appends them to the normal
  execution-plan-derived entries
- do not add a second renderer parameter just for this feature

### Technical insertion point

This track should be a dedicated phase between group building and subgroup
classification:

- `CaptureGroupBuilder::build()`
- `CrossGroupVideoDuplicateReconciler::reconcile()`
- `SubgroupClassifier::classify()`

Why this exact position:

- `TargetBasenameStrategy` groups by generated timestamp basename
- metadata drift splits exact-content videos into different `AssetGroup`s
- a later in-group matcher cannot repair a pair that never shared a group
- embedding this logic inside `CaptureGroupBuilder` would overload the builder
  with broader reconciliation policy instead of keeping it focused on initial grouping

This is the only intended insertion point for the feature. The plan should not
be interpreted as permission to embed the behavior inside `CaptureGroupBuilder`
or to postpone it until `SubgroupClassifier`.

### Interaction with existing orphan Live Photo reconciliation

This broader cross-group video track must remain clearly separate from the
existing orphan-Live-Photo special case in `SubgroupClassifier`.

Separation rules:

- orphan Live Photo reconciliation
  - only for video-only orphan groups around an already-valid still+video pair
  - may merge directly when the existing Live Photo-specific policy allows it
- cross-group video reconciliation
  - applies to normal videos regardless of Live Photo context
  - must not assume a still anchor or content identifier relationship
  - must not silently reuse Live Photo-specific decision messages

Recommended integration rule:

- run the new cross-group video reconciliation before the existing orphan-Live-Photo
  step narrows the problem to companion-specific cases
- keep separate decision logs and separate tests for both mechanisms
- do not let one mechanism call the other internally; they may share low-level
  fingerprinting/matching helpers, but not business ownership

### Structural placement rule

`VideoStreamFingerprintMatcher` should not live under `HashGrouping/` anymore.
Its responsibility is broader than hash-subgrouping:

- it supports pre-group reconciliation
- it may later support orphan-video reconciliation
- it answers a low-level video-fingerprinting question, not a hash-cluster question

`src/Service/Video/` is therefore the preferred home unless a broader media-level
package is introduced later.

### Acceptance criteria

- exact-content video duplicates with different container metadata can be
  surfaced across groups
- the policy for audio mismatch vs. exact duplicate is documented and tested
- additional non-A/V data tracks are explicitly treated as container noise
- the feature lands as an explicit behavior change, not as hidden refactor fallout
- existing Live Photo–specific reconciliation remains separate from this broader track
- candidate UX is defined up front so ambiguous matches do not disappear into logs
- the data path is explicit:
  - `PipelineContext` stores review facts
  - `PipelineReviewMapper` projects them
  - `RenameResult` transports them
  - `RenameOutputRenderer` renders them centrally
- review findings are counted in the summary, not only listed inline

### Output-tag rule

Do not reuse the existing `OutputEntryTag::Candidate` semantics for this feature.
That tag already carries the meaning “conflicting Live Photo content IDs”.

For cross-group video review findings, introduce a distinct output concept:

- add `OutputEntryTag::Review`
- assign it its own tag letter for `--show`
- define its own color and skip/display semantics explicitly

This preserves clear `--show` semantics:

- Live Photo conflict review remains its own category
- cross-group video review remains its own category
- users must not have to infer the difference from free-form message text alone

### Summary rule

Cross-group video review findings should not only appear as individual lines.
They should also contribute a dedicated summary counter:

- `cross-group video review`

This label should be used directly during implementation so the signal stays
specific and does not get diluted into a generic catch-all review bucket.

### Risk

- high

### Branching guidance

If this track is implemented before the larger core refactors stabilize, it
should land as a clearly visible feature branch/track rather than being mixed
quietly into structural cleanup commits.

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
- if worker extraction introduces alternative canonical or suffix policies,
  prefer `Strategy` over boolean mode switches

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

The video-specific duplicate behavior track belongs in Feature Track A. Phase 6 should
only consume the already-defined `VideoStreamFingerprintMatcher` as a narrower
collaborator if the subgrouping internals are later decomposed.

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
5. `refactor: split legacy duplicate detection internals`
6. `refactor: extract subgroup naming resolver`
7. `refactor: extract capture candidate collector`
8. `refactor: extract capture group assembler`
9. `refactor: extract pending live photo video resolver`
10. `refactor: extract capture group quality tracker`
11. `feat: add cross-group video duplicate reconciliation`
12. `refactor: extract perceptual hash group merger`

Not every step must happen immediately, but each step should remain independently reviewable.

Recommended phase order:

1. Phase 0
2. Phase 1
3. Phase 2
4. Phase 5
5. Phase 3
6. Phase 4
7. Feature Track A
8. optional Phase 6

Rationale:

- Phases 1 and 2 provide faster structural wins at moderate risk
- Phase 5 relieves pressure in the bounded legacy path
- Phases 3 and 4 are the most dangerous core refactors
- Feature Track A should either land after the affected structures stabilize,
  or be executed separately with full visibility as a feature effort

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

### Delivery workflow

Every phase or feature track should be executed as a sequence of small reviewed subtasks:

- start each subtask by adding or tightening the test that captures the intended
  behavior or structural guardrail
- make the smallest code change that satisfies that test
- run `make test`
- review the just-completed subtask before starting the next one

This plan should be executed in a TDD-style rhythm wherever practical. The goal
is to keep refactors behavior-safe and feature-track changes explicitly
controlled and continuously reviewable, especially in the identity-sensitive and
order-sensitive phases.

---

## Definition of Done for Each Phase or Feature Track

Each phase or feature track is only complete when all of the following are true:

- `make test` is green
- the affected orchestrator or boundary is shorter, clearer, or more declarative than before
- each extracted collaborator can be described in one clear business sentence

---

## Acceptance criteria

- Commands are visibly thinner and mostly orchestration-focused
- specialized logic blocks live in specifically named classes
- major hotspots are decomposed into collaborators that can be described in one sentence each
- no execution-path migration is smuggled in under the name of structural cleanup
- test coverage is sufficient to treat the work as a refactor, not a rewrite
- the resulting class names and package structure make responsibilities obvious without opening the implementation first
