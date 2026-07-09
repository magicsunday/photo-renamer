# Conservative Merge Policy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden the perceptual merge decision in `HashSubGroupingService` to prevent minimally edited images from being absorbed into the canonical cluster, fixing false merges and resulting circular swaps.

**Architecture:** Remove the `dHash == 0` fast-path that bypasses Stage B analysis. Extract merge policy into `shouldMergePerceptually()` with RMSE-based early-exit zones (safe merge / gray zone / safe no-merge). Split `LocalDifferenceAnalyzer` into `analyzeRmse()` + `analyzeDetailed()` methods, keeping it a pure measurement service while `HashSubGroupingService` owns all policy. Gray zone defaults to no-merge until blob analysis is validated (Step 2, conditional).

**Tech Stack:** PHP 8.5, Imagick, PHPUnit 12, PHPStan, Docker buildbox

**Spec:** `docs/superpowers/specs/2026-04-01-conservative-merge-policy-design.md`

**Branch:** `fix/conservative-merge-policy` (from `main`)

---

## File Map

| Action | File | Responsibility |
|--------|------|---------------|
| Modify | `src/Service/HashSubGroupingService.php` | Merge policy: `shouldMergePerceptually()`, rename threshold, remove dHash fast-path, deterministic union-find roots |
| Modify | `src/Service/PerceptualHash/LocalDifferenceAnalyzer.php` | Measurement: split `analyze()` into `analyzeRmse()` + `analyzeDetailed()`, remove `enableBlobAnalysis` |
| Modify | `src/Command/RenameByExifDateCommand.php` | Caller: rename `setMaxMergeChangedArea` → `setMaxMergeRmse`, update CLI help text |
| Modify | `tests/Unit/Service/HashSubGroupingServiceTest.php` | Unit tests for new merge policy |
| Modify | `tests/Integration/TestImageScenariosTest.php` | Integration regression fixture |
| Create | `tests/Fixtures/Images/59-minimal-edit-false-merge/` | Real fixture images for regression test |

---

## Task 1: Calibration measurement (Step 0 prerequisite)

**Files:**
- Read: `src/Service/PerceptualHash/LocalDifferenceAnalyzer.php`
- Read: `/volume1/Fotos/MobileBackup/Test4/` (failure-case images)

Before any code change, measure the actual RMSE of the failure-case images to validate the 0.015 threshold.

- [ ] **Step 1: Measure RMSE of the failure-case images**

Run the analyzer directly on the three images from the reported failure (original + heavily edited + minimally edited):

```bash
docker compose run --rm buildbox php -r "
require 'vendor/autoload.php';
use MagicSunday\Renamer\Service\PerceptualHash\LocalDifferenceAnalyzer;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\MediaTypeClassifier;

\$loader = new ImagickImageLoader(new MediaTypeClassifier());
\$analyzer = new LocalDifferenceAnalyzer();

\$original = \$loader->loadNormalized(new SplFileInfo('/volume1/Fotos/MobileBackup/Test4/2009-07-25_11-27-50-100.jpg'), 512);
\$edit002 = \$loader->loadNormalized(new SplFileInfo('/volume1/Fotos/MobileBackup/Test4/2009-07-25_11-27-50-100-002.jpg'), 512);
\$edit003 = \$loader->loadNormalized(new SplFileInfo('/volume1/Fotos/MobileBackup/Test4/2009-07-25_11-27-50-100-003.jpg'), 512);

\$r1 = \$analyzer->analyze(\$original, \$edit002);
echo 'Original vs -002 (heavy edit): RMSE=' . round(\$r1->rmse, 6) . PHP_EOL;

\$r2 = \$analyzer->analyze(\$original, \$edit003);
echo 'Original vs -003 (minimal edit): RMSE=' . round(\$r2->rmse, 6) . PHP_EOL;

\$r3 = \$analyzer->analyze(\$edit002, \$edit003);
echo '-002 vs -003: RMSE=' . round(\$r3->rmse, 6) . PHP_EOL;
"
```

Document the output. If `-003` RMSE < 0.015: Step 2 (blob analysis) is mandatory, not optional.

- [ ] **Step 2: Measure dHash distance to confirm dHash==0 for failure case**

```bash
docker compose run --rm buildbox php -r "
require 'vendor/autoload.php';
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculator;
use MagicSunday\Renamer\Service\MediaTypeClassifier;

\$calc = new PerceptualHashCalculator(new MediaTypeClassifier());

\$result = \$calc->similarityScore(
    new SplFileInfo('/volume1/Fotos/MobileBackup/Test4/2009-07-25_11-27-50-100.jpg'),
    new SplFileInfo('/volume1/Fotos/MobileBackup/Test4/2009-07-25_11-27-50-100-003.jpg'),
    null, null,
);

echo 'Original vs -003: score=' . \$result->score . ' dHash=' . \$result->dhashDistance . ' classification=' . \$result->classification->name . PHP_EOL;
"
```

Expected: `dHash=0`, `classification=DuplicateLikely`. This confirms the failure-case triggers the fast-path.

- [ ] **Step 3: Document calibration results**

Create a file `tests/Fixtures/Images/59-minimal-edit-false-merge/CALIBRATION.md` with the measured values. This is the evidence that the threshold 0.015 is (or is not) correct.

---

## Task 2: Create regression fixture and failing test

**Files:**
- Create: `tests/Fixtures/Images/59-minimal-edit-false-merge/` (copy of failure-case images)
- Modify: `tests/Integration/TestImageScenariosTest.php`

- [ ] **Step 1: Copy failure-case images to fixture directory**

```bash
mkdir -p tests/Fixtures/Images/59-minimal-edit-false-merge
cp '/volume1/Fotos/MobileBackup/Test4/2009-07-25_11-27-50-100.jpg' tests/Fixtures/Images/59-minimal-edit-false-merge/
cp '/volume1/Fotos/MobileBackup/Test4/2009-07-25_11-27-50-100-002.jpg' tests/Fixtures/Images/59-minimal-edit-false-merge/
cp '/volume1/Fotos/MobileBackup/Test4/2009-07-25_11-27-50-100-003.jpg' tests/Fixtures/Images/59-minimal-edit-false-merge/
```

- [ ] **Step 2: Write the failing integration test**

Add to `tests/Integration/TestImageScenariosTest.php` in the data provider:

```php
// Scenario 59: minimal edit false merge — dHash==0 but images are distinct.
// Original + heavily edited (-002) + minimally edited (-003) must produce
// three separate subgroups, NOT merge -003 into canonical cluster as duplicate.
// Regression test for: dHash==0 fast-path bypass in HashSubGroupingService.
yield '59-minimal-edit-false-merge' => [
    '59-minimal-edit-false-merge',
    [
        '2009-07-25_11-27-50-100.jpg'     => '2009-07-25_11-27-50-100.jpg',
        '2009-07-25_11-27-50-100-002.jpg' => '2009-07-25_11-27-50-100-002.jpg',
        '2009-07-25_11-27-50-100-003.jpg' => '2009-07-25_11-27-50-100-003.jpg',
    ],
    3,
];
```

Note: expected mapping assumes each image stays as its own subgroup (idempotent names). Adjust target names based on actual calibration if needed — the key assertion is that `-003` is NOT renamed to `-duplicate-001`.

- [ ] **Step 3: Run the test to verify it fails under current code**

```bash
docker compose run --rm buildbox .build/bin/phpunit --filter "59-minimal-edit-false-merge" tests/Integration/TestImageScenariosTest.php
```

Expected: FAIL — current code merges `-003` into canonical cluster and renames it to `-duplicate-001`.

- [ ] **Step 4: Commit the failing regression test**

```bash
git add tests/Fixtures/Images/59-minimal-edit-false-merge/ tests/Integration/TestImageScenariosTest.php
git commit -m "test: add failing regression fixture for dHash==0 minimal-edit false merge"
```

---

## Task 3: Rename `maxMergeChangedArea` → `maxMergeRmse`

**Files:**
- Modify: `src/Service/HashSubGroupingService.php:54,51,78-81`
- Modify: `src/Command/RenameByExifDateCommand.php:152`

- [ ] **Step 1: Rename property and setter in HashSubGroupingService**

In `src/Service/HashSubGroupingService.php`:

```php
// Line 49-54: rename property and docblock
/**
 * Maximum RMSE for merging isDuplicateLikely pairs.
 * Configurable at runtime via setMaxMergeRmse().
 * HEIC↔JPG format conversions: 0.001–0.013. Different photos: 0.25+.
 */
private float $maxMergeRmse = 0.05;
```

```php
// Line 74-81: rename setter
/**
 * Sets the maximum RMSE threshold for merging isDuplicateLikely pairs.
 * Pairs with RMSE at or above this threshold are kept as separate sub-groups.
 */
public function setMaxMergeRmse(float $threshold): void
{
    $this->maxMergeRmse = $threshold;
}
```

Update all internal references from `$this->maxMergeChangedArea` to `$this->maxMergeRmse` (line 678).

- [ ] **Step 2: Update caller in RenameByExifDateCommand**

In `src/Command/RenameByExifDateCommand.php` line 152:

```php
$this->hashSubGroupingService->setMaxMergeRmse(
    $this->resolveMergeThreshold($this->input),
);
```

- [ ] **Step 3: Update CLI help text**

In `src/Command/RenameByExifDateCommand.php` line 120, replace the option description:

```php
'Maximum RMSE (0.0–1.0) for merging visually similar files. Below 0.015: automatic codec-noise merge. Between 0.015 and threshold: conservative analysis. Overrides MERGE_THRESHOLD env var. Default: 0.05.',
```

- [ ] **Step 4: Run tests to verify rename is complete**

```bash
make test
```

Expected: all existing tests green (PHPStan catches any missed references).

- [ ] **Step 5: Commit**

```bash
git add src/Service/HashSubGroupingService.php src/Command/RenameByExifDateCommand.php
git commit -m "refactor: rename maxMergeChangedArea to maxMergeRmse"
```

---

## Task 4: Split `LocalDifferenceAnalyzer` into `analyzeRmse()` + `analyzeDetailed()`

**Files:**
- Modify: `src/Service/PerceptualHash/LocalDifferenceAnalyzer.php`
- Modify: `src/Service/HashSubGroupingService.php:69,671`

- [ ] **Step 1: Add `analyzeRmse()` and `analyzeDetailed()` methods**

In `src/Service/PerceptualHash/LocalDifferenceAnalyzer.php`, keep the existing `analyze()` as a deprecated wrapper and add two new methods:

```php
/**
 * Computes RMSE only. Fast path — blob fields zeroed in result.
 */
public function analyzeRmse(Imagick $imageA, Imagick $imageB): LocalDiffResult
{
    try {
        return $this->doRmseOnly($imageA, $imageB);
    } catch (Throwable) {
        return new LocalDiffResult(0.0, 0.0, 0.0, 0, false, success: false);
    }
}

/**
 * Computes RMSE + blob/morphology/connected-component analysis in a single pass.
 * Reuses pixel arrays from RMSE computation for blob analysis.
 */
public function analyzeDetailed(Imagick $imageA, Imagick $imageB): LocalDiffResult
{
    try {
        return $this->doDetailed($imageA, $imageB);
    } catch (Throwable) {
        return new LocalDiffResult(0.0, 0.0, 0.0, 0, false, success: false);
    }
}
```

- [ ] **Step 2: Extract `doRmseOnly()` from existing `doAnalyze()`**

Extract the RMSE computation (lines 76-119) into `doRmseOnly()`:

```php
private function doRmseOnly(Imagick $imageA, Imagick $imageB): LocalDiffResult
{
    [$pixelsA, $pixelsB, $totalPixels, $width, $height] = $this->exportPixels($imageA, $imageB);
    $rmse = $this->computeRmse($pixelsA, $pixelsB, $totalPixels);

    return new LocalDiffResult($rmse, 0.0, 0.0, 0, false);
}
```

- [ ] **Step 3: Add `doDetailed()` that computes RMSE + blob in one pass**

```php
private function doDetailed(Imagick $imageA, Imagick $imageB): LocalDiffResult
{
    [$pixelsA, $pixelsB, $totalPixels, $width, $height] = $this->exportPixels($imageA, $imageB);
    $rmse = $this->computeRmse($pixelsA, $pixelsB, $totalPixels);

    return $this->doLegacyBlobAnalysis($pixelsA, $pixelsB, $totalPixels, $width, $height, $rmse);
}
```

- [ ] **Step 4: Extract shared helpers `exportPixels()` and `computeRmse()`**

```php
/**
 * @return array{list<int>, list<int>, int, int, int} [pixelsA, pixelsB, totalPixels, width, height]
 */
private function exportPixels(Imagick $imageA, Imagick $imageB): array
{
    $grayA = $this->downscaleGray($imageA);
    $grayB = $this->downscaleGray($imageB);

    $width  = $grayA->getImageWidth();
    $height = $grayA->getImageHeight();

    if (($width !== $grayB->getImageWidth()) || ($height !== $grayB->getImageHeight())) {
        $grayB->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1.0, false);
    }

    /** @var list<int> $pixelsA */
    $pixelsA = $grayA->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR);

    /** @var list<int> $pixelsB */
    $pixelsB = $grayB->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR);

    $grayA->clear();
    $grayB->clear();

    return [$pixelsA, $pixelsB, $width * $height, $width, $height];
}

private function computeRmse(array $pixelsA, array $pixelsB, int $totalPixels): float
{
    $sumSquaredErr = 0.0;

    for ($i = 0; $i < $totalPixels; ++$i) {
        $diff = $pixelsA[$i] - $pixelsB[$i];
        $sumSquaredErr += $diff * $diff;
    }

    return sqrt($sumSquaredErr / $totalPixels) / 255.0;
}
```

- [ ] **Step 5: Remove `enableBlobAnalysis` boolean and update `analyze()` to delegate**

Remove the `private bool $enableBlobAnalysis = false;` property. Update the existing `analyze()` to call `analyzeRmse()` (backward compat — callers that still use `analyze()` get RMSE-only):

```php
public function analyze(Imagick $imageA, Imagick $imageB): LocalDiffResult
{
    return $this->analyzeRmse($imageA, $imageB);
}
```

- [ ] **Step 6: Update HashSubGroupingService to call `analyzeRmse()`**

In `src/Service/HashSubGroupingService.php`, update `hasLocalRetouchCached()` line 671 to use `analyzeRmse()`:

```php
$diffResult = $this->localDiffAnalyzer->analyzeRmse($imgA, $imgB);
```

- [ ] **Step 7: Run tests**

```bash
make test
```

Expected: all green — behavior unchanged (same RMSE computation).

- [ ] **Step 8: Commit**

```bash
git add src/Service/PerceptualHash/LocalDifferenceAnalyzer.php src/Service/HashSubGroupingService.php
git commit -m "refactor: split LocalDifferenceAnalyzer into analyzeRmse() and analyzeDetailed()"
```

---

## Task 5: Extract `shouldMergePerceptually()` with RMSE zones

**Files:**
- Modify: `src/Service/HashSubGroupingService.php`

- [ ] **Step 1: Add `SAFE_MERGE_RMSE` constant**

```php
/**
 * RMSE at or below this value is safe codec noise (HEIC↔JPG conversions).
 * Merge immediately without blob analysis.
 */
private const float SAFE_MERGE_RMSE = 0.015;
```

- [ ] **Step 2: Rename `hasLocalRetouchCached()` to `analyzeLocalDifferenceCached()`**

Change return type from `bool` to `LocalDiffResult`. Return the `LocalDiffResult` directly instead of interpreting it:

```php
private function analyzeLocalDifferenceCached(
    SplFileInfo $fileA,
    SplFileInfo $fileB,
    array &$imageCache,
): LocalDiffResult {
    // Only analyze still images — videos use duration as the differentiator
    if ($this->mediaTypeClassifier->isVideo($fileA) || $this->mediaTypeClassifier->isVideo($fileB)) {
        // Return a success result with rmse=0 to signal "skip analysis" for videos
        return new LocalDiffResult(0.0, 0.0, 0.0, 0, false, success: true);
    }

    $keyA = $fileA->getPathname();
    $keyB = $fileB->getPathname();

    if (!isset($imageCache[$keyA]) && !array_key_exists($keyA, $imageCache)) {
        $imageCache[$keyA] = $this->imageLoader->loadNormalized($fileA, 512);
    }

    if (!isset($imageCache[$keyB]) && !array_key_exists($keyB, $imageCache)) {
        $imageCache[$keyB] = $this->imageLoader->loadNormalized($fileB, 512);
    }

    $imgA = $imageCache[$keyA];
    $imgB = $imageCache[$keyB];

    if ((!$imgA instanceof Imagick) || (!$imgB instanceof Imagick)) {
        return new LocalDiffResult(0.0, 0.0, 0.0, 0, false, success: false);
    }

    return $this->localDiffAnalyzer->analyzeRmse($imgA, $imgB);
}
```

- [ ] **Step 3: Add `shouldMergePerceptually()`**

```php
/**
 * Multi-stage conservative merge decision for DuplicateLikely pairs.
 *
 * Stage A: isDuplicateLikely pre-filter (caller's responsibility).
 * Stage B1: RMSE-based early-exit zones (safe merge / safe no-merge).
 * Stage B2: Gray-zone blob analysis (Step 2, conditional).
 */
private function shouldMergePerceptually(
    SplFileInfo $fileA,
    SplFileInfo $fileB,
    SimilarityResult $similarity,
    array &$imageCache,
): bool {
    if (!$similarity->isDuplicateLikely()) {
        return false;
    }

    // Videos: preserve current behavior (merge if DuplicateLikely)
    if ($this->mediaTypeClassifier->isVideo($fileA) && $this->mediaTypeClassifier->isVideo($fileB)) {
        return true;
    }

    // Analyze local difference (RMSE)
    $diff = $this->analyzeLocalDifferenceCached($fileA, $fileB, $imageCache);

    // Analysis failure → no merge (conservative, Regel 2)
    if (!$diff->success) {
        return false;
    }

    // Safe merge zone: codec noise
    if ($diff->rmse <= self::SAFE_MERGE_RMSE) {
        return true;
    }

    // Safe no-merge zone
    if ($diff->rmse >= $this->maxMergeRmse) {
        return false;
    }

    // Gray zone: default to no merge until blob analysis is validated (Step 2)
    return false;
}
```

- [ ] **Step 4: Replace inline merge decision in `mergePerceptuallySimilarGroups()`**

Replace lines 552-564:

```php
$shouldMerge = false;

if ($result->isDuplicateLikely()) {
    $shouldMerge = $this->shouldMergePerceptually(
        $representativeByHash[$hashes[$i]],
        $representativeByHash[$hashes[$j]],
        $result,
        $stageBImageCache,
    );
}
```

This removes the `dHash == 0` fast-path entirely.

- [ ] **Step 5: Run tests**

```bash
make test
```

Expected: existing tests green. The regression test from Task 2 should now **pass** (the minimal edit is no longer merged).

- [ ] **Step 6: Commit**

```bash
git add src/Service/HashSubGroupingService.php
git commit -m "fix: extract shouldMergePerceptually with RMSE zones, remove dHash==0 fast-path"
```

---

## Task 6: Deterministic union-find root selection

**Files:**
- Modify: `src/Service/HashSubGroupingService.php`

- [ ] **Step 1: Add deterministic re-rooting after merge loop**

After the pairwise merge loop (after line 575), before the "Build merged groups" section, add:

```php
// Deterministic root selection: re-root each component to the
// lexicographically smallest hash string. This guarantees the same
// cluster identity regardless of comparison order.
for ($i = 0; $i < $count; ++$i) {
    $root     = $this->findRoot($parent, $i);
    $rootHash = $hashes[$root];
    $myHash   = $hashes[$i];

    if ($myHash < $rootHash) {
        $parent[$root] = $i;
    }
}
```

- [ ] **Step 2: Run tests**

```bash
make test
```

Expected: all green.

- [ ] **Step 3: Commit**

```bash
git add src/Service/HashSubGroupingService.php
git commit -m "fix: deterministic union-find root selection for stable cluster identity"
```

---

## Task 7: Unit tests for merge policy

**Files:**
- Modify: `tests/Unit/Service/HashSubGroupingServiceTest.php`

- [ ] **Step 1: Write unit test `duplicateLikely + dhashZero + highRmse => no merge`**

Add to `HashSubGroupingServiceTest.php`. Use a `StubPerceptualHashCalculator` that returns `DuplicateLikely` with `dhashDistance=0`, and create real image files where the RMSE is above `SAFE_MERGE_RMSE`:

```php
#[Test]
public function dhashZeroWithHighRmseDoesNotMerge(): void
{
    // Create two images with dHash=0 but RMSE above safe-merge zone
    // Use the real fixture images from scenario 59
    $dir = $this->createTempDirectory();
    $original = __DIR__ . '/../../../Fixtures/Images/59-minimal-edit-false-merge/2009-07-25_11-27-50-100.jpg';
    $edit003 = __DIR__ . '/../../../Fixtures/Images/59-minimal-edit-false-merge/2009-07-25_11-27-50-100-003.jpg';

    copy($original, $dir . '/original.jpg');
    copy($edit003, $dir . '/edit.jpg');

    // Use a stub calculator that returns DuplicateLikely with dHash=0
    $stub = new StubPerceptualHashCalculator();
    $stub->setResult(new SimilarityResult(
        100, 0, 0, 0.0, 0.0, null,
        SimilarityClassification::DuplicateLikely,
    ));

    $service = $this->createHashSubGroupingServiceWithStub($stub);

    // Create a FileDuplicate with two renames pointing at these files
    // and run apply() — should return true (sub-grouping applied = separate groups)
    $fileDuplicate = $this->createFileDuplicateFromFiles($dir, ['original.jpg', 'edit.jpg']);

    $result = $service->apply(
        $fileDuplicate,
        null,
        null,
        [],
        fn (SplFileInfo $file, string $target): string => $dir . '/' . $target,
    );

    self::assertTrue($result, 'dHash=0 with high RMSE must produce separate subgroups (apply returns true)');
}
```

Note: the exact test setup depends on the existing `createFileDuplicateFromFiles` helper. If it doesn't exist, create one or adapt the pattern from existing tests.

- [ ] **Step 2: Write unit test `duplicateLikely + lowRmse => merge`**

Test that real format conversions (low RMSE) still merge correctly. Use two copies of the same file (identical content = RMSE 0.0):

```php
#[Test]
public function duplicateLikelyWithLowRmseDoMerge(): void
{
    $dir = $this->createTempDirectory();
    $original = __DIR__ . '/../../../Fixtures/Images/59-minimal-edit-false-merge/2009-07-25_11-27-50-100.jpg';

    copy($original, $dir . '/copy1.jpg');
    copy($original, $dir . '/copy2.jpg');

    $stub = new StubPerceptualHashCalculator();
    $stub->setResult(new SimilarityResult(
        100, 0, 0, 0.0, 0.0, null,
        SimilarityClassification::DuplicateLikely,
    ));

    $service = $this->createHashSubGroupingServiceWithStub($stub);
    $fileDuplicate = $this->createFileDuplicateFromFiles($dir, ['copy1.jpg', 'copy2.jpg']);

    $result = $service->apply(
        $fileDuplicate,
        null,
        null,
        [],
        fn (SplFileInfo $file, string $target): string => $dir . '/' . $target,
    );

    self::assertFalse($result, 'Identical content (low RMSE) must merge (apply returns false)');
}
```

- [ ] **Step 3: Run tests**

```bash
docker compose run --rm buildbox .build/bin/phpunit --filter "dhashZero|duplicateLikelyWith" tests/Unit/Service/HashSubGroupingServiceTest.php
```

Expected: both pass.

- [ ] **Step 4: Run full suite**

```bash
make test
```

Expected: all green including the regression test from Task 2.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Service/HashSubGroupingServiceTest.php
git commit -m "test: add unit tests for conservative merge policy (dHash==0, RMSE zones)"
```

---

## Task 8: Verify regression test passes + full backward compatibility

**Files:**
- Read: all test output

- [ ] **Step 1: Run the specific regression test**

```bash
docker compose run --rm buildbox .build/bin/phpunit --filter "59-minimal-edit-false-merge" tests/Integration/TestImageScenariosTest.php
```

Expected: PASS — the three images now stay as separate subgroups.

- [ ] **Step 2: Run full test suite**

```bash
make test
```

Expected: all 651+ tests green. No existing scenario expectations changed.

- [ ] **Step 3: Run on real data to verify no regressions**

```bash
./renamer.sh rename:exif --dry-run /volume1/Fotos/2009
./renamer.sh rename:exif --dry-run /volume1/Fotos/2010
./renamer.sh rename:exif --dry-run '/volume1/Fotos/MobileBackup/Test4/'
```

Verify:
- `/volume1/Fotos/2009` — no circular swap error
- `/volume1/Fotos/2010` — "Nothing to do" (idempotent)
- `Test4/` — three separate subgroups, no `-duplicate-001`

- [ ] **Step 4: Commit all remaining changes if any**

```bash
git status
# If clean: nothing to commit
# If there are adjustments: commit them
```

---

## Task 9: Idempotency proof (second run = no-op)

**Files:**
- None (verification only)

- [ ] **Step 1: Run on Test4 without --dry-run to actually rename**

```bash
./renamer.sh rename:exif '/volume1/Fotos/MobileBackup/Test4/'
```

- [ ] **Step 2: Run again with --dry-run — must be no-op**

```bash
./renamer.sh rename:exif --dry-run '/volume1/Fotos/MobileBackup/Test4/'
```

Expected: `Files to process: 0`, all files `[O]`.

- [ ] **Step 3: Restore original filenames for Test4**

Rename files back to original names so the fixture remains usable.

---

## Success Criteria

- [ ] Regression test `59-minimal-edit-false-merge` passes
- [ ] All existing tests remain green
- [ ] No existing scenario expected mappings changed
- [ ] `/volume1/Fotos/2009` runs without circular swap
- [ ] `/volume1/Fotos/MobileBackup/Test4/` produces three subgroups
- [ ] Second run on renamed files is no-op (zero planned moves)
- [ ] `dHash == 0` fast-path is removed
- [ ] `maxMergeChangedArea` renamed to `maxMergeRmse`
- [ ] `LocalDifferenceAnalyzer` has `analyzeRmse()` + `analyzeDetailed()` methods
- [ ] Union-find roots are deterministic (lexicographically smallest hash)
