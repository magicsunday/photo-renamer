# Perceptual Hash Duplicate Detection — Architecture & Empirical Analysis

## Context

This document describes the duplicate detection challenges in a photo/video renamer CLI tool that organizes media files by EXIF timestamp. When multiple files share the same capture timestamp, the tool must decide:

- **Duplicate** (`-duplicate-NNN`): Same capture, different encoding/format → treat as copy
- **Sub-group** (`-002`, `-003`): Different content that coincidentally shares a timestamp → keep separate

The tool processes collections of 10,000–70,000+ files across nested directory structures (year → event → subfolder). Files come from iPhones, DSLRs, photo studios, WhatsApp, cloud sync, and manual imports.

## Pipeline Overview (2-Stage Architecture)

```
1. Group files by EXIF timestamp (target basename)
2. Compute content hash (xxh128) for each file
3. If all hashes identical → pure duplicates → -duplicate-NNN
4. If multiple hash groups:
   ┌─ Stage A: Global multi-signal scoring (all pairs) ─────────────┐
   │  dHash + wHash + HF-energy + color histogram + video duration  │
   │  → Score 0–100 per pair                                        │
   │  score < 85 → different content → -002, -003                   │
   │  score 85–94 → edited variant → -002, -003                     │
   │  score ≥ 95 → near-identical candidate → proceed to Stage B    │
   └────────────────────────────────────────────────────────────────┘
   ┌─ Stage B: Local difference analysis (only score ≥ 95 pairs) ──┐
   │  Downscale to 1024px, pixel diff, threshold, blob analysis     │
   │  scattered noise (no blobs) → duplicate → -duplicate-NNN       │
   │  compact blob(s) → local retouch → -002, -003                 │
   │  ambiguous → directory context tiebreaker                      │
   └────────────────────────────────────────────────────────────────┘
```

Stage A runs on all pairs with different content hashes (typically <1% of collection). Stage B is an optional refinement that only triggers for very-high-score pairs — typically <5 per run. This 2-stage design keeps the pipeline fast while addressing the fundamental limit of global perceptual hashes for minimal local retouches.

## Problem Cases (Categorized)

### Category A: Format Backups (should be duplicates)

**Pattern:** Same capture saved in different formats by the device or sync software.

| Case | Files | Challenge |
|------|-------|-----------|
| A1: JPG + HEIC | iPhone saves both formats | Different binary content, different color profiles (sRGB vs Display P3), different EXIF orientation encoding |
| A2: Re-import from phone | Same video, re-transferred via USB/AirDrop | Different encoding parameters, slightly different file size (75 MB vs 66 MB) |
| A3: Cloud sync copy | Same photo synced via iCloud/Google Photos | May be re-compressed, different JPEG quality |
| A4: WhatsApp forward | Photo sent via messenger | Heavily re-compressed, metadata stripped, resolution reduced |

**Key insight:** These files look visually identical to a human but have different byte content.

### Category B: Edits & Retouches (should NOT be duplicates)

**Pattern:** A photo studio or user edited the original — the result is visually similar but not identical.

| Case | Files | Challenge |
|------|-------|-----------|
| B1: Minimal local retouch | Original + license plate retouched | Only a few pixels changed (~0.5% of area). ALL perceptual signals report near-zero difference. |
| B2: Color grading / LUT | Original + Lightroom-exported version | Significant color shift visible in histogram, but spatial structure unchanged |
| B3: Studio skin smoothing | Original + beauty-retouched version | Mid-frequency textures changed (skin smoothing, noise reduction), global structure identical |
| B4: Crop/resize | Original + cropped version | Composition partially preserved, aspect ratio may change |
| B5: Panorama stitch | Source frames + stitched result | Completely different image, same EXIF timestamp |

**Key insight:** These files share the same scene/composition but differ in texture detail, color grading, or framing. The difficulty varies enormously — B1 is undetectable by any pixel-based method, B3 is detectable by wavelet/HF analysis, B2 is detectable by color histogram.

### Category C: Same-Second Coincidence (should NOT be duplicates)

**Pattern:** Two genuinely different captures that happened to occur in the same second.

| Case | Files | Challenge |
|------|-------|-----------|
| C1: Burst photos | Rapid-fire captures at 10fps | Different composition, same second-level timestamp (subsecond differs) |
| C2: Multiple cameras | Two photographers shoot the same event | Different angles, same timestamp |
| C3: Video + photo | Video recording + photo taken during recording | Same second, completely different content |

**Key insight:** These are visually distinct and correctly separated by any perceptual hash. Not a problem case.

### Category D: Cross-Directory Interactions

**Pattern:** Same timestamp appears in files across different subdirectories.

| Case | Files | Challenge |
|------|-------|-----------|
| D1: Original + edits in subfolder | `Studio/photo.jpg` + `Studio/edited/photo.jpg` | Same timestamp, same software (all Photoshop), but different edits |
| D2: Same-dir duplicates + cross-dir copy | `root/a.jpg` + `root/b.jpg` (dups) + `backup/a.jpg` (copy) | Mixed group: some are duplicates, some are cross-dir copies |
| D3: Multiple members in same subdir | Two files with different hashes in same subfolder | Cross-dir no-conflict logic must not give both the unsuffixed name → collision |

**Key insight:** Directory structure provides semantic context (originals vs edits) but cannot be the sole decision factor.

## Empirical Signal Analysis

All measurements were taken on real-world photo/video files from the production collection. The test set covers all problem categories.

### Measurement Setup

| Pair | Description | Expected Classification |
|------|-------------|----------------------|
| HEIC Backup | `2025-11-15_20-26-50-647.jpg` ↔ `.heic` (iPhone 15, same capture) | duplicate |
| iPhone Dup | `2024-09-21_17-02-07-833.jpg` ↔ `-duplicate-001.jpg` (same dir, same device) | duplicate |
| Foto Edit002 | Fotostudio original ↔ edit with license plate retouched (Photoshop 7.0, 2009) | edited variant |
| Foto Edit003 | Fotostudio original ↔ edit with color grading + retouch (Photoshop 7.0, 2009) | edited variant |
| Video ReImport | `2025-11-15_16-49-51-000.mov` (75 MB) ↔ re-imported copy (66 MB), Duration 39.6s both | duplicate |
| Video Edit | Same video original ↔ trimmed version (39.6s → 20.2s) | edited variant |

### Signal 1: Content Hash (xxh128)

All pairs have different content hashes (that's why they enter the perceptual comparison pipeline).

- **Detects:** Exact byte-identical copies
- **Misses:** Everything else
- **Cost:** ~1ms per file, cached
- **Verdict:** Necessary first-pass filter. Already implemented.

### Signal 2: dHash (Difference Hash)

Compares horizontal brightness gradients in a downsampled grayscale image.

**Measured distances at multiple resolutions (Imagick with autoOrient + sRGB normalization):**

| Pair | 8×8 (64 bit) | 16×16 (256 bit) | 32×32 (1024 bit) | 64×64 (4096 bit) | 128×128 (16384 bit) |
|------|-------------|----------------|-----------------|-----------------|-------------------|
| HEIC Backup | 0/64 (0%) | 0/256 (0%) | 2/1024 (0.2%) | 10/4096 (0.2%) | — |
| iPhone Dup | 0/64 (0%) | 0/256 (0%) | 1/1024 (0.1%) | 5/4096 (0.1%) | — |
| Foto Edit002 (plate) | 2/64 (3.1%) | 2/256 (0.8%) | 6/1024 (0.6%) | 28/4096 (0.7%) | 87/16384 (0.5%) |
| Foto Edit003 (grading) | 6/64 (9.4%) | 29/256 (11.3%) | 102/1024 (10.0%) | 406/4096 (9.9%) | 1712/16384 (10.4%) |
| Video ReImport | 5/64 (7.8%) | — | — | — | — |
| Video Edit | 5/64 (7.8%) | — | — | — | — |

**Findings:**
- At standard 8×8 (64 bit): iPhone/HEIC produce distance 0 ✓. But Foto Edit002 only distance 2 and Video ReImport/Edit both distance 5 — indistinguishable.
- At higher resolutions: Edit002 remains <1% different (license plate is too small a region). Edit003 is consistently ~10%.
- Videos: poster frame at 1s is identical in both original and trimmed version → dHash cannot distinguish.
- **Verdict:** Good for format conversions and re-encodes. Useless for minimal local retouches (B1) and trimmed videos.

**Critical: Color space normalization is mandatory.** Without `autoOrient()` + `transformImageColorspace(SRGB)` via Imagick, JPG↔HEIC distance is **35** (garbage). See "Color Space Normalization" section.

### Signal 3: wHash (Haar Wavelet Hash, 64-bit)

Uses 2-level Haar wavelet decomposition on a grayscale matrix, thresholds the LL2 subband against median.

**Measured distances (64-bit output, input sizes 32×32 through 512×512):**

| Pair | 32×32 | 64×64 | 128×128 | 256×256 | 512×512 |
|------|-------|-------|---------|---------|---------|
| HEIC Backup | 0/64 | 0/64 | 0/64 | 0/64 | 0/64 |
| iPhone Dup | 0/64 | 0/64 | 0/64 | 0/64 | 0/64 |
| Foto Edit002 (plate) | **0/64** | **0/64** | **0/64** | **0/64** | **0/64** |
| Foto Edit003 (grading) | 14/64 | 12/64 | 14/64 | 14/64 | 12/64 |

**With 256-bit output (16×16 LL subband):**

| Pair | 128×128 → 256bit | 256×256 → 256bit | 512×512 → 256bit |
|------|-------------------|-------------------|-------------------|
| Foto Edit002 | 4/256 (1.6%) | 2/256 (0.8%) | 2/256 (0.8%) |
| Foto Edit003 | 42/256 (16.4%) | 40/256 (15.6%) | 44/256 (17.2%) |

**Findings:**
- wHash is **resolution-invariant**: 32×32 through 512×512 input produce the same distances. The information is captured by the LL subband which is always reduced to 8×8 or 16×16.
- Edit003 (color grading): wHash distance ~14/64 (21.9%) — clearly separable ✓
- Edit002 (license plate only): **wHash distance 0/64 at all resolutions**. The Haar wavelet averages over large spatial regions, so a small local change (license plate) disappears completely.
- **Verdict:** Excellent for detecting color grading and extensive retouching (B2, B3). Cannot detect minimal local edits (B1).
- **32×32 input is sufficient** — higher resolution costs more without information gain.

### Signal 4: High-Frequency Energy Delta

Computes the mean absolute difference between original and Gaussian-blurred (σ=1.2) versions. Higher values = more texture/noise.

**Measured values:**

| Pair | HF-A | HF-B | |Delta| @128 | @256 | @512 |
|------|------|------|------------|------|------|
| HEIC Backup | 0.0456 | 0.0456 | 0.000021 | 0.000020 | 0.000026 |
| iPhone Dup | 0.0491 | 0.0491 | 0.000017 | 0.000018 | 0.000024 |
| Foto Edit002 | 0.0688 | 0.0706 | **0.001809** | 0.001818 | 0.001151 |
| Foto Edit003 | 0.0688 | 0.0656 | 0.003175 | **0.007692** | **0.008360** |

**Findings:**
- True duplicates (HEIC, iPhone): delta ~0.00002 — virtually zero
- Edit002 (license plate): delta 0.0018 — **90× more than duplicates**, but still very small in absolute terms
- Edit003 (color grading): delta 0.008 — **400× more than duplicates**
- **Verdict:** Useful secondary signal. Can differentiate duplicates (0.00002) from edits (0.002+) but requires careful threshold tuning. Edit002 falls in an ambiguous zone.

### Signal 5: Color Histogram Distance (L1)

Computes 3D RGB histogram (16 bins per channel = 4096 buckets) and measures L1 distance (normalized to 0–1).

**Measured values:**

| Pair | RGB 8-bin | RGB 16-bin | RGB 32-bin |
|------|-----------|------------|------------|
| HEIC Backup | 0.003 | 0.008 | 0.016 |
| iPhone Dup | 0.003 | 0.006 | 0.013 |
| Foto Edit002 (plate) | 0.006 | **0.007** | 0.009 |
| Foto Edit003 (grading) | 0.221 | **0.294** | 0.375 |
| Video ReImport | — | **0.656** | — |
| Video Edit | — | **0.656** | — |

**Note:** LAB and RGB histograms produced identical values in practice (Imagick's LAB conversion may normalize differently).

**Findings:**
- True duplicates: 0.003–0.016 depending on bin count
- Edit002 (license plate): **0.007 — indistinguishable from duplicates!** Changing a license plate doesn't shift the color distribution.
- Edit003 (color grading): **0.294 — clearly different** ✓
- Videos (both pairs): 0.656 — very high, likely due to ffmpeg poster frame color space not being normalized (only Imagick normalizes properly, but ffmpeg is used for video frame extraction)
- **Verdict:** Excellent for detecting color grading (B2, B4). Cannot detect minimal local retouches (B1). Video color histograms are unreliable due to ffmpeg color space issues.

### Signal 6: Video Duration Match

Compares video duration from metadata.

| Pair | Duration A | Duration B | Delta |
|------|-----------|-----------|-------|
| Video ReImport | 39.6s | 39.6s | **0.0s** |
| Video Edit | 39.6s | 20.2s | **19.4s** |

**Findings:**
- Duration is a **definitive signal** for trimmed videos. Zero cost (already in metadata).
- **Verdict:** Must-have for video pairs. Combined with poster-frame dHash, it cleanly separates re-imports from edits.

## Combined Scoring Model

### Normalization

Each signal is normalized to a 0–1 similarity:

```
sim_dhash = 1 − (distance / 64)          # 64-bit dHash
sim_whash = 1 − (distance / 64)          # 64-bit wHash
sim_hf    = 1 − min(1, hf_delta / 0.15)  # HF typical range 0–0.15
sim_color = 1 − min(1, color_delta)       # Histogram distance already 0–1
sim_dur   = 1 − min(1, dur_delta / 30)    # 30s tolerance for videos
```

### Weights

**For still images (no duration available):**
```
score = 0.30 × sim_dhash + 0.25 × sim_whash + 0.20 × sim_hf + 0.25 × sim_color
```

**For videos (duration available):**
```
score = 0.25 × sim_dhash + 0.20 × sim_whash + 0.15 × sim_hf + 0.10 × sim_color + 0.30 × sim_dur
```

Final score is `round(score × 100)`, range 0–100.

### Measured Scores

| Pair | dH | wH | HF-Δ | Color | Dur-Δ | **Score** | Expected | Correct? |
|------|----|----|------|-------|-------|-----------|----------|----------|
| HEIC Backup | 0 | 0 | 0.000 | 0.008 | — | **100** | duplicate | ✓ |
| iPhone Dup | 0 | 0 | 0.000 | 0.006 | — | **100** | duplicate | ✓ |
| Foto Edit002 | 2 | 0 | 0.002 | 0.007 | — | **99** | edited | ✗ |
| Foto Edit003 | 6 | 14 | 0.003 | 0.294 | — | **84** | edited | ✓ |
| Video ReImport | 5 | 2 | 0.001 | 0.656 | 0.0 | **91** | duplicate | ✓ |
| Video Edit | 5 | 2 | 0.001 | 0.656 | 19.4 | **54** | edited | ✓ |

### Stage A Classification

```
Score ≥ 95  → near_identical_candidate → proceed to Stage B
Score 85–94 → edited_variant           → sub-group (-002, -003)
Score < 85  → different                → sub-group (-002, -003)
```

Stage A correctly classifies 5 of 6 cases. Edit002 (score 99) passes to Stage B for local analysis.

## Stage B: Local Difference Analysis

### Why Stage B Is Needed

Global perceptual hashes compress the entire image into 64–1024 bits. Any local change affecting <1% of pixels is absorbed by the averaging. This is a **fundamental limit of the signal class**, not a tuning problem.

Edit002 demonstrates this: a retouched license plate (~0.5% of image area) produces identical dHash, wHash, color histogram, and near-identical HF-energy. No adjustment of weights or thresholds can separate it from true duplicates without also breaking real format-backup detection.

### The Key Insight: Noise Pattern vs Retouch Pattern

When two visually-near-identical images differ at the byte level:

- **JPEG re-encode / format conversion:** Differences are fine-grained, evenly distributed across the image (DCT compression artifacts). No spatial structure to the diff.
- **Local retouch:** Differences form a **compact, spatially coherent blob** — the retouched region. Surrounding pixels are nearly identical.

This qualitative difference is detectable by analyzing the **spatial structure** of the pixel difference mask.

### Algorithm: LocalDifferenceAnalyzer

Input: Two Imagick images that scored ≥ 95 in Stage A.

```
1. Normalize both images (autoOrient, sRGB, strip, flatten)
2. Downscale both to 1024px on the long edge (proportional)
3. Convert both to grayscale (COLORSPACE_GRAY)
4. Compute absolute pixel difference: diff[y][x] = |A[y][x] - B[y][x]|
5. Apply noise threshold: binary[y][x] = (diff[y][x] > 6/255) ? 1 : 0
   This eliminates JPEG rounding noise (typically ±2-3 levels)
6. Morphological opening (erode + dilate) to remove isolated pixels
7. Connected component analysis on the binary mask
8. Measure:
   - changedAreaRatio  = count(binary == 1) / totalPixels
   - largestBlobArea   = area of largest connected component
   - largestBlobRatio  = largestBlobArea / totalPixels
   - blobCount         = number of connected components (after morphology)
```

### Decision Rule

```
IF changedAreaRatio < 0.002 AND largestBlobRatio < 0.0005:
    → duplicate (pure re-encode noise, no coherent changes)

IF largestBlobRatio >= 0.001:
    → edited_variant (compact local retouch detected)

IF changedAreaRatio >= 0.002 AND largestBlobRatio < 0.001:
    → ambiguous (widespread small changes, no clear blob)
    → use directory context as tiebreaker:
      - same directory → duplicate
      - different directory with edit-semantic name → edited_variant
      - different directory without edit-semantic name → duplicate
```

### Edit-Semantic Directory Names

Directory names that suggest post-processing (case-insensitive substring match):

```
bearbeitet, edited, edit, edits, export, exported, final, retouched,
retouch, processed, adjusted, corrected, lightroom, photoshop, print
```

### Expected Results with Stage B

| Pair | Stage A Score | Changed Area | Largest Blob | Classification |
|------|--------------|-------------|-------------|----------------|
| HEIC Backup | 100 | <0.001 (JPEG noise) | <0.0001 | duplicate ✓ |
| iPhone Dup | 100 | 0.000 | 0.000 | duplicate ✓ |
| Foto Edit002 (plate) | 99 | ~0.005 | **~0.003** (plate blob) | **edited_variant** ✓ |
| Video ReImport | 91 | — (below threshold) | — | duplicate ✓ (Stage A) |

Edit002 is now detectable: the license plate retouch creates a compact blob (~0.3% of image area) that stands out against the near-zero noise floor of the surrounding pixels.

### Performance Considerations

Stage B is expensive compared to Stage A:
- Imagick load + resize to 1024px: ~100ms per image
- Pixel export (1024×683 = 700K pixels): ~50ms
- Difference computation + morphology + blob analysis: ~20ms
- Total per pair: ~300ms

But it only runs for pairs with `score ≥ 95` and different content hashes — typically 0–5 pairs per run of 3000+ files. Total overhead: <2 seconds.

### Morphological Operations in Imagick

Imagick provides built-in morphological operations:

```php
// Erode then dilate (opening) to remove isolated noise pixels
$kernel = ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, '1');
$diffImg->morphology(Imagick::MORPHOLOGY_OPEN, 1, $kernel);
```

Connected component analysis can be done via `Imagick::connectedComponentsImage()` (available since ImageMagick 7).

### CLI Flags for Ambiguous Cases

When Stage B produces an ambiguous result, the user can control behavior:

```
--duplicate-preference=strict   # Only merge when Stage B confirms (default)
--duplicate-preference=relaxed  # Merge all score ≥ 95, skip Stage B
```

This avoids hardcoded directory penalties and gives the user control.

## Fundamental Limit: Minimal Local Retouches (Case B1)

Edit002 is the original photo with **only the license plate retouched** — approximately 0.5% of the image area. The edit was done in Photoshop 7.0 (2009) and the JPEG was re-saved.

**Why no pixel-based signal can detect this:**

| Signal | Edit002 | True Duplicate | Ratio |
|--------|---------|---------------|-------|
| dHash 8×8 | 2/64 (3.1%) | 0/64 | — |
| wHash 64-bit | **0/64** (all resolutions, all inputs 32–512) | 0/64 | **identical** |
| HF-Energy | 0.0018 | 0.00002 | 90× |
| Color Histogram | 0.007 | 0.006 | 1.2× |
| Imagick MSE | 0.0018 | 0.0 | — |

The license plate is a tiny region. At the 8×8 level (dHash) it falls into 1–2 cells. At the wavelet level, the Haar transform averages over 4×4 blocks minimum, completely absorbing the local change. The color histogram doesn't shift because the plate colors are a negligible fraction of the total distribution. Even the HF-energy delta (0.0018 vs 0.00002) is close enough to be within noise range for some JPEG re-saves.

**Conclusion:** For minimal local retouches where the affected area is <1% of the image, the only reliable detection methods are:
1. **Full-resolution pixel comparison** (MSE or SSIM at native resolution) — expensive for large images
2. **Non-visual metadata signals**: different directory (original vs `bearbeitet/`), different file modification date, different file size
3. **Accept as duplicate** — the files are visually identical to any human observer at normal viewing distance

### Recommendation for B1 Cases

Use directory structure as a tiebreaker: if the multi-signal score is in the "duplicate" range (≥85) but the files reside in **different directories**, apply a penalty (e.g., -10 points). This captures the semantic intent of a `bearbeitet/` subfolder without requiring pixel-level detection of micro-edits.

## Color Space Normalization (Critical)

The single most impactful technical finding from the empirical analysis.

### The Problem

iPhones save HEIC in Display P3 color space and JPG in sRGB. When extracting grayscale pixels:

- **ffmpeg:** Does not automatically convert color profiles. HEIC pixels have completely different luminance values than JPG pixels of the same image. dHash distance: **35** (useless).
- **ffmpeg with `colorspace=all=bt709` filter:** Fails on some JPG input (no recognized source colorspace → error).
- **ffmpeg with `-apply_trc bt709`:** No effect on HEIC pixels.
- **Imagick with proper pipeline:** Handles ICC profiles correctly. dHash distance: **0** (correct).

### The Orientation Problem

iPhones store JPG in landscape orientation with EXIF rotation flag 6 (rotate 90° CW), but HEIC is stored already physically rotated (orientation 1). Without `autoOrient()` before pixel extraction:
- JPG dimensions: 5712×4284 (needs rotation)
- HEIC dimensions: 4284×5712 (already correct)
- dHash distance: **24** (comparing portrait vs landscape = garbage)

### Solution

Use Imagick for all still-image pixel extraction with this mandatory normalization:

```php
$img->readImage($path);
$img->autoOrient();           // Apply EXIF rotation to pixels BEFORE stripping
$img->stripImage();           // Remove ICC profiles and metadata
$img->transformImageColorspace(Imagick::COLORSPACE_SRGB);  // Normalize to sRGB
$img->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
$img->setBackgroundColor('white');
$img->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
```

**Order matters:** `autoOrient()` MUST come before `stripImage()`. Stripping first removes the orientation tag, so the rotation is never applied.

Use ffmpeg only for video poster frame extraction (Imagick cannot decode video), then load the extracted JPEG frame through Imagick for normalization.

## Implementation Architecture

### Signal Computation Classes

```
PerceptualHashCalculatorInterface
├── computeDhash(SplFileInfo): ?string          # 64-bit dHash (hex)
├── computeWhash(SplFileInfo): ?string          # 64-bit wHash (hex)
├── computeHfEnergy(SplFileInfo): ?float        # HF-energy scalar
├── computeColorHistogram(SplFileInfo): ?array   # Normalized RGB histogram
├── hammingDistance(string, string): int          # Hamming distance for hex hashes
├── similarityScore(SplFileInfo, SplFileInfo): SimilarityResult  # Combined multi-signal score
└── clearCache(): void
```

### Image Loading

```
ImagickImageLoaderInterface
├── loadNormalized(SplFileInfo): ?Imagick       # Full normalization pipeline
└── loadVideoPosterFrame(SplFileInfo): ?Imagick  # ffmpeg frame → Imagick

Normalization pipeline (still images):
  readImage → autoOrient → stripImage → sRGB → removeAlpha → flatten

Video pipeline:
  ffmpeg -ss 1 -i video -frames:v 1 poster.jpg → loadNormalized(poster.jpg) → cleanup
```

### Integration Point

In `HashSubGroupingService::apply()`, after content-hash grouping:

```php
// Step 1: Content hash groups
$hashGroups = [...];  // hash → list of Rename

// Step 2: If multiple hash groups, compute pairwise similarity
$hashGroups = $this->mergePerceptuallySimilarGroups($hashGroups);

// Step 3: If merged to single group → all duplicates
if (count($hashGroups) <= 1) {
    return false;  // No sub-grouping needed
}

// Step 4: Proceed with sub-group numbering for remaining distinct groups
```

The merge uses union-find: for each pair of hash groups, compute the multi-signal similarity score of representative files. If score ≥ threshold, merge the groups.

### SimilarityResult Value Object

```php
final readonly class SimilarityResult {
    public function __construct(
        public int $score,              // 0–100 combined score
        public int $dhashDistance,       // 0–64
        public int $whashDistance,       // 0–64
        public float $hfEnergyDelta,    // 0.0–1.0
        public float $colorDistance,     // 0.0–1.0
        public ?float $durationDelta,   // seconds (null for images)
        public string $classification,  // 'duplicate_likely' | 'edited_variant' | 'different'
    ) {}
}
```

## File Structure

```
src/Service/PerceptualHash/
├── PerceptualHashCalculatorInterface.php   # Public API: hashes, scoring, similarity
├── PerceptualHashCalculator.php            # All hash computations + Stage A scoring
├── ImagickImageLoaderInterface.php         # Imagick normalization contract
├── ImagickImageLoader.php                  # autoOrient + sRGB + flatten + video poster
├── LocalDifferenceAnalyzerInterface.php    # Stage B: local diff contract
├── LocalDifferenceAnalyzer.php             # Blob analysis for near-identical pairs
├── SimilarityResult.php                    # Value object: score + all metrics + classification
├── LocalDiffResult.php                     # Value object: changedAreaRatio, blobRatio, blobCount
├── FfmpegGrayscaleLoaderInterface.php      # ffmpeg-only fallback (kept for video frames)
└── FfmpegGrayscaleLoader.php               # Video poster frame extraction via ffmpeg

src/Service/
├── HashSubGroupingService.php              # mergePerceptuallySimilarGroups() with 2-stage decision
└── DuplicateDetectionService.php           # Orchestrator

tests/Unit/Service/PerceptualHash/
├── PerceptualHashCalculatorTest.php
├── ImagickImageLoaderTest.php
├── LocalDifferenceAnalyzerTest.php
└── FfmpegGrayscaleLoaderTest.php

tests/Unit/Service/Fixtures/
└── StubPerceptualHashCalculator.php
```

## Technical Environment

- **Language:** PHP 8.5, PHPStan max level, PHPUnit 12
- **Docker:** Alpine Linux, php:8.5-cli-alpine
- **Available tools:** ffmpeg, ffprobe, exiftool, ImageMagick CLI
- **PHP extensions:** Imagick (php85-pecl-imagick), GD (php85-gd), PCOV
- **Hash library:** Pure PHP (no external pHash/wHash library needed)
- **Process execution:** `Symfony\Component\Process\Process` for ffmpeg
- **Reference implementation:** Full pHash/dHash/aHash in sister project `photo-memories` (`PerceptualHashExtractor.php`)

## Implementation Phases

### Phase 1: ImagickImageLoader (foundation)

Replace `FfmpegGrayscaleLoader` with Imagick-based pixel extraction for still images. This fixes the critical JPG↔HEIC color space and orientation problems. Keep ffmpeg for video poster frame extraction only.

**New files:**
- `ImagickImageLoaderInterface.php` — `loadNormalized(SplFileInfo): ?Imagick`
- `ImagickImageLoader.php` — autoOrient + stripImage + sRGB + flatten + video poster via ffmpeg

**Modify:**
- `PerceptualHashCalculator.php` — use `ImagickImageLoader` instead of `FfmpegGrayscaleLoader` for pixel data
- `Services.yaml` — wire new interfaces

### Phase 2: Stage A multi-signal scoring

Add wHash, HF-energy, color histogram, and video duration to the hash calculator. Combine into weighted score.

**New/modify:**
- `PerceptualHashCalculatorInterface.php` — add `computeWhash()`, `computeHfEnergy()`, `computeColorHistogram()`, `similarityScore()`
- `PerceptualHashCalculator.php` — implement all signals + scoring formula
- `SimilarityResult.php` — value object with all metrics + score + classification
- `HashSubGroupingService.php` — use `similarityScore()` in `mergePerceptuallySimilarGroups()`
- `StubPerceptualHashCalculator.php` — update with new methods

### Phase 3: Stage B local difference analysis

Add `LocalDifferenceAnalyzer` for near-identical pairs (score ≥ 95). Runs only on the <5 pairs per run that Stage A cannot decide.

**New files:**
- `LocalDifferenceAnalyzerInterface.php` — `analyze(Imagick, Imagick): LocalDiffResult`
- `LocalDifferenceAnalyzer.php` — downscale, diff, threshold, morphology, blob analysis
- `LocalDiffResult.php` — changedAreaRatio, largestBlobRatio, blobCount

**Modify:**
- `HashSubGroupingService.php` — call Stage B for `score ≥ 95` pairs, use blob results + directory context for final decision

### Phase 4: Validation and threshold calibration

- Fix test images (visually distinct images for edit scenarios)
- Run against real photo library (Fotostudio, iPhone, MobileBackup, video cases)
- Calibrate thresholds empirically
- Add `--duplicate-preference` CLI flag for ambiguous cases

### Nice-to-Have (Future)

- **Multi-frame video sampling** — Sample at 10%, 50%, 90% of duration for more robust video comparison
- **Calibration with larger dataset** — 50 known duplicates, 50 known edits, 50 burst photos
- **Feature matching (ORB/SIFT)** — For robust local edit detection without resolution dependency. Overkill for current use case but the cleanest theoretical solution.
