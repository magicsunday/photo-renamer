# Calibration Results — Scenario 59

Measured 2026-04-01 against real failure-case images from `/volume1/Fotos/MobileBackup/Test4/`.

## RMSE Measurements

| Pair | RMSE | Interpretation |
|------|------|---------------|
| Original vs -002 (heavy edit) | 0.189985 | Clearly different — well above any merge threshold |
| Original vs -003 (minimal edit) | 0.042753 | Gray zone (0.015 < x < 0.05) — current code merges (wrong) |
| -002 vs -003 | 0.184855 | Clearly different |

## Perceptual Hash Scores

| Pair | Score | dHash | Classification |
|------|-------|-------|---------------|
| Original vs -003 | 99 | 1 | DuplicateLikely |
| Original vs -002 | 89 | 2 | EditedVariant |

## Key Findings

- **dHash is NOT 0** for the minimal edit (dHash=1). The `dHash==0` fast-path is not the direct trigger for this bug.
- **RMSE 0.043** falls between SAFE_MERGE_RMSE (0.015) and maxMergeRmse (0.05) — the gray zone.
- Current code merges at RMSE < 0.05 → 0.043 < 0.05 → merge (wrong).
- With the new conservative policy (gray zone → no merge), this case is correctly separated.
- Step 1 alone (RMSE zones without blob) fixes this specific failure case.
- Step 2 (blob analysis) is a hardening improvement, not mandatory for this case.

## Threshold Validation

- SAFE_MERGE_RMSE = 0.015 safely covers codec noise (documented range 0.001–0.013)
- The failure case (0.043) sits well above 0.015 — no risk of the safe-merge zone absorbing it
- A simpler fix (lowering maxMergeRmse to 0.04) would also work for this specific case
