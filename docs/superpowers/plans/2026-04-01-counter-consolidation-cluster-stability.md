# Counter-Consolidation + Cluster-Stability + Companion-Selection Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate counter-drift between Renderer and Executor (#2), harden cluster/subgroup stability for exotic edge cases (#9), and fix multi-companion selection bug.

**Branch:** `feature/asset-group-pipeline` (continues existing work)

---

## Already completed (outside this plan)

- [x] **CollisionResolver reclaimable No-Op fix** — Only source paths of items that are actually being renamed (source !== target) are treated as reclaimable. No-op items keep their path occupied. (commit 399b07e)

---

## Problem 1: Counter-Drift (#2)

### Current State

Renderer (`RenameOutputRenderer::renderPlanEntries`) and Executor (`FileSystemService::executePlan`) each iterate the `ExecutionPlan` independently and maintain separate counters. Both read the same plan, but their counting logic can diverge when runtime collision fallback changes a target or exception handling skips an item.

### Solution

Introduce `ExecutionCounters` — a single mutable counter object that both Renderer and Executor populate.

---

## Problem 2: Cluster-Stability (#9)

### Current State

Three exotic edge cases can break subgroup idempotency:

**Case A — Degraded Classification Recovery:** Lauf 1: Hash-Fehler → degraded → keine clusterIds → flat duplicate naming. Lauf 2: Classification succeeds → clusterIds → subgroup naming. Result: completely different names.

**Case B — Cross-directory Companions in Subgroups:** Companion MOV in a subdirectory of a non-canonical subgroup. Already handled correctly in the code — Companions `continue` before the `isCrossDirNoConflict` check. Needs only a test to prove it.

**Case C — Cluster renumbering after threshold change:** If `MERGE_THRESHOLD` changes between runs, new clusters appear, shifting subgroup numbers. Accepted behavior — alphabetical sort is deterministic. Needs documentation + test.

---

## Problem 3: Multi-Companion Selection Bug

### Current State

When multiple files of the same media type share the same Content-Identifier (e.g. two MOVs both paired to the same HEIC), the `CompanionDetector` marks ALL of them as Companion. But only ONE should be the Companion — the others are duplicates of the Companion.

Example:
```
...-272.heic  (Canonical)
...-272.mov   (Companion — primary LP video)
...-duplicate-001.jpg  (Duplicate of the still)
...-duplicate-001.mov  (Duplicate of the LP video — NOT a second Companion)
```

Currently: both MOVs get Companion role → TargetNameResolver gives both `...-272.mov` → collision.

### Solution

CompanionDetector must select only ONE Companion per media type. Preference: the one whose basename already matches the canonical (idempotent). Fallback: first found.

---

## Task Breakdown

### Task 1: ExecutionCounters Value Object

**Files:**
- Create: `src/Model/Execution/ExecutionCounters.php`
- Create: `tests/Unit/Model/Execution/ExecutionCountersTest.php`

- [ ] **Step 1: Write failing tests** (countersStartAtZero, incrementMethodsWork, toArrayReturnsAllCounters)

- [ ] **Step 2: Implement ExecutionCounters**

```php
final class ExecutionCounters
{
    public int $fileCount = 0;
    public int $duplicateCount = 0;
    public int $plannedMoves = 0;
    public int $plannedSkips = 0;

    public function incrementFileCount(): void { ++$this->fileCount; }
    public function incrementDuplicateCount(): void { ++$this->duplicateCount; }
    public function incrementPlannedMoves(): void { ++$this->plannedMoves; }
    public function incrementPlannedSkips(): void { ++$this->plannedSkips; }

    /** @return array{fileCount: int, duplicateCount: int, plannedMoves: int, plannedSkips: int} */
    public function toArray(): array { ... }
}
```

- [ ] **Step 3: Run tests, PHPStan, commit**

```
git commit -m "feat: add ExecutionCounters shared counter object"
```

---

### Task 2: Wire ExecutionCounters into Renderer + Executor

**Files:**
- Modify: `src/Service/RenameOutputRenderer.php`
- Modify: `src/Service/FileSystemService.php`
- Modify: `src/Service/FileSystemServiceInterface.php`
- Modify: `src/Command/RenameByExifDateCommand.php`

- [ ] **Step 1: Update renderPlanEntries signature**

Current signature (after Copilot-fix):
```php
public function renderPlanEntries(
    ExecutionPlan $plan,
    RenameOptions $options,
    ?string $sourceBaseDirectory = null,
    ?array $showFilter = null,
    ?RenameResult $result = null,
): array
```

New signature:
```php
public function renderPlanEntries(
    ExecutionPlan $plan,
    RenameOptions $options,
    ?string $sourceBaseDirectory = null,
    ?array $showFilter = null,
    ?RenameResult $result = null,
    ?ExecutionCounters $counters = null,
): void
```

Increment `$counters` instead of maintaining local counter variables. If null, create a local one (backwards compatible).

- [ ] **Step 2: Update executePlan signature**

```php
public function executePlan(
    ExecutionPlan $plan,
    bool $dryRun = false,
    ?ExecutionCounters $counters = null,
): void
```

- [ ] **Step 3: Update command to share one instance**

```php
$counters = new ExecutionCounters();

$this->renameOutputRenderer->renderPlanEntries(
    $executionPlan, $options, $this->sourceDirectory, $this->showFilter, $result, $counters,
);

$this->fileSystemService->executePlan($executionPlan, $this->dryRun, $counters);

$this->renameOutputRenderer->renderPlanSummary(
    $executionPlan, $result, $counters->toArray(), $this->dryRun,
);
```

- [ ] **Step 4: Update tests** (executePlan no longer returns array, renderPlanEntries no longer returns array)

- [ ] **Step 5: Run make test, commit**

```
git commit -m "refactor: unify Renderer and Executor counters via shared ExecutionCounters"
```

---

### Task 3: Companion never enters cross-directory shortcut (Case B) — test only

**Files:**
- Add test: `tests/Unit/Service/Pipeline/TargetNameResolverTest.php`

The code is already correct: Companions `continue` at line 327 before the `isCrossDirNoConflict` check at line 353. This task only adds the test proving it.

- [ ] **Step 1: Write test**

```php
#[Test]
public function companionInSubdirectoryKeepsSubgroupSuffix(): void
{
    // Group: canonical HEIC in /photos (cluster-a),
    //        companion MOV in /photos/subdir (cluster-a),
    //        duplicate JPG in /photos (cluster-b)
    // MOV is alone in its directory but is a Companion
    // Assert: MOV gets subgroup-matching name, NOT the unsuffixed canonical basename
}
```

- [ ] **Step 2: Run tests, commit**

```
git commit -m "test: verify companions never enter cross-directory shortcut"
```

---

### Task 4: Degraded classification recovery respects existing names (Case A)

**Files:**
- Modify: `src/Service/Pipeline/TargetNameResolver.php`
- Add test: `tests/Unit/Service/Pipeline/TargetNameResolverTest.php`

- [ ] **Step 1: Write failing test**

```php
#[Test]
public function degradedGroupWithExistingSubgroupNamesPreservesNames(): void
{
    // Group with degraded classification (no clusterIds)
    // Items: canonical ...-000.jpg, items ...-002.jpg and ...-003.jpg
    // hasMultipleSubgroups returns false (no clusterIds)
    // Without fix: falls through to flat naming → changes -002 to -duplicate-001
    // With fix: preserves existing subgroup names (no-op)
}
```

- [ ] **Step 2: Implement degraded-group name preservation**

In `resolveGroup()`, after `hasMultipleSubgroups()` returns false:

```php
if ($group->isClassificationDegraded() && $this->hasExistingSubgroupPattern($items, $groupKey)) {
    $this->preserveExistingSubgroupNames($group, $items, $groupKey, $canonicalExtension, $useFileExtensionFromSource);
    return;
}
```

`hasExistingSubgroupPattern()` — checks if any non-Canonical item basename matches `groupKey-NNN`.

`preserveExistingSubgroupNames()` — assigns each matching item its current name as proposedName (no-op). Non-matching items get flat duplicate suffixes.

- [ ] **Step 3: Run tests, commit**

```
git commit -m "fix: degraded classification respects existing subgroup names for stability"
```

---

### Task 5: Document cluster renumbering behavior (Case C) — test only

**Files:**
- Add test: `tests/Unit/Service/Pipeline/TargetNameResolverTest.php`

The alphabetical sort in `buildSubgroupMap()` is stable for the common case. When a threshold change causes new clusters to appear, numbers shift. This is accepted behavior — the test documents it.

- [ ] **Step 1: Write test**

```php
#[Test]
public function newClusterBetweenExistingOnesShiftsSubgroupNumbers(): void
{
    // Cluster bases: "aaa", "ccc" → numbers 2, 3
    // Add cluster "bbb" → numbers shift to 2, 3, 4
    // Accepted behavior — deterministic and alphabetically ordered
}
```

- [ ] **Step 2: Run tests, commit**

```
git commit -m "test: document cluster renumbering behavior when new clusters appear"
```

---

### Task 6: CompanionDetector — select only one Companion per media type

**Files:**
- Modify: `src/Service/Pipeline/CompanionDetector.php`
- Add test: `tests/Unit/Service/Pipeline/CompanionDetectorTest.php`

- [ ] **Step 1: Write failing test**

```php
#[Test]
public function onlyOneCompanionPerMediaTypeWhenMultipleShareContentId(): void
{
    // Group: canonical HEIC (content-id=abc),
    //        MOV #1 (content-id=abc),
    //        MOV #2 (content-id=abc)
    // Assert: only ONE MOV is detected as companion, not both
}

#[Test]
public function companionWithMatchingBasenamePreferedOverOther(): void
{
    // Group: canonical HEIC named ...-272.heic (content-id=abc),
    //        MOV #1 named ...-272.mov (content-id=abc),
    //        MOV #2 named ...-duplicate-001.mov (content-id=abc)
    // Assert: MOV #1 (matching basename) is companion, MOV #2 is not
}
```

- [ ] **Step 2: Fix CompanionDetector Phase 1**

In the Content-ID matching loop (current lines 67-83), instead of adding ALL matches to `$companions`, collect candidates and select the best one:

```php
// Phase 1: Content-ID matching — collect candidates, select best one
$contentIdCandidates = [];

foreach ($group->getItems() as $item) {
    if ($item === $canonical) { continue; }

    $itemIsStill = $this->mediaTypeClassifier->isLivePhotoStill($item->file);
    if ($canonicalIsStill === $itemIsStill) { continue; }

    if ($item->contentIdentifier === $canonical->contentIdentifier) {
        $contentIdCandidates[] = $item;
    }
}

if ($contentIdCandidates !== []) {
    // Prefer candidate whose basename matches canonical (idempotent)
    $bestCandidate = $contentIdCandidates[0];

    foreach ($contentIdCandidates as $candidate) {
        if (FileHelper::basenameWithoutExtension($candidate->file) === $canonicalBasename) {
            $bestCandidate = $candidate;
            break;
        }
    }

    $companions[$bestCandidate->file->getPathname()] = true;
}
```

This selects ONE companion per content-ID match. The remaining MOVs stay as Duplicates (assigned by RoleAssigner).

- [ ] **Step 3: Run tests, commit**

```
git commit -m "fix: CompanionDetector selects only one companion per media type"
```

---

## Success Criteria

- [ ] `ExecutionCounters` is the single source of truth for all rename operation counts
- [ ] Renderer and Executor never produce different counts for the same plan
- [ ] Companions in subdirectories never lose their subgroup suffix (proven by test)
- [ ] Degraded groups with existing subgroup-pattern names remain stable (no-op on re-run)
- [ ] Cluster renumbering on threshold change is documented, deterministic, and tested
- [ ] CompanionDetector selects exactly one companion per media type per content-ID
- [ ] Live Photo groups with duplicate companions are fully idempotent
- [ ] All existing tests remain green
- [ ] `make test` passes
