# Cross-group video planning benchmark

Issue #127 replaces the materialized pair list with lazy deterministic comparison and a finite work budget. This experiment isolates planning/storage and the actual stream fingerprint cache using synthetic exact duplicates. It does not model the diversity, decoding cost or throughput of a large personal collection.

Run `make comparison-benchmark` after building the development image and installing dependencies. To use fewer candidates: `bash scripts/benchmark-video-comparisons.sh 100`. Counts outside 2–1000 are rejected. The script generates one 16×16, one-second video, copies it to distinct paths and capture groups, and checks that all candidates converge to one complete group. It removes its private temporary workspace in `finally`.

Both measurements below used 1000 paths in one duration bucket (499500 potential pairs), PHP 8.5.7 and ffmpeg 8.1.2, with the same local development image and host. Docker limits: 384 MiB RAM/no extra swap, 0.5 CPU, 64 PIDs, 16 MiB temporary storage; PHP memory limit 256 MiB. No original media, network or writable repository were used. Timing is from a single run per version and varies with host load.

| Implementation | Cache | Runtime (s) | Additional peak PHP bytes during reconciliation | Total PHP peak bytes |
|---|---|---:|---:|---:|
| Before: complete pair list | Cold | 94.8148 | 68934504 | 86730720 |
| Before: complete pair list | Warm | 0.5909 | 64494856 | 86870568 |
| After: lazy pairs, stale-row skipping | Cold | 92.3799 | 455752 | 18261632 |
| After: lazy pairs, stale-row skipping | Warm | 0.0296 | 164400 | 18420984 |

Cold means a newly created `VideoStreamFingerprintMatcher`; warm reuses the same instance with fresh equivalent groups and unchanged files. This measures its in-process fingerprint cache, not persistent metadata or perceptual JSON caches. Memory uses `memory_reset_peak_usage()`, `memory_get_usage()` before reconciliation and `memory_get_peak_usage()` afterward; native ffmpeg memory and Docker RSS are not included. Fixture creation is excluded. The warm baseline includes the retained fingerprints from the cold pass.

The cold run remains dominated by hashing 1000 file paths through ffmpeg. The substantial improvement is removal of pair-list storage; warm planning also improves because exact merges invalidate whole later rows. Both implementations produced one group containing all 1000 videos.

## Remaining limits

Candidate models, duration buckets, pipeline groups and fingerprint caches remain linear in the input. This change does not stream the whole collection or bound every cache. If no pairs match, comparisons remain quadratic. `MAX_COMPARISON_PAIRS=100000` caps candidate-pair visits per capture group and across all cross-group buckets, including cheap exclusions. A single-group cross-group bucket and stale left rows require no pair walk. The counter is local to each analysis and never carries over into later runs.

The limit fails explicitly before another pair allocation/expensive comparison. Cross-group exhaustion aborts before filesystem execution; a capture-group exhaustion is reported as degraded classification and blocks that group's mutations. Unrelated complete groups may still proceed, and the command returns failure. Tiny unit/integration fixtures use a two-pair budget to verify this behavior and unchanged source bytes. No unexamined pair becomes proof of a duplicate.

This experiment demonstrates neither completion of arbitrary 70000-file collections nor a fixed wall-clock bound. Large equal-duration buckets containing distinct videos may exhaust the budget even when RAM is sufficient. Review their input distribution, split safely into batches, or raise the finite budget after measuring. Do not separate Live Photo companions when splitting inputs.
