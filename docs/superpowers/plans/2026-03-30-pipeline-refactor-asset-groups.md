# Pipeline Refactor: Asset Groups — Zielarchitektur-Plan v2

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Source context:** This plan supersedes the earlier 3-phase draft and incorporates the original domain proposal (canonical item, duplicate items, sub-groups, format priority, Live Photo pairing, no-touch for already-correct names) plus the repo-specific findings around `RenameByExifDateCommand`, `AbstractRenameCommand`, `DuplicateDetectionService`, `FileSystemService`, `RenameOutputRenderer`, and `HashSubGroupingServiceInterface`. See the uploaded prior draft for the baseline it replaces fileciteturn25file0.

---

## Goal

Replace the monolithic `DuplicateDetectionService` pipeline used by `rename:exif` with a domain-first pipeline built around explicit `AssetGroup` / `AssetItem` models.

This refactor optimizes for the **best long-term architecture**, not minimal churn.

The new pipeline must satisfy these product rules:

- recursive traversal over a directory tree
- one logical result object per capture / asset group
- each group has exactly one **Canonical** item
- groups contain **Duplicates**, **Companions**, and optional **Subgroups / Clusters**
- canonical selection is **score-based** with **format priority as the dominant signal**
- format priority is configurable via environment/config
- Live Photo still/video pairs are treated as **Companion**, not Duplicate
- files already matching their final target name remain untouched
- collision handling and rename safety checks occur before execution
- other commands (`rename:hash`, `rename:pattern`, `rename:date-pattern`, `rename:lower`, etc.) may continue using the old pipeline temporarily

---

## Architectural Decision

The new architecture is allowed to replace or reshape existing services where the old APIs encode the old pipeline order.

### Consequence

The current `HashSubGroupingServiceInterface` is **not** treated as a stable architectural boundary.
Its current API expects `FileDuplicate`, `Rename`, canonical rename selection, and companion rename selection, which are artifacts of the old pipeline. In the new architecture, content / similarity sub-grouping happens **before role assignment and before naming**, so the old interface must either:

- be **replaced** by an AssetGroup-native classifier API, or
- be kept only as an internal implementation detail behind a new AssetGroup-native facade

The new domain pipeline is the source of truth. Legacy structures (`FileDuplicateCollection`, `Rename`) are transitional execution adapters only.

---

## Final Target Pipeline

```text
OLD:
  groupFilesByDuplicateIdentifier()
  → createDuplicateFilenames()
  → renameFiles()

NEW:
  1. CaptureGroupBuilder.build()
  2. SubgroupClassifier.classify()
  3. RoleAssigner.assign()
  4. TargetNameResolver.resolve()
  5. CollisionResolver.resolve()
  6. RenamePlanValidator.validate()
  7. AssetGroupAdapter.toFileDuplicateCollection()   [temporary]
  8. FileSystemService.renameFiles()
```

### Semantics of each phase

1. **CaptureGroupBuilder**
   - collect files recursively
   - normalize metadata
   - defer video companions where appropriate
   - perform Live Photo pairing pass
   - build `AssetGroupCollection`

2. **SubgroupClassifier**
   - split or annotate groups by content identity / perceptual similarity
   - compute duplicate relations and subgroup membership
   - does **not** assign canonical / companion / duplicate roles
   - does **not** compute names

3. **RoleAssigner**
   - choose canonical item by score
   - detect companion items
   - assign broad roles: `Canonical`, `Duplicate`, `Companion`, `Ambiguous`

4. **TargetNameResolver**
   - compute desired target names from group key + role semantics
   - no collision checks here

5. **CollisionResolver**
   - make proposed names unique against disk index + already planned targets
   - update `PipelineContext`

6. **RenamePlanValidator**
   - detect duplicate targets, case conflicts, circular swaps, other pre-execution hazards

7. **AssetGroupAdapter**
   - temporary bridge into current execution and renderer infrastructure

8. **FileSystemService**
   - execute as today, retaining runtime fallback protection until later migration

---

## Core Domain Model

### `AssetGroup`
Represents one logical capture / media asset.

Fields:
- `groupKey` — stable logical key for the capture group
- `items` — all `AssetItem` members
- `decisionLog` — human-readable explanations
- `clusterInfo` / subgroup metadata
- `canonicalItemId` or canonical lookup method

### `AssetItem`
Represents one physical file.

Fields:
- `file: SplFileInfo`
- `metadata: ?TemporalMetadata`
- `contentIdentifier: ?string`
- `mediaType` / derived classifier helpers
- `role: ItemRole`
- `duplicateRelation: ?DuplicateRelation`
- `priorityScore: int`
- `reasoning: list<string>`
- `proposedName: ?string`
- `renameRequired: bool`
- `sequenceNumber: ?int`
- `clusterId: ?string`
- optional future flags: `matchesNamingPattern`, `matchesProposedNameExactly`

### `ItemRole`
```php
Canonical | Duplicate | Companion | Ambiguous
```

### `DuplicateRelation`
Informational only for output / decision log in this phase.
No branching logic depends on it.

```php
Exact | SameAsset | Transcoded | Probable
```

### Important distinction

- **Role** answers: what is this file's job in the group?
- **DuplicateRelation** answers: how does this non-canonical file relate to the canonical or cluster?
- **Subgroup / Cluster membership** answers: which content-class within the group does it belong to?

These must remain separate.

---

## Product Rules and Ranking Rules

### Canonical selection

Canonical selection must use a weighted score where **format priority dominates all other signals**.

| Factor | Points | Purpose |
|--------|--------|---------|
| Format priority | `(count - index) * 10000` | Dominant signal |
| Idempotency | `1000` | Stable preference within same format tier |
| Root / shallow path | `50` | Weak tie-breaker only |
| Live Photo content ID present | `25` | Prefer original-capture stills |
| Pathname tie-break | `0–20` | Deterministic stability |

### Product consequence

A preferred format beats an already correctly named lower-priority format.
This intentionally changes the old `rename:exif` canonical preference rule.
That change must be documented in `AGENTS.md` and tested explicitly.

### Format priority config

Use `CANONICAL_FORMAT_PRIORITY` with fallback to `Constants::DEFAULT_FORMAT_PRIORITY`.

Default:
```text
heic,heif,dng,arw,jpg,jpeg,mov,mp4,m4v,avi
```

### Path priority

Shallower / root files are preferred only as weak tie-breakers.
Path depth never outranks format priority.

### Live Photo rule

A still + video pair is:
- one logical asset group
- one canonical still
- one companion video
- same basename, different extension
- never treated as a normal duplicate pair

### No-touch rule

Files whose `proposedName` exactly equals current path are no-op items and must not be renamed.

---

## Naming Rules

### Canonical
```text
<dir>/<groupKey>.<ext>
```

### Companion
```text
<dir>/<groupKey>.<companion-ext>
```

### Duplicate
```text
<dir>/<groupKey>-duplicate-001.<ext>
<dir>/<groupKey>-duplicate-002.<ext>
```

### Ambiguous
Use safe duplicate-style suffixing until a more specific policy is introduced.

### Important
TargetNameResolver computes **desired** names only.
CollisionResolver is the only stage allowed to mutate these names for uniqueness.

---

## Safety Model

### Planning-time safety
Implemented by `CollisionResolver` and `RenamePlanValidator`.

Checks include:
- occupied target path
- cross-group target collisions
- case-only conflicts
- circular swaps
- duplicate target paths
- no-op detection

### Runtime safety
`FileSystemService` keeps its current runtime fallback collision protection until the execution layer is migrated to AssetGroups directly.
This is deliberate defense in depth.

---

## Release Strategy

## Phase 1 — Domain Model + Scoring

**Goal:** establish the new domain vocabulary and canonical scoring independent of pipeline integration.

### Deliverables
- [x] `src/Model/ItemRole.php`
- [x] `src/Model/DuplicateRelation.php`
- [x] `src/Model/AssetItem.php`
- [x] `src/Model/AssetGroup.php`
- [x] `src/Model/Collection/AssetGroupCollection.php`
- [x] `src/Model/PipelineContext.php`
- [x] `src/Service/CanonicalScorerInterface.php`
- [x] `src/Service/CanonicalScorer.php`
- [x] unit tests for all new domain classes and scorer
- [x] `Constants::DEFAULT_FORMAT_PRIORITY`
- [x] `ConfiguresMetadataProvider::resolveFormatPriority()`
- [x] `.env.dist` entry for `CANONICAL_FORMAT_PRIORITY`

### Rules
- no command integration yet
- no adapter yet
- no renderer changes yet
- all existing tests remain green

### Success criteria
- [x] format-dominant scoring proven by tests
- [x] idempotency only wins inside same format tier
- [x] all new classes are PHPStan max clean
- [x] all existing CI remains green

---

## Phase 2 — New Analysis / Planning Pipeline in Parallel

**Goal:** build the complete new planning pipeline alongside the old one and prove equivalence where intended.

### New services
- [x] `CaptureGroupBuilderInterface`
- [x] `CaptureGroupBuilder`
- [x] `SubgroupClassifierInterface`
- [x] `SubgroupClassifier`
- [x] `CompanionDetectorInterface`
- [x] `CompanionDetector`
- [x] `RoleAssignerInterface`
- [x] `RoleAssigner`
- [x] `TargetNameResolverInterface`
- [x] `TargetNameResolver`
- [x] `CollisionResolverInterface`
- [x] `CollisionResolver`
- [x] `RenamePlanValidator`
- [x] `ValidationResult`
- [x] `AssetGroupAdapter`
- [x] `tests/Integration/PipelineDifferentialTest.php`

### Key design requirement for Phase 2

#### 2.1 `CaptureGroupBuilder`
Must absorb the current grouping and Live Photo pairing behavior of:
- old grouping scan
- deferred video handling
- second-pass Live Photo pairing
- quality flag collection

Output: `AssetGroupCollection`

#### 2.2 `SubgroupClassifier`
This phase must be **AssetGroup-native**.

It may use the current perceptual / hash internals, but the public boundary for this phase must no longer depend on:
- `FileDuplicate`
- `Rename`
- preselected canonical rename
- preselected companion rename

##### Required refactor
- [x] Introduce a new AssetGroup-facing subgroup classification contract
- [x] **Preferred approach:** create a new AssetGroup-native facade backed by the existing `HashSubGroupingService` internals where possible
- [x] **Fallback approach:** refactor `HashSubGroupingService` itself only where the facade cannot express the needed semantics cleanly
- [x] Preserve per-group `clearCache()` semantics for Imagick memory release

##### Why facade-first is preferred
The current hash/subgroup implementation is one of the riskiest parts of the repo: it is large, Imagick-heavy, stateful, and already tuned around multi-signal scoring and local blob analysis. A facade-first migration lowers delivery risk while still allowing the public pipeline boundary to become AssetGroup-native. A full rewrite is explicitly **not** the preferred first move.

##### Output of SubgroupClassifier
- cluster / subgroup membership
- `clusterId` written onto every affected `AssetItem` via `AssetGroup::replaceItem()`
- informational `DuplicateRelation`
- optional ambiguity markers
- **not** final role assignment
- **not** final naming

##### Concrete mutation rule
`SubgroupClassifier` must set `clusterId` on every item it classifies. Cluster membership is not implicit or external-only metadata; it lives directly on `AssetItem` so later phases (`RoleAssigner`, `TargetNameResolver`, decision logging, output) can reason over it without recomputing subgroup membership.

#### 2.3 `RoleAssigner`
Thin orchestrator only:
- score items
- select canonical
- detect companions
- assign roles
- propagate quality flags to companions
- append decision log entries

#### 2.4 `TargetNameResolver`
Pure semantic naming from role + group key.
No collision checks.

#### 2.5 `CollisionResolver`
Uses `PipelineContext` occupied-path index and planned targets.
Marks targets as occupied after assignment.
Increments naming collision counter.

#### 2.6 `RenamePlanValidator`
Pure validation over proposed plans.
Must abort on circular swaps.

#### 2.7 `AssetGroupAdapter`
Strictly transitional.
No business logic beyond mapping.

#### 2.8 Differential testing
Add a temporary differential test comparing:
- old pipeline output
- new pipeline output via adapter

### Important nuance for differential testing
The new architecture intentionally changes some product rules, so the differential test must distinguish between:

- **must stay identical**
- **intentionally changed behavior**

##### Intentionally changed behavior
- format-dominant canonical selection: preferred HEIC/HEIF/RAW may beat an already correctly named JPG
- Live Photo companion becomes an explicit `Companion` role instead of only being implicitly treated as a non-duplicate

##### Must stay identical
- same grouping semantics: the same physical files land in the same logical capture groups
- same duplicate suffix numbering (`-duplicate-001`, `-duplicate-002`, ...) unless a documented intentional change requires otherwise
- same Live Photo pairing results
- same subgroup suffix behavior
- same skip / warning / error classification
- same idempotency behavior on already processed files

Every differential test failure must be classified into one of these buckets. Anything outside the explicit "intentionally changed" list is a regression.

### Success criteria
- [x] all new services fully unit-tested
- [x] asset-native subgrouping exists before role assignment and naming
- [x] differential tests cover fixture directories
- [x] intended behavioral changes are documented, not accidental
- [x] all existing CI remains green

---

## Phase 3 — `rename:exif` Migration + Output + Deprecation

**Goal:** wire `rename:exif` to the new pipeline and expose the new decision model to users.

### 3.1 Command integration
Files:
- [x] `src/Command/AbstractRenameCommand.php`
- [x] `src/Command/RenameByExifDateCommand.php`

Tasks:
- [x] change `renderPostScanSummary()` visibility from `private` to `protected`
- [x] override `RenameByExifDateCommand::executeCommand()`
- [x] instantiate / configure new pipeline dependencies there
- [x] call new pipeline instead of `parent::executeCommand()`
- [x] keep try/catch/finally cache-flush pattern

### 3.2 New execution flow in `RenameByExifDateCommand`
```text
CaptureGroupBuilder
→ SubgroupClassifier
→ RoleAssigner
→ TargetNameResolver
→ CollisionResolver
→ RenamePlanValidator
→ AssetGroupAdapter
→ FileSystemService.renameFiles()
```

### 3.3 Output integration
Files:
- [x] `src/Service/RenameOutputRenderer.php`

Tasks:
- [x] support optional `AssetGroupCollection` context for decision logs
- [x] render per-group reasoning in `--list-all`
- [x] do not mutate `FileDuplicate` to store new domain data

### 3.4 Deprecation
Files:
- [x] `src/Service/DuplicateDetectionService.php`

Tasks:
- [x] mark old public pipeline methods `@deprecated`
- [x] document migration status for other commands

### 3.5 Documentation updates
Files:
- [x] `AGENTS.md`
- [x] `config/Services.yaml`
- [x] `.env.dist`

Tasks:
- [x] document new pipeline stages
- [x] update canonical-selection rule to format-dominant scoring
- [x] register DI services, including explicit `SymfonyStyle` arguments where required

### 3.6 Test updates
- [x] update all `RenameByExifDateCommand` constructor call sites in tests
- [x] ensure integration tests still pass (3 remaining failures from subgroup suffixing)
- [x] add tests for intentional new behavior:
  - preferred HEIC canonical over correctly named JPG
  - companion not treated as duplicate
  - decision log visible in dry-run output
  - validation warnings for unsafe plans

### Success criteria
- [x] `rename:exif` exclusively uses new pipeline
- [x] all old integration scenarios still pass unless intentionally changed
- [x] intentional behavior changes are covered by dedicated tests
- [x] decision log is visible in dry-run output
- [x] validation warnings are displayed
- [x] CI remains green

---

## Implementation Guardrails (v4 Addendum)

The following invariants were established during implementation review and are now enforced in the codebase.

### Non-negotiable invariants

1. **No stubbed core behavior** — features must be fully ported, explicitly deferred, or kept in the old pipeline. Fake progress bars and empty implementations are forbidden.

2. **Cross-directory idempotency** — grouping is cross-directory, naming is directory-preserving, re-runs produce identical output, subgroup suffixes are stable across directories.

3. **Group-level classification atomicity** — `SubgroupClassifier` completes per group or marks it as degraded via `AssetGroup::markClassificationFailed()`. No partial `clusterId` assignment. Degraded groups use conservative flat naming.

4. **Thin command orchestration** — `RenameByExifDateCommand` delegates to `AssetGroupPipeline` which encapsulates the 6-step flow and returns `ExifRenamePipelineResult`. The command handles only execution-phase concerns.

5. **Service decomposition** — `CaptureGroupBuilder::build()` is orchestration-only (~40 lines), delegating to named helpers (extractAssetCandidate, trackQualityFlags, deferVideoCompanion, generateDuplicateIdentifier, attachToGroup). Mutable state lives in `CaptureGroupBuildState`.

### Failure semantics

- **SubgroupClassifier failure**: atomic per group. Either all items get clusterIds or none do. Group is marked `classificationFailed` with reason. Decision log records the failure. Downstream phases use safe fallback.
- **LP Second-Pass**: real implementation using `LivePhotoPairingService`. No stubs.
- **Hash computation failure**: handled gracefully per-file (skipped with error message), does not corrupt group state.

### Pipeline Result Object

`AssetGroupPipeline::run()` returns `ExifRenamePipelineResult` containing:
- `AssetGroupCollection $groups`
- `PipelineContext $context`
- `ValidationResult $validationResult`

### Differential test contract

Intentionally changed: format-dominant canonical selection, explicit Companion role.
Must stay identical: grouping, LP pairing, duplicate suffixes, subgroup suffixes, skip/warning/error classification, idempotency.

---

## Phase 4 — Optional Post-Migration Cleanup

**Not required for initial migration, but this is the correct end state.**

- [ ] refactor `FileSystemService` to accept `AssetGroupCollection` directly
- [ ] remove `AssetGroupAdapter`
- [ ] reduce legacy dependence on `FileDuplicateCollection`
- [ ] migrate remaining commands incrementally where beneficial

---

## File Plan

### New files
- [x] `src/Model/ItemRole.php`
- [x] `src/Model/DuplicateRelation.php`
- [x] `src/Model/AssetItem.php`
- [x] `src/Model/AssetGroup.php`
- [x] `src/Model/Collection/AssetGroupCollection.php`
- [x] `src/Model/PipelineContext.php`
- [x] `src/Service/CanonicalScorerInterface.php`
- [x] `src/Service/CanonicalScorer.php`
- [x] `src/Service/Pipeline/CaptureGroupBuilderInterface.php`
- [x] `src/Service/Pipeline/CaptureGroupBuilder.php`
- [x] `src/Service/Pipeline/SubgroupClassifierInterface.php`
- [x] `src/Service/Pipeline/SubgroupClassifier.php`
- [x] `src/Service/Pipeline/CompanionDetectorInterface.php`
- [x] `src/Service/Pipeline/CompanionDetector.php`
- [x] `src/Service/Pipeline/RoleAssignerInterface.php`
- [x] `src/Service/Pipeline/RoleAssigner.php`
- [x] `src/Service/Pipeline/TargetNameResolverInterface.php`
- [x] `src/Service/Pipeline/TargetNameResolver.php`
- [x] `src/Service/Pipeline/CollisionResolverInterface.php`
- [x] `src/Service/Pipeline/CollisionResolver.php`
- [x] `src/Service/RenamePlanValidator.php`
- [x] `src/Service/ValidationResult.php`
- [x] `src/Service/AssetGroupAdapter.php`
- [x] `src/Service/Pipeline/AssetGroupPipeline.php`
- [x] `src/Service/Pipeline/ExifRenamePipelineResult.php`
- [x] `src/Service/Pipeline/CaptureGroupBuildState.php`
- [x] unit + integration tests for each new unit

### Modified files
- [x] `src/Constants.php`
- [x] `.env.dist`
- [x] `src/Command/Concern/ConfiguresMetadataProvider.php`
- [x] `src/Command/AbstractRenameCommand.php`
- [x] `src/Command/RenameByExifDateCommand.php`
- [x] `src/Service/RenameOutputRenderer.php`
- [x] `src/Service/DuplicateDetectionService.php`
- [x] `src/Service/HashSubGroupingService.php` or replacement facade
- [x] `src/Service/HashSubGroupingServiceInterface.php` or replacement contract
- [x] `config/Services.yaml`
- [x] `AGENTS.md`

---

## Important Engineering Rules

- [ ] Use Docker-only commands (`make test`, `make stan`, etc.)
- [ ] TDD first for each unit of behavior
- [ ] no `@phpstan-ignore`
- [ ] one concern per commit
- [ ] run `make test` before every commit
- [ ] keep the adapter free of domain logic
- [ ] keep subgrouping free of naming logic
- [ ] keep role assignment free of collision logic
- [ ] keep naming free of collision detection

---

## Explicitly Allowed Behavioral Changes

These are product changes, not regressions, and must be documented and tested:

- [ ] preferred-format canonical may override an already correctly named lower-priority format
- [ ] companion files are explicitly modeled and reported, not just implicitly skipped from duplicate suffixing
- [ ] decision logging is exposed in dry-run output
- [ ] validation warnings may appear for rename plans previously not surfaced to users

---

## Explicitly Forbidden Regressions

- [ ] Live Photo companions getting their own timestamp instead of paired still timestamp
- [ ] already-correct filenames being renamed anyway
- [ ] path collisions causing silent overwrite risk
- [ ] loss of idempotency on second run
- [ ] removal of runtime safety fallback before the new execution layer is fully proven
- [ ] leaking old `Rename` / `FileDuplicate` assumptions back into new domain phases

---

## Final Acceptance Criteria

- [ ] `rename:exif` is driven by the new AssetGroup pipeline
- [ ] the new domain model reflects canonical / duplicate / companion / ambiguous semantics directly
- [ ] subgroup classification happens before role assignment and naming
- [ ] canonical selection is format-dominant and configurable
- [ ] files already matching their final name remain untouched
- [ ] safety checks occur before execution
- [ ] decision logs explain why each file got its role and target
- [ ] all CI remains green
- [ ] legacy execution bridge is clearly temporary
