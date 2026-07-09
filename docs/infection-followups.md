# Infection follow-ups

## Timeout and skipped mutant review

The Infection report generated after enabling the full mutation suite showed
16 timed-out mutants, 27 skipped mutants, 1436 escaped mutants, and a Covered
Code MSI of 66% at a 30 second timeout.

After the follow-up tests, local Infection runs reported a stable Covered Code
MSI of 68%. The first validation run generated 4320 mutants, killed 2832,
left 1316 escaped, timed out 8, and skipped 164. The second run, with
`minCoveredMsi` already raised to 68, generated 4320 mutants, killed 2883,
left 1368 escaped, timed out 29, and skipped 40. After extracting the directly
tested helpers into public helper classes, the final validation run generated
4322 mutants, killed 2852, left 1302 escaped, timed out 6, and skipped 162.

The timeout/skipped split is not stable between runs because Infection stops
individual mutants once they exceed the configured runtime budget. The Covered
Code MSI stayed stable at 68%, so the threshold was raised only to that
reproduced value.

Timed-out mutants are concentrated in expensive media-processing paths:

- `RenameByExifDateCommand` setup and cleanup paths around cache flushing,
  perceptual calculator wiring, and media extension regex generation.
- `HashSubGroupingService::findRoot()` path-compression logic.
- `PerceptualHashCalculator` bit packing and bit counting loops.

The command-level timeouts are expected to be high-cost integration paths
because the covering tests execute real command pipelines with media fixtures.
No production behavior should be changed solely to make these mutants faster.

The hash-grouping and perceptual-hash timeouts represent pure algorithmic
boundaries. They are now covered by focused unit tests for path compression,
bit packing, weighted score boundaries, strict hex handling, and bit counting.

Skipped mutants are currently concentrated in code that already has direct
behavioral assertions but is skipped by Infection because the mutation takes
longer than configured for the covering tests. The follow-up tests exercise
cache root resolution plus timezone and threshold precedence so future
Infection runs can distinguish equivalent visibility/constructor mutations
from observable configuration regressions.

The timeout remains at 30 seconds. Raising it would hide slow mutants without
improving the mutation signal.
