# Phase 4 Plan — Execution/Output Migration + Legacy Finalization (Reviewed v3)
Branch target: `feature/asset-group-pipeline`

> Goal: Complete the asset-group migration by removing the temporary `AssetGroupAdapter -> FileDuplicateCollection` bridge for `rename:exif`, migrating execution + output to a dedicated runtime model derived from `AssetGroupCollection`, and then explicitly deciding how the remaining legacy execution layer is retained, consolidated, or removed so the project does not keep two parallel runtime architectures by accident.

---

## Why this plan exists

On `feature/asset-group-pipeline`, the analysis/planning pipeline is already migrated:

```text
CaptureGroupBuilder -> SubgroupClassifier -> RoleAssigner
-> TargetNameResolver -> CollisionResolver -> RenamePlanValidator
```

and is encapsulated behind `AssetGroupPipeline`.

However, `rename:exif` still exits the new model here:

```text
AssetGroupCollection -> AssetGroupAdapter -> FileDuplicateCollection
-> FileSystemService::renameFiles() -> RenameOutputRenderer
```

This plan removes that transitional bridge and then closes the open question of how much of the legacy execution DTO layer should remain in the project.

This is **not** a cleanup-only phase. It is a second architectural migration:
- from legacy execution model (`FileDuplicateCollection`, `FileDuplicate`, `RenameList`)
- to a dedicated runtime execution/output model derived from `AssetGroupCollection`

---

## Design decision

## Do **not** push `AssetGroupCollection` directly into FileSystemService

Introduce a dedicated runtime model:

- `ExecutionPlan`
- `ExecutionGroup`
- `ExecutionItem`

This keeps a clean separation between:
- **analysis/planning domain** (`AssetGroupCollection`)
- **runtime execution/output domain** (`ExecutionPlan`)

### Why this is preferred

- avoids leaking analysis-only fields into I/O code
- gives Renderer and FileSystemService a smaller, stable contract
- makes ordering, tagging, summaries, and runtime fallback easier to test
- avoids replacing one explicit adapter with another hidden adapter inside executor/renderer

---

## Non-negotiable invariants

These must hold throughout Phase 4A and 4B.

### Behavior that must stay identical

- idempotency: rerunning on already processed files still produces `[O]`
- cross-directory grouping semantics remain unchanged
- files remain in their original directories after renaming
- Live Photo still + companion remain atomic in output and skip/warning propagation
- runtime collision fallback still prevents data loss
- circular swaps still abort execution
- summary counters remain semantically identical
- output tags remain semantically identical:
  - `[R]`, `[F]`, `[D]`, `[O]`, `[W]`, `[S]`, `[E]`, `[C]`
- dry-run semantics remain identical
- `rename:hash`, `rename:pattern`, `rename:date-pattern`, `rename:lower`, etc. continue working unchanged until deliberately migrated

### Intentionally changed behavior

None in Phase 4A.

Phase 4A is an execution/output migration only.
All intentional behavior changes were introduced earlier in the asset-group pipeline phases.
If a visible behavior changes in Phase 4A, treat it as a regression unless explicitly documented.

### Phase 4B note

Phase 4B may remove legacy DTOs and old execution paths, but it must not introduce user-visible behavior changes without explicit documentation and parity tests.

---

## Source of truth table

To prevent duplicated counting and hidden logic drift, each concern has one primary owner.

| Concern | Source of truth | Notes |
|--------|------------------|-------|
| scanned files | `PipelineContext` | Derived during scan/build, not recomputed in runtime model |
| naming collisions | `PipelineContext` | Set by planning path |
| skipped files | `PipelineContext` | Includes metadata/read failures from earlier phases |
| fallback date flags | `PipelineContext` | Analysis quality state |
| ambiguous timezone flags | `PipelineContext` | Analysis quality state |
| live photo conflict flags | `PipelineContext` | Analysis quality state |
| planned moves | `ExecutionPlan` / renderer projection | Runtime/output-specific |
| planned skips | `ExecutionPlan` / renderer projection | Runtime/output-specific |
| live photo group count | `ExecutionPlan` | Derived from runtime group composition, not legacy prefixes |
| duplicate/output tags | `ExecutionPlan` projected by renderer | No hidden tag logic in executor |
| runtime occupied-path fallback | `FileSystemService::executePlan()` | Safety layer only |
| rename-plan conflicts | `RenamePlanValidator` | Planning validation remains authoritative |
| summary display values for scan/analysis | `PipelineContext.toRenameResult()` | Passed separately to renderer/executor; not copied into `ExecutionPlan` |

### Explicit ownership rules

- `PipelineContext` owns **analysis and scan metadata**
- `ExecutionPlan` owns **runtime execution and output projection data**
- `RenameOutputRenderer` owns **rendering only**, not business classification
- `FileSystemService` owns **file I/O and runtime safety only**, not naming policy

---

## New target architecture

### Phase 4A target

```text
RenameByExifDateCommand
  -> AssetGroupPipeline::run()
  -> ExecutionPlanBuilder::build()
  -> PipelineContext.toRenameResult()
  -> RenameOutputRenderer
  -> FileSystemService::executePlan()
```

Legacy path remains for non-`rename:exif` commands:

```text
AbstractRenameCommand
  -> DuplicateDetectionService
  -> FileDuplicateCollection
  -> FileSystemService::renameFiles()
```

### Phase 4B target

After legacy consolidation, the project should converge toward:
- one preferred runtime/output execution path
- legacy DTOs retained only if a concrete unmigrated command still truly needs them

---

## New runtime model

## File structure

### New files

| File | Responsibility |
|------|----------------|
| `src/Model/Execution/ExecutionPlan.php` | Full runtime plan for one command invocation |
| `src/Model/Execution/ExecutionGroup.php` | One logical group in runtime form |
| `src/Model/Execution/ExecutionItem.php` | One executable/renderable item |
| `src/Model/Execution/ExecutionItemType.php` | Enum: Canonical, Companion, Duplicate, Ambiguous, Skipped |
| `src/Service/Execution/ExecutionPlanBuilder.php` | Converts `AssetGroupCollection` + `PipelineContext` into `ExecutionPlan` |
| `src/Service/Execution/ExecutionPlanBuilderInterface.php` | Interface |
| `tests/Unit/Model/Execution/*.php` | Runtime model tests |
| `tests/Unit/Service/Execution/*.php` | Builder tests |

### Modified files

| File | Change |
|------|--------|
| `src/Command/RenameByExifDateCommand.php` | Use `ExecutionPlanBuilder` instead of `AssetGroupAdapter` |
| `src/Service/FileSystemService.php` | Add `executePlan()` for `ExecutionPlan` and extract shared runtime helpers |
| `src/Service/FileSystemServiceInterface.php` | Add new method |
| `src/Service/RenameOutputRenderer.php` | Render `ExecutionPlan` directly, with summary values from `RenameResult` |
| `src/Service/AssetGroupAdapter.php` | Deprecate in Phase 4A, remove in 4B if no longer needed |
| `tests/Integration/*` | Add execution/output parity tests |

---

## Runtime model contract

## `ExecutionItem`

Each `ExecutionItem` should contain only runtime-relevant data.

### Required fields

- `sourcePath`
- `targetPath`
- `type` (`ExecutionItemType`)
- `renameRequired`
- `isNoOp`
- `groupKey`
- `clusterId`
- `isDuplicateTarget`
- `isLivePhotoConflict`
- `isFallbackDate`
- `isAmbiguousTimezone`

### Optional / render-only fields

- `relativeSourcePath`
- `relativeTargetPath`
- `warningReason`
- `decisionLog`
- `displayOrder`

### Explicit decision: use strings, not SplFileInfo

Use plain strings for runtime DTOs.

Reason:
- Symfony Filesystem rename/mkdir APIs operate on strings
- runtime DTOs should stay lightweight
- there is no strong need to preserve `SplFileInfo` in the execution/output layer

#### Renderer consequence

Because some current output/link helpers may still assume `SplFileInfo`, Task 4A.4 must explicitly:
- move clickable-link rendering to string-based helpers, or
- add string-based path projection logic in the renderer

The renderer must not force `ExecutionItem` back into `SplFileInfo`.

### `ExecutionItemType::Skipped`

`ExecutionItemType::Skipped` is reserved for runtime-projected, non-executable entries inside an already projected runtime group.

It is **not** used to duplicate normal analysis-time skipped files already represented in `RenameResult` / `SkippedFile`.

Normal analysis skips remain outside `ExecutionPlan` and are rendered from `RenameResult`.

## `ExecutionGroup`

Suggested fields:

- `groupKey`
- `isLivePhotoGroup`
- `canonicalSourcePath`
- `items` (`list<ExecutionItem>`)
- `decisionLog`
- `displayOrder`

### `isLivePhotoGroup` rule

`isLivePhotoGroup` is derived from runtime group composition:
- true when the runtime group contains at least one `Companion` item
- false otherwise

It must **not** be derived from legacy duplicate identifier prefixes.

## `ExecutionPlan`

Suggested fields:

- `groups` (`list<ExecutionGroup>`)

### Explicit decision: no scan/analysis summary copy on ExecutionPlan

Do **not** duplicate these `PipelineContext` fields onto `ExecutionPlan`:
- `scannedFiles`
- `namingCollisions`
- `skippedFiles`
- `fallbackDateFiles`
- `ambiguousTimezoneFiles`
- `livePhotoConflictFiles`

Those remain owned by `PipelineContext` / `RenameResult`.

`ExecutionPlanBuilder::build()` may take `PipelineContext` as input for projection purposes, but it must not re-copy the summary state into the runtime DTO unless a concrete need is proven later.

---

## Dekomposition requirements

Phase 4 must not create a new monolith in `ExecutionPlanBuilder`, `FileSystemService`, or the renderer.

## `ExecutionPlanBuilder` must be projection-only

`ExecutionPlanBuilder` projects already-made decisions from the asset-group pipeline into the runtime DTO model.

It must **not** introduce new business classification, naming, grouping, or conflict-resolution behavior.

It may:
- map existing roles
- map existing flags
- derive runtime/output representations from already known state
- derive display/runtime grouping helpers such as `isLivePhotoGroup`

It must **not**:
- re-run companion detection
- re-run duplicate detection
- make new canonical choices
- resolve new collisions
- invent new grouping semantics

## `ExecutionPlanBuilder` must be internally decomposed

Even if only one public service is exposed, the implementation must be split into clearly separated responsibilities.

Minimum expected internal decomposition:

- `projectGroup()` — group-level projection
- `projectItem()` — item-level projection
- `orderGroups()` — stable group ordering
- `orderItems()` — canonical/companion/duplicate/ambiguous order
- `projectRuntimeFlags()` — transfer quality/runtime flags from context
- `projectDecisionLog()` — group decision-log carry-over
- `validateProjectionPreconditions()` — guard partial/inconsistent runtime state

If complexity still grows, extract helpers such as:
- `ExecutionGroupProjector`
- `ExecutionItemProjector`
- `ExecutionOrderer`

## FileSystemService decomposition rule

`renameFiles()` and `executePlan()` will temporarily coexist.
They must not become copy-pasted twins.

Shared runtime logic must be extracted into private helpers, such as:

- occupied-path index building
- runtime move execution
- duplicate suffix fallback
- dry-run branching
- counter accumulation
- summary handoff

### Forbidden
- duplicating large execution loops in two methods
- maintaining two independent runtime collision fallback implementations

## Renderer decomposition rule

`RenameOutputRenderer` must not become a dual-path monolith.

Preferred approach:
- extend the existing `RenameOutputRenderer`
- add a dedicated runtime-plan projection path
- reuse shared formatting helpers where possible

Only introduce a second top-level renderer if the existing renderer cannot be kept coherent without major duplication.

Allowed:
- shared helper methods for alignment, diff highlighting, summary formatting
- separate projection/build methods for legacy and new runtime input

Forbidden:
- one giant method with large `if old-path / else new-path` branches

---

## Ordering rules

These rules must be explicit and tested.

### Group order
Stable order identical to the current visible semantics:
- preserve the effective order already produced by the asset-group pipeline
- if reordering is required, use stable canonical source path ordering

### Item order within a group
Always:

1. Canonical
2. Companion(s)
3. Duplicate(s)
4. Ambiguous item(s)
5. runtime-only skipped/non-executable entries if any

### Execution order
Execution order must remain stable and deterministic.
Do not infer move order from hash maps or unordered iterables.

---

## Failure semantics

Phase 4 must specify what happens when the runtime projection is inconsistent.

### ExecutionPlanBuilder failures

#### Per-item projection failure
If one item cannot be projected:
- do **not** silently drop it
- convert it into an explicit non-executable entry if possible, or
- fail the whole group projection with a marked runtime error

#### Per-group projection failure
If a group cannot be projected consistently:
- fail the command before execution
- report which group failed and why
- do not produce a partial runtime plan for that group

#### Partial clusterId state
If a group reaches Phase 4 with inconsistent `clusterId` population:
- treat this as a precondition failure
- abort execution before runtime plan build
- error message must identify the affected group

No mixed runtime plans with partially projected cluster state are allowed.

### File execution failures
Execution remains fail-fast unless the existing command semantics already permit partial completion.
Do not change current failure mode silently.

---

## Runtime collision safety

Phase 4 must preserve the current "last line of defense" behavior.

Today, `CollisionResolver` plans unique targets, and `FileSystemService` also keeps a runtime occupied-path guard.
This second guard stays.

### Required behavior

- `executePlan()` builds an occupied-path index from the runtime plan
- if a target path is already occupied at runtime by another planned item, use the same fallback strategy as today
- if no free suffix can be found within `MAX_DUPLICATE_SUFFIX`, abort with error
- self-reference is never treated as collision
- case-only rename behavior must remain safe on case-insensitive file systems

### Explicit rule

- `CollisionResolver` remains **planning logic**
- `FileSystemService::executePlan()` remains **runtime safety logic only**
- runtime fallback must **not** introduce new naming policy or business rules
- runtime fallback may only protect against collisions that survive into execution time

Phase 4 does **not** remove runtime collision fallback, even if planning already resolved collisions.

---

## Output migration rules

Phase 4 must move rendering to the new runtime model without changing visible semantics.

## Renderer responsibilities

The renderer must:
- build output entries from `ExecutionPlan`
- assign tags from runtime fields + quality flags
- render decision logs
- compute execution/output counters from `ExecutionPlan`
- use `RenameResult` / `PipelineContext.toRenameResult()` for scan/analysis summary values
- count Live Photo groups without relying on legacy `LIVE_PHOTO_IDENTIFIER_PREFIX` hacks
- preserve diff highlighting behavior
- build clickable paths from strings, not from forced `SplFileInfo` back-conversions

## Tag mapping rules

Suggested mapping source:

- `[C]` from `isLivePhotoConflict`
- `[W]` from `isAmbiguousTimezone && !isNoOp`
- `[F]` from `isFallbackDate && !isNoOp`
- `[D]` from `isDuplicateTarget && !isNoOp`
- `[O]` from canonical/no-op/original semantics
- `[R]` default executable rename
- `[S]` runtime-projected skipped entries from `ExecutionPlan`, plus analysis skips from `RenameResult`
- `[E]` runtime projection or read error entry

These mappings must be parity-tested against existing `rename:exif` output.

---

## Summary parity requirements

The summary must stay semantically identical.

Phase 4 tests must assert parity for:
- scanned files
- skipped (no metadata)
- skipped (read errors)
- planned moves
- planned skips
- live photo groups
- duplicates found
- naming collisions
- files processed / files to process

If a summary field changes only because the model changed, that is still a regression unless explicitly accepted.

### Summary ownership rule

- `PipelineContext.toRenameResult()` remains authoritative for scan/analysis-derived values
- `ExecutionPlan` is authoritative for execution/output-derived values
- `RenameOutputRenderer` formats counts, but must not become a hidden source of truth

---

## Command wiring changes

## `RenameByExifDateCommand`

Replace this path:

```text
AssetGroupPipeline::run()
-> AssetGroupAdapter::toFileDuplicateCollection()
-> FileSystemService::renameFiles()
```

with:

```text
AssetGroupPipeline::run()
-> ExecutionPlanBuilder::build()
-> PipelineContext.toRenameResult()
-> RenameOutputRenderer renders from ExecutionPlan + RenameResult
-> FileSystemService::executePlan()
```

### Dependency simplification
Add:
- `ExecutionPlanBuilderInterface $executionPlanBuilder`

Remove after migration:
- `AssetGroupAdapter`

### Explicit decision: no ExecutionPlanValidator

Do **not** introduce `ExecutionPlanValidator` unless a concrete, non-overlapping need appears.

Reason:
- `RenamePlanValidator` already owns planning conflict checks
- `FileSystemService` already owns runtime safety
- an extra runtime validator is currently YAGNI

The plan should stay simpler until a real gap appears.

---

## Migration strategy

This work is split into Phase 4A and 4B so the architecture can be completed intentionally rather than leaving "future work" open-ended.

---

# Phase 4A — Execution/Output Migration for rename:exif

### Parallel-path rule

During Phase 4A there may be a temporary parity harness, but there must be **exactly one production execution path** for `rename:exif` at any given commit after the command switch.

Allowed:
- old adapter path for differential tests / temporary parity harness
- old legacy execution path for non-migrated commands

Forbidden:
- long-lived dual production paths for `rename:exif`
- hidden fallback from new execution path back to adapter path

## Task 4A.1 — Introduce runtime model only
Create:
- `ExecutionPlan`
- `ExecutionGroup`
- `ExecutionItem`
- `ExecutionItemType`

Add unit tests only.
No behavior changes yet.

## Task 4A.2 — Build runtime plan from AssetGroupCollection
Create `ExecutionPlanBuilder`.

Input:
- `AssetGroupCollection`
- `PipelineContext`
- source base directory

Output:
- `ExecutionPlan`

Tests must cover:
- canonical / companion / duplicate / ambiguous mapping
- quality flag mapping
- decision log carry-over
- Live Photo group detection
- stable ordering
- no-op detection

## Task 4A.3a — Refactor existing `renameFiles()` into shared runtime helpers
Before writing `executePlan()`, extract private helpers from the current `renameFiles()` path.

Goal:
- isolate occupied-path tracking
- isolate runtime collision fallback
- isolate actual move execution
- isolate dry-run branching
- isolate counter accumulation and summary handoff

This extraction must happen **before** adding `executePlan()` so helpers are extracted once from the existing method, not reverse-engineered from two diverging methods.

Tests must keep current `renameFiles()` behavior fully green.

## Task 4A.3b — Add `FileSystemService::executePlan()`
Add a new method alongside existing `renameFiles()` using the shared private helpers introduced in 4A.3a.

Do **not** remove the old method yet.

Tests must cover:
- normal move execution
- dry-run
- runtime occupied-path fallback
- duplicate suffix fallback
- summary counter integration
- no-op handling

## Task 4A.4 — Teach renderer to render `ExecutionPlan`
Extend or refactor `RenameOutputRenderer` so it can render from runtime plan directly.

Do **not** duplicate large blocks of output logic.
Extract shared helpers if needed.

Task must explicitly migrate clickable-link generation to string-based path rendering.

Renderer inputs for `rename:exif` become:
- `ExecutionPlan` for runtime/output entries
- `RenameResult` for scan/analysis summary data

Tests must cover:
- same tags as before
- same summary counts
- decision log rendering
- LP group counting
- dry-run parity

## Task 4A.5 — Switch `RenameByExifDateCommand` to new runtime path
Use:
- `AssetGroupPipeline`
- `ExecutionPlanBuilder`
- `PipelineContext.toRenameResult()`
- `FileSystemService::executePlan()`

Keep old path untouched for other commands.

## Task 4A.6 — Differential parity tests for execution/output
Add branch-specific tests comparing:
- old adapter path output
- new execution-plan path output

### Must match
- visible output entries
- tags
- summary counts
- LP group counts
- runtime collision results
- dry-run behavior

### Acceptable differences
None, unless explicitly documented.

## Task 4A.7 — Remove `AssetGroupAdapter` from `rename:exif`
Once the new path is stable:
- delete command dependency
- deprecate adapter
- keep only if still needed by a temporary parity test harness

## Task 4A.8 — Cleanup
After parity tests pass:
- remove unused imports and service registrations
- document `AssetGroupAdapter` removal from `rename:exif`
- keep old `FileDuplicateCollection` path only for non-migrated commands

---

# Phase 4B — Legacy Execution Finalization

> Goal: finish the migration story instead of leaving legacy execution DTOs as indefinite "future work".

Phase 4B starts immediately after 4A is stable.

## Explicit rule for 4B scope

It is explicitly allowed that Task 4B.1 concludes:

- no further command migration is worthwhile right now
- some commands keep the legacy path with documented rationale
- legacy DTO removal is partial, not total, until those commands are intentionally revisited

Phase 4B is therefore:
- an **audit + decision phase**
- followed by migration/removal **only where justified**

It is **not** required to force all commands onto `ExecutionPlan` if that brings no architectural or maintenance benefit.

## Decisions required at the start of 4B

For each remaining command, decide explicitly:
- migrate to `ExecutionPlan`
- keep legacy path temporarily with rationale
- or redesign/remove the command-specific dependency another way

No command should remain on the legacy runtime path "just because".

## Task 4B.1 — Audit remaining `FileDuplicateCollection` consumers
Identify all remaining commands/services using:
- `FileDuplicateCollection`
- `FileDuplicate`
- `RenameList`
- legacy renderer assumptions
- legacy file execution assumptions

Produce a table:
- command/service
- why still needed
- migrate now / defer with reason / keep legacy with reason / delete

## Task 4B.2 — Migrate remaining compatible commands (if any)
Only migrate commands where the audit shows a clear benefit.

Examples:
- commands whose runtime execution can naturally project to `ExecutionPlan`
- commands where retaining legacy DTOs adds avoidable maintenance cost

It is valid for this task to be empty if 4B.1 finds no worthwhile migration candidates.

For each selected command:
- project into `ExecutionPlan`
- reuse `FileSystemService::executePlan()`
- reuse runtime renderer path
- preserve existing behavior

## Task 4B.3 — Consolidate FileSystemService
After enough commands are migrated:
- make `executePlan()` the preferred runtime path
- keep `renameFiles()` only while real consumers still exist
- avoid maintaining two first-class execution architectures longer than necessary

## Task 4B.4 — Consolidate RenameOutputRenderer
After enough commands are migrated:
- make the runtime-plan rendering path preferred
- keep legacy rendering only while real consumers still exist
- remove duplicated legacy-only assumptions when no longer needed

## Task 4B.5 — Deprecate legacy execution DTOs where possible
If no active production command still requires them, deprecate and then remove:
- `FileDuplicate`
- `FileDuplicateCollection`
- `RenameList`
- any adapter-only helper structures

If some commands still legitimately require them, document that explicitly and defer removal without ambiguity.

## Task 4B.6 — Remove old execution path where possible
If all active commands have been migrated or consciously redesigned:
- remove old `renameFiles()` DTO dependency path
- remove stale service registrations
- update docs and architecture description

If not all commands are migrated, document the partial end state explicitly rather than pretending full removal happened.

---

## Explicit anti-patterns for Phase 4

These are forbidden.

- no new hidden adapter inside `FileSystemService`
- no "convert `ExecutionPlan` back to `FileDuplicateCollection` internally"
- no stubbed runtime path
- no duplicate rendering logic for old/new path with large copy-paste blocks
- no partial `ExecutionPlan` emission for invalid groups
- no unordered iteration affecting output or move order
- no indefinite coexistence of two runtime architectures without explicit per-command rationale

---

## Test plan

## Unit tests
Add focused tests for:
- `ExecutionPlanBuilder`
- runtime item ordering
- output tag projection
- runtime occupied-path fallback
- shared execution helper behavior

## Integration tests
Add / update tests for:
- `RenameByExifDateCommand` on `feature/asset-group-pipeline`
- dry-run parity
- real execution parity
- Live Photo output
- cross-directory duplicate behavior
- idempotent second run
- circular swap abort
- case-conflict behavior

## Differential tests
Maintain a temporary parity harness:

```text
AssetGroupCollection
-> old: AssetGroupAdapter -> FileDuplicateCollection -> renameFiles()
-> new: ExecutionPlanBuilder -> executePlan()
```

Compare:
- output entries
- tags
- counts
- final resulting filenames in workspace tests

Remove this harness only after Phase 4A is validated.

## Phase 4B tests
Add migration tests for any remaining commands moved to the new runtime path.
Legacy DTO removal must be blocked until these pass.

---

## Detailed task breakdown

### Task 4A.1 — Runtime model
- [x] Create `ExecutionItemType`
- [x] Create `ExecutionItem`
- [x] Create `ExecutionGroup`
- [x] Create `ExecutionPlan`
- [x] Add unit tests
- [x] Run `make test`
- [x] Commit: `feat: add execution runtime model for asset-group pipeline`

### Task 4A.2 — ExecutionPlanBuilder
- [x] Create interface + implementation
- [x] Map `AssetGroupCollection` + `PipelineContext` to `ExecutionPlan`
- [x] Define stable group/item order
- [x] Add unit tests for mapping + edge cases
- [x] Enforce projection-only rule
- [x] Enforce internal decomposition rules
- [x] Run `make test`
- [x] Commit: `feat: add ExecutionPlanBuilder for asset-group runtime projection`

### Task 4A.3a — Extract shared helpers from renameFiles()
- [x] Refactor `renameFiles()` into reusable private runtime helpers
- [x] Keep behavior identical
- [x] Add/adjust unit tests
- [x] Run `make test`
- [x] Commit: `refactor: extract shared runtime helpers from renameFiles`

### Task 4A.3b — Add executePlan()
- [x] Add `executePlan()` to interface + implementation
- [x] Reuse shared runtime helpers
- [x] Preserve dry-run behavior
- [x] Add unit tests
- [x] Run `make test`
- [x] Commit: `feat: add execution-plan based file execution path`

### Task 4A.4 — Renderer migration
- [x] Refactor renderer to accept `ExecutionPlan`
- [x] Preserve tag semantics and summary semantics
- [x] Add decision log rendering from runtime groups
- [x] Migrate clickable-link/path rendering to string-based helpers
- [x] Prefer extending existing renderer over adding a second top-level renderer
- [x] Avoid dual-path monolith logic
- [x] Add parity tests
- [x] Run `make test`
- [x] Commit: `refactor: render rename output from ExecutionPlan`

### Task 4A.5 — Command migration
- [x] Update `RenameByExifDateCommand`
- [x] Remove adapter usage from command
- [x] Pass `RenameResult` separately for summary data
- [x] Keep old command paths untouched
- [x] Ensure only one production execution path remains for `rename:exif`
- [x] Add integration coverage
- [x] Run `make test`
- [x] Commit: `feat: switch rename:exif execution to ExecutionPlan`

### Task 4A.6 — Differential parity harness
- [x] Add temporary comparison tests old vs new execution path
- [x] Verify no visible output regressions
- [x] Verify workspace end-state parity
- [x] Run `make test`
- [x] Commit: `test: add execution-path differential parity coverage`

### Task 4A.7 — Adapter removal / cleanup
- [x] Remove `AssetGroupAdapter` from `rename:exif`
- [x] Deprecate adapter implementation
- [x] Clean DI registrations
- [x] Update docs
- [x] Run `make test`
- [x] Commit: `refactor: remove transitional asset group adapter from rename:exif`

### Task 4B.1 — Legacy consumer audit
- [x] Inventory remaining legacy execution DTO consumers
- [x] Decide migrate/defer/keep/remove per consumer
- [x] Add migration table to docs
- [x] Run `make test`
- [x] Commit: `docs: audit remaining legacy execution path consumers`

**Audit result (2026-03-31):**

| Command | Pipeline | Legacy DTOs used | Decision | Rationale |
|---------|----------|-----------------|----------|-----------|
| `rename:exif` | AssetGroup + ExecutionPlan | none | **migrated** | Fully on new pipeline since Phase 3+4A |
| `rename:hash` | AbstractRenameCommand | `FileDuplicateCollection`, `FileDuplicate`, `renameFiles()` | **keep legacy** | Simple single-concern command; no benefit from ExecutionPlan |
| `rename:pattern` | AbstractRenameCommand | `FileDuplicateCollection`, `FileDuplicate`, `renameFiles()` | **keep legacy** | Simple single-concern command; no benefit from ExecutionPlan |
| `rename:date-pattern` | AbstractRenameCommand | `FileDuplicateCollection`, `FileDuplicate`, `renameFiles()` | **keep legacy** | Simple single-concern command; no benefit from ExecutionPlan |
| `rename:lower` | AbstractRenameCommand | `FileDuplicateCollection`, `FileDuplicate`, `renameFiles()` | **keep legacy** | Simple single-concern command; no benefit from ExecutionPlan |
| `rename:verify` | Own pipeline (read-only) | none | **no legacy consumer** | Uses shared infra (ExifMetadataProvider, FileHelper, MediaTypeClassifier) but not the rename execution pipeline |
| `rename:write-date` | Own pipeline (exiftool) | none | **no legacy consumer** | Uses shared infra (ExifMetadataProvider, ExiftoolWriter) but not the rename execution pipeline |
| `rename:dedup` | Own pipeline (filename-based) | none | **no legacy consumer** | Standalone dedup logic, no DuplicateDetectionService dependency |

**Conclusion:** End State B. No migration candidates found. Four simple commands (`rename:hash`, `rename:pattern`, `rename:date-pattern`, `rename:lower`) retain the legacy path via `AbstractRenameCommand`. Three commands (`rename:verify`, `rename:write-date`, `rename:dedup`) have their own pipelines and never used legacy DTOs. Legacy path is intentionally retained with documented rationale. See `DuplicateDetectionService` MIGRATION STATUS block and `AGENTS.md` Legacy Execution Path section.

### Task 4B.2 — Migrate remaining compatible commands (if any)
**Skipped — no migration candidates.** Audit (4B.1) found no commands where migration to `ExecutionPlan` provides architectural or maintenance benefit. All remaining legacy consumers are simple commands consciously retained on the legacy path.

### Task 4B.3 — Consolidate runtime services
**Not applicable.** `executePlan()` is already the preferred path for `rename:exif`. `renameFiles()` is retained as the sole execution path for the four simple legacy commands. No further consolidation is warranted given End State B.

### Task 4B.4 — Deprecate/remove legacy DTOs where possible
**Not applicable.** `FileDuplicate`, `FileDuplicateCollection`, and `RenameList` are still active production dependencies for `rename:hash`, `rename:pattern`, `rename:date-pattern`, and `rename:lower`. Removal is deferred without ambiguity until those commands are intentionally revisited.

---

## Acceptance criteria

### Phase 4A is done when all of the following are true

- `rename:exif` no longer uses `AssetGroupAdapter`
- `rename:exif` no longer requires `FileDuplicateCollection` for execution/output
- `RenameByExifDateCommand` imports and depends on no legacy adapter-only execution types
- `FileSystemService` executes a dedicated runtime plan
- `RenameOutputRenderer` renders from the runtime plan
- scan/analysis summary values still come from `RenameResult`, not duplicated runtime DTO state
- output semantics are unchanged
- runtime collision fallback remains intact
- cross-directory idempotency remains intact
- Live Photo atomicity remains intact
- all tests pass via `make test`

### Phase 4B is done when all of the following are true

- every remaining legacy execution DTO consumer has an explicit migrate/defer/keep/remove decision
- the project no longer keeps two first-class runtime architectures without explicit justification
- any migrated remaining commands use `ExecutionPlan`
- legacy execution DTOs are deprecated or removed where no longer needed
- docs describe one preferred runtime execution architecture and any consciously retained legacy exceptions
- all tests pass via `make test`

### Acceptable closed end states for the whole topic

#### End state A — full runtime consolidation
- all production commands use the preferred runtime path
- legacy execution DTOs are removed

#### End state B — intentional bounded legacy exceptions
- `rename:exif` and any migrated commands use the preferred runtime path
- a limited set of commands consciously retain the legacy DTO path
- each retained exception is documented with rationale and status
- there is no accidental transitional architecture left

---

## End state

This topic is considered truly closed only when:

- `rename:exif` is fully runtime-plan based
- the old adapter bridge is gone
- renderer and executor use the runtime model directly
- remaining legacy execution DTOs are either removed or consciously retained with documented rationale
- the project no longer carries an accidental transitional architecture
