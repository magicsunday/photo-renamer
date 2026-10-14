# Visual classification benchmark

This optional offline benchmark compares the current `ContentClassifier` with
additional SSIM and regional-error vetoes. It does not change production merge
rules, cache schemas, file mutation or deletion authority, and is not a CI gate.
The measured comparison and implementation decision are recorded in
[issue #190](https://github.com/magicsunday/photo-renamer/issues/190).

## Run

Docker and the checked-out public regression fixtures are required. Build the
current immutable production runtime first, then capture the JSON report:

```bash
make runtime-build
make --silent visual-comparison-benchmark > .build/visual-comparison.json
```

Progress goes to stderr. A failed decode, metric, worker, resource limit or
cold/warm consistency check fails the command; incomplete output is not a result.
Only scripts and public synthetic regressions 42/59 are mounted read-only. All
new images and signal snapshots live in disposable container tmpfs. No original
collection, host credentials or network is available to the measurement.

## Corpus and labels

The fixed seed is `20261014`. Twelve independently drawn families cover smooth
areas, texture and edges at 512×384 and 1536×1152. Families 00–05 calibrate;
06–11 are held out, including every encoding of their source images. Existing
regressions are reported separately and never select thresholds.

Each family has ten intended backup pairs: identical bytes, JPEG qualities 85/60,
HEIC qualities 70/40, JPEG re-encoding, half-size representation, EXIF orientation,
sRGB tagging and a color-managed Display-P3 representation. The P3 fixture is
checked by a separate ICC round trip before classification. The two tiny profiles
are CC0 data pinned with source references and SHA-256 values in
[`provenance.json`](../scripts/fixtures/icc/provenance.json); their original
[license](../scripts/fixtures/icc/license) is retained. No profile is downloaded at run time.

Sixteen intended non-backup pairs per family cover gray/hue changes, cropping,
physical rotation, local blur/removal/insertion, independently rendered object
movement and 1/4/16/64-pixel edits in both JPEG and HEIC. Unmodified encodings are
pixel-signature controls: an edit completely erased by its encoder is reported as
unobservable and excluded from the observable-negative denominator, without
changing labels in response to classifier scores. These are deliberately difficult
synthetic controls, not a representative sample of a personal media collection.

## Compared measurements

The baseline is actual production source classification, including its exact
content hash, Stage-A signals and mandatory still-image Stage B. Metadata-only
context and whole CLI grouping are not simulated by the pair benchmark; existing
integration tests retain those contracts.

Additional images use the current normalized loader, full decoding, explicit
triangle downsampling and 8-bit Imagick grayscale exports. Working long edges are
256, 512 and 1024 pixels for the reference image, without enlarging that reference.
The counterpart is resized to the reference grid and can therefore be enlarged,
including half-size backups. Resampling can lose very small edits. The baseline
itself retains its production JPEG decode hints.

- Native SSIM/DSSIM uses Gaussian radius 5 (11-pixel window), sigma 1.5 and
  k1/k2 0.01/0.03. The installed API is probed for identity, a changed pixel and
  failure. On the measured ImageMagick API both constants return the same
  normalized distance `(1 − SSIM) / 2`: identical pixels measure zero; failure is
  unavailable. A zero metric alone supplies no byte-identity or deletion proof.
- Global SSIM distance and the maximum distance over non-overlapping 128-pixel
  crops are separate observations. Cropped-window boundaries differ from a
  full-image local map; the returned comparison image is not used as an SSIM map.
- Regional grayscale RMSE uses non-overlapping 32-pixel tiles and reports maximum
  and q99 at each working resolution. Codec noise and tile boundaries can affect
  both values.

For each candidate, calibration chooses the maximum metric of **baseline-accepted
positive calibration pairs**, plus a 1e-12 numerical allowance. Negative, holdout
and regression scores never tune thresholds. Candidate decisions retain every
baseline veto and add only their own veto. One fixed combination uses local SSIM
and maximum regional RMSE at 512; a high score cannot override missing evidence,
chroma/RMSE rejection or any permanent-deletion guard.

## Reading the report

`summary` contains frozen thresholds, label denominators, unobservable exclusions,
false merges, missed backups, additional missed backups and individual error IDs.
`reports` retains every synthetic pair, baseline reason, Stage-A acceptance,
edit size, source resolution and raw measurements for more detailed partitioning.
Profile round-trip error is an independent fixture check, not production evidence.

Cold means the first pass through empty private signal storage; warm uses fresh
family processes that reload that snapshot. Kernel/page caches are not purged.
Stage B and extra measurements still decode images. Per-pair baseline, decode,
preprocessing, regional and SSIM timings are wall time in milliseconds. Worker CPU
is measured separately. Phase durations sum worker loops; generation is separate.
PHP/RSS peaks are the maximum of fresh family workers, not a sum of process RSS.
The cgroup peak includes controller, native allocations and workers. Temporary
file bytes at the end are not a sampled disk peak. Runtime provenance and the
benchmark source digest identify the measured code.

Limits are explicit: container 1536 MiB with no extra swap, one CPU, 128 PIDs,
256 MiB non-executable tmpfs, PHP 768 MiB, native memory/map 128 MiB each and native
disk 64 MiB. Family workers have native time 30 seconds and process timeout
90 seconds; the entire container has 1800 seconds. The generator shares the
1800-second native ceiling. Effective cgroup limits are included; unavailable
kernel counters are reported as unavailable rather than invented.

## Interpretation

Keep production behavior unless a separate decision and acceptance justify a
change. A useful extra veto must reduce relevant false merges without merely
rejecting valid format backups. Examine error IDs and edit-size/codec/resolution
partitions; mean scores alone are insufficient. These fixed-pair timings do not
predict throughput or capacity for arbitrary 70,000-file collections.

PDQ is a candidate hash, not a replacement for local evidence or deletion proof.
Only investigate it for a demonstrated relevant candidate-recall gap. LPIPS needs
an evidenced benefit over classical local analysis before introducing a separate
offline model stack. Neither dependency belongs in the production runtime merely
because the benchmark can expose an aggregate-metric limitation.

References: [SSIM authors](https://www.cns.nyu.edu/~lcv/ssim/),
[ImageMagick metric options](https://imagemagick.org/command-line-options/#metric),
[measured native implementation](https://github.com/ImageMagick/ImageMagick/blob/7.1.2-30/MagickCore/compare.c),
[PDQ reference](https://github.com/facebook/ThreatExchange/blob/main/pdq/README.md),
[LPIPS research](https://richzhang.github.io/PerceptualSimilarity/).
