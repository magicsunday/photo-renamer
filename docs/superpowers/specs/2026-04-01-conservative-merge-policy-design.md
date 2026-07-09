# Conservative Merge Policy — Design Spec

**Date:** 2026-04-01
**Branch:** TBD (from `main`)
**Status:** Draft

---

## Problem

`HashSubGroupingService::mergePerceptuallySimilarGroups()` merges perceptually similar hash groups too aggressively. This causes two concrete failures:

### Failure 1: Edited image absorbed into canonical cluster

Three files share a timestamp — an original, a heavily edited version (`-002`), and a minimally edited version (`-003`). The minimal edit has a dHash distance of 0 from the original (the 9x8 gradient grid doesn't capture the local change). The current code fast-paths `dhashDistance === 0` to immediate merge, skipping Stage B entirely. Result: `-003` lands in the canonical cluster and becomes `-duplicate-001` instead of its own subgroup.

### Failure 2: Circular swap from unstable subgroup numbering

When the same three files are split across directories (edits in `edited/`), the cluster analysis assigns subgroup numbers that swap the existing `-002` and `-003` suffixes. The validator correctly detects the cycle and aborts.

In the reported fixture, the circular swap is likely downstream of an incorrect merge decision. However, subgroup renumbering itself is currently documented as deterministic accepted behavior when the cluster set changes (tested in `clusterRenumberingIsDeterministicWhenNewClusterAppears`). This spec addresses the reported case by preventing the incorrect merge upstream.

### Why the naming layer is not the fix

The existing `TargetNameResolver` already contains strong idempotency logic (3-priority sort, idempotent basename matching). It correctly produces no-ops on a second run — **when the clusters are correct**. The problem is upstream: wrong clusters produce wrong names, and no amount of naming-layer stability can compensate for incorrect clustering.

---

## Design Principles

### Regel 1: Cluster formation is filename-free

Clustering decisions derive exclusively from image content (hashes, pixel data, RMSE, blob analysis). Never from current filenames, existing `-NNN` suffixes, or alphabetical ordering of paths.

### Regel 2: When in doubt, don't merge

A false merge (absorbing an edit into the wrong cluster) is worse than a false split (extra subgroup). Extra subgroups produce harmless `-NNN` suffixes. False merges produce incorrect `-duplicate-` labels and can cause circular swaps.

### Regel 3: Second run must be a no-op

Achieved through correct clustering (this spec) + existing naming idempotency (already implemented). Not through filename-based cluster stabilization.

---

## Solution: Multi-Stage Conservative Merge Decision

Replace the current inline merge logic with a structured `shouldMergePerceptually()` method that implements early-exit zones.

### Current behavior (to be replaced)

```php
if ($result->isDuplicateLikely()) {
    $shouldMerge = ($result->dhashDistance === 0)
        || !$this->hasLocalRetouchCached(...);
}
```

Problems:
- `dhashDistance === 0` bypasses all local analysis
- `hasLocalRetouchCached()` only checks RMSE (blob analysis disabled)
- RMSE threshold 0.05 is 3-5x higher than documented codec-noise range (0.001-0.013)

### New behavior

```php
if ($result->isDuplicateLikely()) {
    $shouldMerge = $this->shouldMergePerceptually(
        $representativeByHash[$hashes[$i]],
        $representativeByHash[$hashes[$j]],
        $result,
        $stageBImageCache,
    );
}
```

### Stage A: Cheap pre-filter

`SimilarityResult` classification:
- `Different` → **no merge** (early exit)
- `EditedVariant` → **no merge** (early exit)
- `DuplicateLikely` → proceed to Stage B

**Known limitation:** `PerceptualHashCalculator::similarityScore()` contains its own `dHash == 0` early exit (line 355) that returns `DuplicateLikely` with `score=100` and **skips wHash, HF-energy, and color histogram computation entirely**. This means minimally edited images that fool the 9x8 gradient grid arrive at `shouldMergePerceptually()` classified as `DuplicateLikely` with zeroed secondary signals — none of which were actually measured. The wHash/HF/color signals never get a chance to flag the pair as `EditedVariant` (score 85-94).

**Mitigation in this spec:** The RMSE gate in Stage B catches these false `DuplicateLikely` classifications. This is sufficient for the reported failures.

**Future improvement (out of scope):** Remove the `PerceptualHashCalculator` dHash==0 early exit so all signals are computed. This would allow Stage A itself to classify some of these pairs as `EditedVariant`, avoiding Stage B entirely. Tracked as a follow-up, not a blocker for this spec.

### Stage B1: Fast RMSE check with early-exit zones

For still-image pairs classified as `DuplicateLikely`:

1. Compute local RMSE via `LocalDifferenceAnalyzer`
2. Apply two hard thresholds:

| Zone | RMSE range | Decision | Blob needed? |
|------|-----------|----------|-------------|
| Safe merge | `rmse <= SAFE_MERGE_RMSE` | Merge | No |
| Gray zone | `SAFE_MERGE_RMSE < rmse < maxMergeRmse` | Undecided | Yes |
| Safe no-merge | `rmse >= maxMergeRmse` | No merge | No |

Suggested initial thresholds:
- `SAFE_MERGE_RMSE = 0.015` — covers documented HEIC-to-JPG codec noise (0.001-0.013)

**Calibration prerequisite (before implementation):** Measure the actual RMSE of the failure-case images (the minimally edited `-003` image from Failure 1 vs the original). If its RMSE falls below 0.015, the safe-merge zone would absorb it and Step 1 alone will NOT fix the problem — Step 2 (blob analysis) becomes mandatory. Also gather RMSE measurements from at least 3 camera models (HEIC encoders differ between iPhone generations) to validate the 0.013 ceiling.

Analysis failure (`success === false`) → **no merge** (conservative).

### Stage B2: Gray-zone blob analysis

Only invoked when RMSE is ambiguous (between the two thresholds). Uses morphology/connected-component analysis to detect compact local edits:

- `hasCompactRetouch === true` → **no merge**
- `hasCompactRetouch === false` → **merge**

This is the existing blob analysis code in `LocalDifferenceAnalyzer`, currently disabled behind `enableBlobAnalysis = false` and labeled "legacy" and "superseded by RMSE" in code comments.

**Validation prerequisite:** Before enabling blob analysis as a gray-zone decision-maker, validate it against the actual failure-case images. If the minimally edited image falls in the gray zone AND `hasCompactRetouch` fails to detect it, the gray-zone strategy needs revision. Fallback: if blob proves unreliable, the gray zone defaults to **no merge** (consistent with Regel 2: "When in doubt, don't merge"), making blob analysis a non-blocking enhancement.

**Operational definition of "unreliable":** Blob analysis is considered unreliable if any of these hold during Step 2 validation:
1. `hasCompactRetouch` returns `false` for the failure-case minimally edited image (false negative — misses the edit)
2. `hasCompactRetouch` returns `true` for clean codec-noise-only pairs from the calibration set (false positive — flags a format conversion as an edit)
3. Blob analysis throws exceptions or produces `success=false` on valid Imagick inputs

### Video pairs

No change in this spec. Video similarity uses duration as the primary differentiator. The merge policy change targets still-image pairs only.

---

## Code Changes

### File 1: `src/Service/HashSubGroupingService.php`

#### Rename property

`maxMergeChangedArea` → `maxMergeRmse`

Also rename `setMaxMergeChangedArea()` → `setMaxMergeRmse()`. Update all callers (command, tests).

#### Add threshold constants/properties

```php
private const float SAFE_MERGE_RMSE = 0.015;
```

`maxMergeRmse` (user-settable, default 0.05 via `--merge-threshold`) acts as the upper bound of the merge window. `SAFE_MERGE_RMSE` is a constant (codec noise ceiling, not user-adjustable). The gray zone spans from `SAFE_MERGE_RMSE` to `maxMergeRmse`.

**Validation:** If `maxMergeRmse <= SAFE_MERGE_RMSE`, the gray zone collapses and blob analysis never runs. This is valid — it means the user wants strict codec-noise-only merging. Effective behavior: `rmse <= maxMergeRmse` merges, everything above separates. No need for `SAFE_NO_MERGE_RMSE` as a separate constant — `maxMergeRmse` IS the no-merge boundary.

#### New method: `shouldMergePerceptually()`

```php
private function shouldMergePerceptually(
    SplFileInfo $fileA,
    SplFileInfo $fileB,
    SimilarityResult $similarity,
    array &$imageCache,
): bool
```

Logic:
1. `!$similarity->isDuplicateLikely()` → return false
2. Video pairs → preserve current behavior: if either file is a video, skip RMSE/blob analysis and return `true` (merge). This matches the existing logic at `hasLocalRetouchCached()` line 649-650 which returns `false` (no retouch = merge) for any video pair. No new conservative policy for videos in this spec. Mixed still+video pairs that reach `shouldMergePerceptually()` are anomalous (different content hashes should prevent this); if encountered, they follow the same video-skips-analysis rule.
3. Analyze local difference via `analyzeLocalDifferenceCached()` (returns `LocalDiffResult`)
4. Analysis failure → return false (conservative)
5. `rmse <= SAFE_MERGE_RMSE` → return true (safe merge zone)
6. `rmse >= maxMergeRmse` → return false (safe no-merge zone)
7. Gray zone: `hasCompactRetouch` → return false; else return true

**Behavioral change:** Currently, Imagick load failure in `hasLocalRetouchCached()` returns `false` (no retouch detected), which causes a **merge**. After this change, analysis failure returns `false` from `shouldMergePerceptually()`, which prevents merge (conservative). This may produce additional subgroups for files that Imagick cannot load (e.g., unsupported RAW formats without delegate). This is the correct behavior per Regel 2.

#### Replace `hasLocalRetouchCached()`

Rename to `analyzeLocalDifferenceCached()`. Return `LocalDiffResult` instead of `bool`. The caller (`shouldMergePerceptually`) interprets the result according to the zone logic.

#### Remove dHash==0 fast-path

Delete the conditional that bypasses Stage B when `dhashDistance === 0`. All `DuplicateLikely` still-image pairs must pass through at minimum the RMSE gate.

#### Update `mergePerceptuallySimilarGroups()`

Replace inline merge decision with `shouldMergePerceptually()` call.

#### Deterministic union-find root selection

After all pairwise merges complete, the union-find root for each component depends on the order `union()` was called. Different file orderings could produce different roots, which changes the dict key in `$merged` and downstream subgroup numbering.

Fix: after the merge loop, re-root each component to the lexicographically smallest hash string. This guarantees deterministic cluster identity regardless of comparison order:

```php
for ($i = 0; $i < $count; ++$i) {
    $root = $this->findRoot($parent, $i);
    $rootHash = $hashes[$root];
    $myHash = $hashes[$i];
    // Re-root to lexicographically smallest hash in the component
    if ($myHash < $rootHash) {
        $parent[$root] = $i;
    }
}
```

### File 2: `src/Service/PerceptualHash/LocalDifferenceAnalyzer.php`

`LocalDifferenceAnalyzer` is a **measurement service**, not a policy component. It computes and returns values; the caller (`HashSubGroupingService`) decides what they mean. The CLI already sets merge thresholds on `HashSubGroupingService`, not on the analyzer — this separation must be preserved.

#### Two methods: RMSE-only and detailed

**Keep the existing Imagick signature** — the caller manages image loading and the per-merge `$stageBImageCache`. No signature change to SplFileInfo.

Remove the global `enableBlobAnalysis` boolean. Replace with two explicit methods:

```php
public function analyzeRmse(Imagick $imageA, Imagick $imageB): LocalDiffResult
public function analyzeDetailed(Imagick $imageA, Imagick $imageB): LocalDiffResult
```

Both methods return the existing `LocalDiffResult` (`src/Service/PerceptualHash/LocalDiffResult.php`), an immutable readonly class with fields: `float $rmse`, `float $changedAreaRatio`, `float $largestBlobRatio`, `int $blobCount`, `bool $hasCompactRetouch`, `bool $success`. The `success` field distinguishes analysis failure (`rmse=0, success=false`) from a perfect pixel match (`rmse=0, success=true`).

- `analyzeRmse()`: computes RMSE only, blob fields zeroed (`changedAreaRatio=0, largestBlobRatio=0, blobCount=0, hasCompactRetouch=false`). Fast path.
- `analyzeDetailed()`: computes RMSE first, then blob/morphology/connected-component analysis in the same pass, reusing the already-exported pixel arrays. Returns full `LocalDiffResult` with all fields populated.

Neither method knows about thresholds or merge policy. The caller decides which to call:

```php
// In HashSubGroupingService::shouldMergePerceptually():
$diff = $this->analyzer->analyzeRmse($imgA, $imgB);

if ($diff->rmse <= self::SAFE_MERGE_RMSE) { return true; }
if ($diff->rmse >= $this->maxMergeRmse) { return false; }

// Gray zone — need blob data
$diff = $this->analyzer->analyzeDetailed($imgA, $imgB);
return !$diff->hasCompactRetouch;
```

**Pixel reuse between calls:** Both methods accept pre-loaded Imagick objects (already cached by the caller). The `analyzeDetailed()` call re-exports pixels from the same Imagick instances — this is the cheap part (cloning + pixel export). The expensive part (disk I/O + decode) is handled once by the caller's image cache.

#### Remove legacy boolean

Delete `private bool $enableBlobAnalysis = false` and the conditional check. The two-method API replaces it.

### File 3: `src/Command/RenameByExifDateCommand.php`

Update caller of `setMaxMergeChangedArea()` → `setMaxMergeRmse()`.

### File 4: `config/Services.yaml`

No changes expected (autowiring handles it). Add `LocalDifferenceAnalyzerInterface` binding if the interface is introduced.

### File 5 (optional): `src/Service/PerceptualHash/LocalDifferenceAnalyzerInterface.php`

Optional refactoring: extract `LocalDifferenceAnalyzerInterface` to simplify unit testing and decouple `HashSubGroupingService` from the concrete analyzer implementation. Useful for injecting stubs that return controlled `LocalDiffResult` values (specific RMSE, specific `hasCompactRetouch`). Not a blocker for the fachlichen Fix — the merge policy change works without it.

---

## Test Strategy

### A. Unit tests for `HashSubGroupingService`

In `tests/Unit/Service/HashSubGroupingServiceTest.php`:

**Fast-path removal:**

1. **`duplicateLikely + dhashZero + localRetouch => no merge`**
   - Proves the old fast-path is gone
   - dHash=0 but local analysis detects compact retouch
   - `shouldMergePerceptually()` returns `false` → files stay in separate hash groups → `apply()` returns `true` (sub-grouping exists)
   - Expect: two separate subgroups

**Threshold zone logic:**

2. **`duplicateLikely + lowRmse + noRetouch => merge`**
   - Real format conversions remain mergeable
   - RMSE within safe-merge zone
   - `shouldMergePerceptually()` returns `true` → files merge into same hash group → `apply()` returns `false` (no sub-grouping)
   - Expect: single group (no subgroups)

3. **`duplicateLikely + grayZoneRmse + compactRetouch => no merge`**
   - Gray-zone triggers blob analysis, blob detects edit
   - Expect: separate subgroups

4. **`duplicateLikely + grayZoneRmse + noRetouch => merge`**
   - Gray-zone triggers blob analysis, no edit found
   - Expect: merge

**Failure handling:**

5. **`duplicateLikely + analyzerFailure => no merge`**
   - Conservative fallback
   - Expect: separate subgroups

### B. Unit tests for `LocalDifferenceAnalyzer`

**`analyzeRmse()` path:**

1. **Pixel-identical or codec noise** — low RMSE, success=true
2. **Clearly different** — high RMSE, success=true

**`analyzeDetailed()` path (blob analysis):**

3. **Local compact edit detected** — `hasCompactRetouch=true`
4. **No compact edit** — `hasCompactRetouch=false`

**Failure handling:**

5. **Imagick error** — `success=false`

### C. Integration test: dHash==0 full pipeline

Real images where dHash==0 but the images have a local edit. Verify the full pipeline (`PerceptualHashCalculator` → `HashSubGroupingService` → `shouldMergePerceptually`) correctly separates them. This catches the interaction between the calculator's dHash==0 early exit (which produces zeroed secondary signals) and the service's merge decision.

### D. Integration test with real scenario

Using the three test images (original + 2 edits):

**Run 1 expectations:**
- Three separate clusters
- Three stable target names (groupKey, groupKey-002, groupKey-003)
- No `-duplicate-001` for the minimally edited image

**Run 2 expectations (idempotency):**
- Same clusters
- Same target names
- `Planned moves = 0`
- All files `[O]`

### E. Integration test with arbitrary initial names

Same content but files named `foo.jpg`, `bar.jpg`, `xyz.jpg`:
- Run 1 produces same final names as with "orderly" names
- Run 2 is no-op

Proves: clustering is filename-free, naming is stable.

---

## What explicitly does NOT change

- **`TargetNameResolver::buildSubgroupMap()`** — alphabetical cluster ordering stays. The fix is upstream (correct clusters), not in naming.
- **Idempotency logic in `TargetNameResolver`** — 3-priority sort, idempotent basename matching. Already correct.
- **`SubgroupClassifier`** — pure adapter, writes back what `HashSubGroupingService` produces.
- **Video merge logic** — out of scope. This spec targets still-image pairs.

---

## Backward Compatibility Requirement

This change modifies merge policy, not just a localized bug fix. All currently supported and already-correct behaviors must be preserved.

**Gate:**
- All existing unit tests remain green
- All existing integration scenario tests remain green
- No existing expected rename mappings may change unless explicitly justified by a newly added failing regression fixture

## Regression-First Requirement

Before changing thresholds or merge policy, add a failing regression test that reproduces the reported bug. This prevents optimizing on assumption.

**Required layers:**

1. **Unit-level regression** for `HashSubGroupingService`: `duplicateLikely + dhashZero + localEdit => no merge`
2. **Integration-level regression** with real fixture images (original + heavily edited + minimally edited, where the minimal edit has dHash==0)
3. **Second-run idempotency assertion** for that same fixture

**Blocker:** If the reported real-world failure cannot be reproduced in the test suite, implementation must not proceed to threshold tuning. First, add a deterministic regression fixture or a stable synthetic reproducer that fails under the current logic and passes under the new logic.

The existing test infrastructure is strong — `TestImageScenariosTest` already covers cross-directory edits (`24-cross-dir-edits`), same-directory edits (`44-same-dir-edit`), format backups (`42-same-dir-format-backup`), and idempotency (`49-*`). The gap is not infrastructure but a missing fixture for the specific dHash==0 minimal-edit false-merge edge case.

---

## Acceptance Criteria

### Functional
- Minimally edited images are NOT merged into the canonical cluster
- Real format conversions (HEIC-to-JPG) remain mergeable
- `EditedVariant` classification prevents merge (existing, preserved)

### Technical
- No cluster decision depends on current filenames
- `dHash == 0` no longer triggers automatic merge
- Local retouches act as merge veto
- Analysis failures conservatively separate
- Blob analysis runs only for still-image pairs in the RMSE gray zone (not globally, not for videos)
- Cluster assignment for the same input set is deterministic and independent of source path ordering

### Backward compatibility
- Existing scenarios (format backups, same-directory edits, cross-directory edits, second-run idempotency) must keep their current expected outputs unchanged unless the new regression fixture proves an existing expectation was wrong

### Idempotency
- Second dry-run on already-renamed collection produces:
  - Same clusters
  - Same target names
  - Zero planned moves

### CLI semantics
- `--merge-threshold` defines the upper no-merge boundary:
  - `rmse <= SAFE_MERGE_RMSE`: merge (codec noise, safe)
  - `SAFE_MERGE_RMSE < rmse < --merge-threshold`: gray zone (blob decides, or no-merge if blob unavailable)
  - `rmse >= --merge-threshold`: no merge
- This should be documented in the CLI help text

---

## Implementation Order

The order is: reproduce first, then fix, then verify.

### Step 0: Calibration + regression fixture (prerequisite)

Before any policy change:
1. Measure RMSE of the failure-case images (minimally edited `-003` vs original)
2. Measure RMSE of 3+ HEIC-to-JPG codec conversions from different camera models
3. Document results to validate the 0.015 threshold
4. Add a **failing regression test** that reproduces the reported bug under the current logic
   - Unit level: `HashSubGroupingService` with dHash==0 + local edit → currently merges (wrong)
   - Integration level: real fixture images → currently produces `-duplicate-001` (wrong)
5. If failure-case RMSE < 0.015: Step 2 is mandatory, not optional

### Step 1: Harden merge decision in `HashSubGroupingService`

- Remove `dHash == 0` fast-path
- Extract `shouldMergePerceptually()`
- Rename `maxMergeChangedArea` → `maxMergeRmse`
- Add `analyzeRmse()` to `LocalDifferenceAnalyzer` (extract from existing `analyze()`)
- Introduce `analyzeDetailed()` as API stub (returns same as `analyzeRmse()` for now — blob not yet implemented)
- Extract `LocalDifferenceAnalyzerInterface` (optional, recommended for unit tests)
- `shouldMergePerceptually()` uses only RMSE-based early-exit zones; gray zone defaults to **no merge** (Regel 2) until Step 2 provides blob data
- Verify: regression test from Step 0 now passes
- Verify: all existing tests remain green

**Validation checkpoint:** Run the regression test from Step 0 after Step 1. The regression test must now **pass** (failure case correctly separated). If it does not pass, the RMSE gate alone is insufficient — do not proceed to Step 3, go directly to Step 2. Note: "tests pass" here means specifically "the regression test produces correct behavior", not just "no test failures". If the regression test was never written to expect the correct behavior, a green test suite proves nothing.

### Step 2: Enable gray-zone blob analysis (conditional hardening)

Step 2 is conditional. It is only required if calibration shows that the failure-case minimal edit falls inside the gray zone where RMSE alone cannot decide.

- Implement blob/morphology/connected-component logic inside `analyzeDetailed()`
- `shouldMergePerceptually()` calls `analyzeDetailed()` in gray zone instead of defaulting to no-merge
- Validate blob analysis against failure-case images before relying on it
- If blob proves unreliable: keep gray-zone default at no-merge (Regel 2)
- Calibrate thresholds with real fixtures
- Add unit tests

### Step 3: Integration tests with real images

- dHash==0 full pipeline test (PerceptualHashCalculator → shouldMergePerceptually)
- Three-image scenario (original + 2 edits)
- Arbitrary-name scenario
- Idempotency proof (run 1 + run 2)
