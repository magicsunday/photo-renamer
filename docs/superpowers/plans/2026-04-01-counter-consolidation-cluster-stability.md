# Counter-Consolidation + Cluster-Stability + Companion-Selection Plan (v3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate counter-drift between Renderer and Executor, harden cluster/subgroup stability for exotic edge cases, and fix multi-companion selection bug.

**Branch:** `feature/asset-group-pipeline`

---

## Overarching rule

**Existing semantically correct names are strong stability signals.**

This applies everywhere:
- Degraded subgroup preservation (Task 4)
- Companion basename preference (Task 6)
- Intra-cluster ordering (already implemented via clusterRank sort)

The pipeline should never silently override a name that already matches the expected output pattern.

---

## Already completed (outside this plan)

- [x] CollisionResolver reclaimable No-Op fix (commit 399b07e)

---

## Task 1: Separate counter ownership — ExecutionPreview + ExecutionResult

### Problem

Renderer and Executor maintain separate counters independently. They can drift when runtime collision fallback changes targets or exception handling skips items. A shared mutable counter object would create hidden coupling and mix planned vs executed semantics.

### Solution

Two immutable result objects with clear ownership:

**`ExecutionPreview`** — produced by Renderer, **source of truth for plan-time counts**:
- `plannedMoves: int` (replaces the misleading `fileCount` counter)
- `plannedSkips: int`
- `duplicateCount: int`

**`ExecutionResult`** — produced by Executor, **source of truth for runtime-only deltas/events**:
- `executedMoves: int`
- `runtimeFallbacks: int`
- `runtimeErrors: int`

No artificial symmetry — Preview covers what was planned/displayed, Result covers what actually happened at runtime. The Command currently only uses Renderer counters for the summary; `executePlan()` return value is unused. This change makes that implicit truth explicit.

Summary renders from three sources:
- `RenameResult` for scan/analysis values (scanned files, skipped, etc.)
- `ExecutionPreview` for plan/dry-run values
- `ExecutionResult` for actual execution values (all zeros in dry-run mode)

### Files

- Create: `src/Model/Execution/ExecutionPreview.php`
- Create: `src/Model/Execution/ExecutionResult.php`
- Modify: `src/Service/RenameOutputRenderer.php` — `renderPlanEntries()` returns `ExecutionPreview`
- Modify: `src/Service/FileSystemService.php` — `executePlan()` returns `ExecutionResult`
- Modify: `src/Service/FileSystemServiceInterface.php`
- Modify: `src/Command/RenameByExifDateCommand.php` — pass both to summary
- Modify: `src/Service/RenameOutputRenderer.php` — `renderPlanSummary()` accepts both
- Create: `tests/Unit/Model/Execution/ExecutionPreviewTest.php`
- Create: `tests/Unit/Model/Execution/ExecutionResultTest.php`
- Update: existing Renderer + Executor tests

### Steps

- [x] Create `ExecutionPreview` (final readonly class)
- [x] Create `ExecutionResult` (final readonly class)
- [x] Update `renderPlanEntries()` to return `ExecutionPreview` instead of `array`
- [x] Update `executePlan()` to return `ExecutionResult` instead of `array`
- [x] Update `renderPlanSummary()` to accept `ExecutionPreview` + `ExecutionResult`
- [x] Update command to pass both to summary
- [x] Update all tests
- [x] Run `make test`
- [x] Commit: `refactor: separate counter ownership into ExecutionPreview and ExecutionResult`

---

## Task 2: Companion cross-directory shortcut — test only

### Problem

Companions in subdirectories could theoretically enter the cross-directory shortcut and lose their subgroup suffix. Code review shows Companions already `continue` at line 327 before the `isCrossDirNoConflict` check at line 353.

### Solution

Test proving the code is already correct.

### Steps

- [x] Write test `companionInSubdirectoryKeepsSubgroupSuffix`
- [x] Verify test passes without code changes
- [x] Commit: `test: verify companions never enter cross-directory shortcut`

---

## Task 3: Document cluster renumbering behavior — test only

### Problem

When `MERGE_THRESHOLD` changes, new clusters appear, shifting subgroup numbers.

### Solution

Accepted behavior. The test documents it as deterministic and intentional, without over-specifying the sort order (alphabetical is the current implementation, but the important property is "stable and documented").

Test at the level where the numbering actually originates — SubgroupClassifier or TargetNameResolver's `buildSubgroupMap()`, depending on which is the actual ordering boundary.

### Steps

- [x] Write test `clusterRenumberingIsDeterministicWhenNewClusterAppears`
  - Test that numbering is deterministic
  - Test that adding a new cluster shifts existing numbers
  - Document this as accepted behavior
  - Do NOT hard-assert alphabetical — assert deterministic + documented
- [x] Commit: `test: document cluster renumbering as deterministic accepted behavior`

---

## Task 4: Degraded classification recovery — strict conditions only

### Problem

When classification is degraded (Hash-Fehler) on one run but succeeds on the next, files get completely different names.

### Solution

When classification is degraded AND existing filenames already match the subgroup pattern, preserve them — but only under strict conditions.

### Entry point

In `TargetNameResolver::resolveGroup()`, **before** the `hasMultipleSubgroups()` check. When degraded, `hasMultipleSubgroups()` returns false (all clusterIds null) and the group falls through to flat naming. The recovery must intercept before that.

### Conscious exception to "filename must not influence pipeline"

Normally the current filename must never drive pipeline decisions. This is a **deliberate, narrowly bounded degraded-mode exception**: when classification has failed, the existing filenames are the only signal available to avoid unnecessary churn. The 5 strict conditions exist precisely to limit this exception to cases where the names demonstrably come from a prior *successful* run.

### Strict conditions (ALL must be true)

1. Group `isClassificationDegraded()` is true
2. At least one non-Canonical item basename matches `groupKey-NNN` pattern
3. No two items claim the same clean subgroup basename (no conflicts)
4. Existing duplicate numbering within subgroups is consistent (no gaps, no duplicates)
5. No item has a clusterId set (truly degraded, not partial)

If ANY condition fails → fall through to normal flat duplicate naming. Do not attempt to recover inconsistent state.

### Important

This is a **degraded-mode stability rule**, not new classification. It is narrow idempotency protection, not inference.

### Steps

- [x] Write failing test `degradedGroupWithExistingSubgroupNamesPreservesNames`
- [x] Write test `degradedGroupWithConflictingSubgroupNamesFallsThrough`
- [x] Write test `degradedGroupWithPartialClusterIdsFallsThrough`
- [x] Implement `hasExistingSubgroupPattern()` with all 5 strict conditions
- [x] Implement `preserveExistingSubgroupNames()` — assigns current name as proposedName for matching items, flat duplicate for non-matching
- [x] Run `make test`
- [x] Commit: `fix: degraded classification preserves existing subgroup names under strict conditions`

---

## Task 5: CompanionDetector — select only one Companion per media type

### Problem

When multiple files of the same media type share the same Content-Identifier (e.g. two MOVs paired to the same HEIC), CompanionDetector marks ALL as Companion. Only ONE should be Companion — the rest are duplicates.

### Solution

Collect candidates, select the best one via a stable preference chain:

1. **Basename matches canonical** — idempotent, file already has the correct companion name
2. **Existing clean companion name** — file already named as a companion from a prior run
3. **Stable tie-breaker** — clusterRank if available, otherwise shortest pathname (deterministic, rename-independent)

NOT "first found" — that depends on iteration order which may change.

### API unchanged (Option A)

`detect()` signature stays `array<string, true>`. The selection happens **inside** CompanionDetector — it collects all candidates per media type, applies the preference chain, and returns only the winner. The losing candidates are simply not returned. They don't become duplicates *through* the detector; they retain whatever role the normal assignment process gives them (typically Duplicate).

### Steps

- [x] Write failing test `onlyOneCompanionPerMediaTypeWhenMultipleShareContentId`
- [x] Write test `companionWithMatchingBasenamePreferedOverOther`
- [x] Write test `companionFallbackUsesStableTieBreaker` (not first-found)
- [x] Refactor CompanionDetector Phase 1: collect candidates → select best via preference chain
- [x] Run `make test`
- [x] Verify with real data: `./renamer.sh rename:exif --dry-run /volume1/Fotos/MobileBackup/Test/ --list-all`
- [x] Commit: `fix: CompanionDetector selects one companion per media type with stable preference`

---

## Success Criteria

- [x] Renderer produces `ExecutionPreview`, Executor produces `ExecutionResult` — clear ownership
- [x] Summary renders from three distinct sources (RenameResult + Preview + Result)
- [x] Companions in subdirectories never lose subgroup suffix (proven by test)
- [x] Degraded groups with existing subgroup names remain stable only under strict conditions
- [x] Degraded groups with inconsistent names fall through to safe flat naming
- [x] Cluster renumbering is documented as deterministic accepted behavior
- [x] CompanionDetector selects exactly one companion per media type with stable preference
- [x] Live Photo groups with duplicate companions are fully idempotent
- [x] All existing tests remain green
- [x] `make test` passes
