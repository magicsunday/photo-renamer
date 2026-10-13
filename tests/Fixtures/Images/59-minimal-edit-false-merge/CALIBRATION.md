# Calibration — minimal edits and format backups

Measurements use the existing normalized Imagick loader at a 512-pixel working resolution. These fixture-specific values are regression evidence, not universal codec or retouch thresholds.

## Current fixture measurements

| Scenario and pair | Grayscale RMSE | Mean chroma difference | Expected behavior |
|---|---:|---:|---|
| 59: minimal edit vs original | 0.036854 | 0.000000 | Separate subgroups; exceeds the near-gradient RMSE cap of 0.03. |
| 59: heavy edit vs minimal edit | 0.097513 | 0.454051 | Separate subgroups. |
| 59: heavy edit vs original | 0.090866 | 0.454051 | Separate subgroups. |
| 42: HEIC/JPG format backup | 0.020514 | 0.012703 | Format duplicate within its applicable safe window. |

The integration scenarios assert the resulting filenames and file counts. Stage B remains mandatory for still-image candidates even when dHash distance is zero. `ContentClassifier` owns the safe caps and chroma veto; user configuration cannot widen these internal caps.

## Interpretation limits

RMSE and mean chroma are aggregate measurements. A small edit may fall below an aggregate threshold; these results do not establish complete local-edit detection. Evaluation of another signal requires labeled codec-conversion and edited-image cases, including negative controls, before changing automatic classification.

The pre-removal detailed blob implementation flagged scenario 42 as a compact retouch with a largest changed region of 0.121445 (about 12% of the image). Using that flag as an automatic veto would reject this expected format backup. This counterexample justifies not activating that uncalibrated rule; it does not prove that every spatial-difference method is inferior.

Perceptual classification is independent of permanent-deletion authority. Distinct bytes cannot pass the fresh SHA-256 equality requirement for deletion.
