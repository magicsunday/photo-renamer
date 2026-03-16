# Content-aware Duplicate Detection — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Distinguish genuine duplicates (same content) from distinct files (different content) that happen to share a target filename, using lazy content hashing.

**Architecture:** New sub-grouping phase in `DuplicateDetectionService::createDuplicateFilenames()` that hashes files only when a naming conflict exists. Sub-groups get sequential numbers (`-001`, `-002`), genuine duplicates within a sub-group get `-duplicate-NNN`. `SafeHashCalculator` injected as new dependency.

**Tech Stack:** PHP 8.5, PHPUnit 12, PHPStan max level, xxh128 hashing

**Spec:** `docs/specs/2026-03-16-content-aware-duplicates.md`

---

## File Map

| Action | File | Responsibility |
|--------|------|---------------|
| Modify | `src/Service/DuplicateDetectionService.php` | Add hash sub-grouping phase, update constructor |
| Modify | `src/Service/DuplicateDetectionServiceInterface.php` | Add `$skipHashSubGrouping` parameter |
| Modify | `src/Command/RenameByHashCommand.php` | Pass `skipHashSubGrouping: true` |
| Modify | `src/Service/FileSystemService.php` | Add `Naming collisions` to summary metrics |
| Modify | `test/Unit/Service/DuplicateDetectionServiceTest.php` | New tests for hash sub-grouping |
| Modify | `test/Unit/Service/FileSystemServiceTest.php` | Update summary assertions |

---

## Task 1: Inject SafeHashCalculator into DuplicateDetectionService

**Files:**
- Modify: `src/Service/DuplicateDetectionService.php:63`

- [ ] **Step 1: Write test — constructor accepts SafeHashCalculator**

In `test/Unit/Service/DuplicateDetectionServiceTest.php`, update `createService()` helper to pass a `SafeHashCalculator` instance. Verify existing tests still pass.

- [ ] **Step 2: Add constructor parameter**

```php
public function __construct(
    private readonly FileSystemService $fileSystemService,
    private readonly SymfonyStyle $io,
    private readonly SafeHashCalculator $hashCalculator,
) {
}
```

- [ ] **Step 3: Run `make test` — all 136 tests pass**

- [ ] **Step 4: Commit**

```
feat: inject SafeHashCalculator into DuplicateDetectionService
```

---

## Task 2: Add skipHashSubGrouping flag to createDuplicateFilenames

**Files:**
- Modify: `src/Service/DuplicateDetectionService.php:280`
- Modify: `src/Service/DuplicateDetectionServiceInterface.php`
- Modify: `src/Command/RenameByHashCommand.php`

- [ ] **Step 1: Add parameter to interface and implementation**

```php
// DuplicateDetectionServiceInterface
public function createDuplicateFilenames(
    FileDuplicateCollection $fileDuplicateCollection,
    bool $skipHashSubGrouping = false,
): FileDuplicateCollection;
```

- [ ] **Step 2: Pass `skipHashSubGrouping: true` from RenameByHashCommand**

In `AbstractRenameCommand::createDuplicateFilenames()`, add the parameter passthrough. Override in `RenameByHashCommand` or add a method `isHashBasedGrouping(): bool` that returns `true`.

- [ ] **Step 3: Run `make test` — all pass, no behavioral change yet**

- [ ] **Step 4: Commit**

```
refactor: add skipHashSubGrouping parameter to createDuplicateFilenames
```

---

## Task 3: Implement hash sub-grouping (TDD)

This is the core task. Build it test-first.

**Files:**
- Modify: `src/Service/DuplicateDetectionService.php`
- Modify: `test/Unit/Service/DuplicateDetectionServiceTest.php`

### 3a: Test — two distinct files with same target get sequential numbers

- [ ] **Step 1: Write failing test**

Create two files on disk with different content but arrange them in the same `FileDuplicate` group. After `createDuplicateFilenames()`, assert:
- First file target: `basename-001.ext`
- Second file target: `basename-002.ext`
- Neither contains `-duplicate-`

- [ ] **Step 2: Run test — FAIL**

- [ ] **Step 3: Implement hash sub-grouping in `createDuplicateFilenames()`**

After renames are created and before suffix assignment, for groups with >1 non-companion file:

1. Compute hash for each source file (excluding companions)
2. Build `array<string, list<Rename>>` keyed by hash
3. If all hashes are identical → skip (pure duplicate group, existing logic handles it)
4. If multiple hashes exist → naming conflict:
   - Assign sequential number per unique hash (`-001`, `-002`, ...)
   - Update each rename's target to include the sub-group number
   - Within each sub-group, first file = canonical, rest = duplicates

- [ ] **Step 4: Run test — PASS**

- [ ] **Step 5: Commit**

```
feat: sub-group by content hash for naming conflicts
```

### 3b: Test — two identical files get duplicate suffix

- [ ] **Step 1: Write failing test**

Two files with identical content in same group. Assert:
- First: `basename-001.ext` (canonical)
- Second: `basename-001-duplicate-001.ext`

- [ ] **Step 2: Run test — verify behavior**

Should pass if the sub-grouping correctly identifies single-hash groups as pure duplicates and falls through to existing logic. If not, adjust.

- [ ] **Step 3: Commit**

```
test: verify identical files get duplicate suffix with hash sub-grouping
```

### 3c: Test — mixed distinct and duplicate files

- [ ] **Step 1: Write test**

5 files: A (hash X), A' (hash X), B (hash Y), B' (hash Y), C (hash Z). Assert:
- A → `basename-001.ext`
- A' → `basename-001-duplicate-001.ext`
- B → `basename-002.ext`
- B' → `basename-002-duplicate-001.ext`
- C → `basename-003.ext`

- [ ] **Step 2: Run — PASS**

- [ ] **Step 3: Commit**

```
test: cover mixed distinct and duplicate files in hash sub-grouping
```

### 3d: Test — single file group skips hashing

- [ ] **Step 1: Write test**

One file in group. Assert: no hash computed (mock `SafeHashCalculator` to fail if called), file gets plain target name.

- [ ] **Step 2: Run — PASS**

- [ ] **Step 3: Commit**

```
test: verify single-file groups skip content hashing
```

### 3e: Test — skipHashSubGrouping flag preserves old behavior

- [ ] **Step 1: Write test**

Two files with different content, `skipHashSubGrouping: true`. Assert: old behavior (first = canonical, second = `-duplicate-001`). No hash computed.

- [ ] **Step 2: Run — PASS**

- [ ] **Step 3: Commit**

```
test: verify skipHashSubGrouping preserves rename:hash behavior
```

### 3f: Test — hash computation failure

- [ ] **Step 1: Write test**

Mock `SafeHashCalculator` to throw `HashComputationException` for one file. Assert: that file gets its own sub-group number.

- [ ] **Step 2: Run — PASS**

- [ ] **Step 3: Commit**

```
test: handle hash computation failure gracefully
```

---

## Task 4: Live Photo companion handling with sub-groups

**Files:**
- Modify: `src/Service/DuplicateDetectionService.php`
- Modify: `test/Unit/Service/DuplicateDetectionServiceTest.php`

- [ ] **Step 1: Write test — companion inherits sub-group number**

Group: image A (hash X) + companion MOV A + image B (hash Y). Assert:
- A → `basename-001.jpg`
- MOV A → `basename-001.mov`
- B → `basename-002.jpg`

- [ ] **Step 2: Implement — companions excluded from hashing, inherit their image's number**

- [ ] **Step 3: Run `make test` — PASS**

- [ ] **Step 4: Commit**

```
feat: live photo companions inherit sub-group number
```

---

## Task 5: Idempotency for new suffix format

**Files:**
- Modify: `src/Service/DuplicateDetectionService.php` (`preAssignExistingDuplicateSuffixes`)
- Modify: `test/Unit/Service/DuplicateDetectionServiceTest.php`

- [ ] **Step 1: Write test — file with `-001` suffix keeps its name on rerun**

Source file named `2025-01-01_00-02-28-001.jpg`. Assert: target = source (no rename).

- [ ] **Step 2: Write test — file with `-001-duplicate-001` suffix keeps its name**

- [ ] **Step 3: Update regex in `preAssignExistingDuplicateSuffixes()`**

Match both:
- Legacy: `canonicalBasename-duplicate-NNN`
- New sequential: `canonicalBasename-NNN`
- New compound: `canonicalBasename-NNN-duplicate-NNN`

- [ ] **Step 4: Write test — legacy `-duplicate-001` suffix still recognized**

- [ ] **Step 5: Run `make test` — PASS**

- [ ] **Step 6: Commit**

```
feat: idempotency for sequential and compound suffix formats
```

---

## Task 6: Update summary metrics

**Files:**
- Modify: `src/Service/FileSystemService.php`
- Modify: `test/Unit/Service/FileSystemServiceTest.php`

- [ ] **Step 1: Add `Naming collisions` metric to summary**

Count groups where hash sub-grouping produced >1 sub-group. Pass this count from `DuplicateDetectionService` to `FileSystemService` (new parameter or return value).

- [ ] **Step 2: Update test assertions for summary output**

- [ ] **Step 3: Run `make test` — PASS**

- [ ] **Step 4: Commit**

```
feat: add naming collisions metric to summary output
```

---

## Task 7: Full integration test and CI

- [ ] **Step 1: Run `make test` — full CI pipeline green**

- [ ] **Step 2: Test manually with real images**

```bash
make run CMD="rename:exif images --dry-run --list-all"
```

Verify:
- Single-date images: no number, no hash
- Same-date different-content: sequential numbers `-001`, `-002`
- Same-date same-content: `-duplicate-001` suffix
- Companions inherit their image's number
- `rename:hash` behavior unchanged

- [ ] **Step 3: Commit any fixes**

- [ ] **Step 4: Final commit**

```
feat: content-aware duplicate detection
```
