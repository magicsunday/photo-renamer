# Synthetic mixed-collection profile

Issue #127 requires measurements with real files in addition to virtual candidates and the isolated stream-cache benchmark. Run `make runtime-build`, then `make mixed-collection-benchmark COUNT=70000` or `COUNT=100000`. A small smoke profile is available with `COUNT=20`; accepted counts are multiples of four between 4 and 100000. Original collections are never mounted or accepted.

The generator creates native 64×64 JPEG/HEIC pixels and one-second 64×64 MPEG-4 video in MOV containers. Minimal TIFF/Apple MakerNotes and QuickTime keys carry generated content identifiers and capture dates; no camera metadata, personal media, coordinates or serial numbers are copied. Each capture has one still and one companion; half the stills are JPEG and half HEIC. All MOVs have equal duration but distinct known IDs and belong to separate capture groups. This deliberately stresses a large plausible Live Photo duration bucket while preserving its cheap identity exclusions.

The image contains the actual production code, dependencies, native tools and ImageMagick policy. Only benchmark scripts are mounted read-only. It has no network, development credentials, original-media mount or persistent application cache volume. Current explicit profile limits are 4 GiB RAM without additional swap, 1 CPU, 128 PIDs, read-only root, 1 GiB non-executable `/tmp`, and 3072 MiB PHP per worker. Symfony Process enforces a 20-minute timeout for each worker, including during polling. A separate 1 MiB executable tmpfs holds two fixed shell counters delegating to the installed ffprobe/ffmpeg binaries; a positive control validates instrumentation. These increased profile budgets and instrumentation differ from ordinary runtime defaults; they are not changes to application defaults.

Four fresh PHP workers load the application's service configuration, use the actual file collector, imagemeta parser, persistent metadata cache, EXIF pipeline and execution-plan builder, and validate every proposed still/companion name. The final script also requires a non-null unique content ID for every capture, an equal parsed ID in both pair members, exactly one second of parsed MOV duration, and the expected native 25 % JPEG / 25 % HEIC / 50 % MOV mix:

1. **Cold:** no persistent metadata entries exist.
2. **Warm:** fresh process reloads unchanged-file metadata from the previous disk cache. This is not the in-process fingerprint cache measured by the separate video experiment.
3. **Mutate:** execute real renames on the generated files, checking successful moves, no errors/fallbacks and complete pair plans.
4. **Idempotency:** rescan renamed paths, require every item to be a no-op and no executable action.

The benchmark profiles the active services directly with a non-buffering progress observer. It does not measure CLI terminal rendering or confirmation prompts. Known-ID exclusions should yield zero consumed comparison plans and zero native fingerprint/decode launches in this profile. The generator's video encoding and the positive instrumentation control are excluded from worker subprocess counts. Existing service/command tests and the separate unknown-ID stream benchmark cover conservative AV vetoes and real expensive comparisons.

Each worker reports elapsed time (including service-container construction, parsing, planning, semantic checks and metadata-cache flush), PHP peak allocation, and Linux `getrusage()` peak RSS for that worker. RSS includes PHP/native allocations in that process; it is not aggregate container memory or a sum of child-process RSS. The parent samples total allocated `/tmp` space every 100 ms while a worker runs, including media, caches and temporary cache replacement files. Samples can miss short-lived peaks; the tmpfs hard limit remains independent. Fixture generation is reported separately and is excluded from worker elapsed time.

The emitted plan count uses the reconciler's existing progress callback, one advance per consumed plan. A null first-comparison memory value means no comparison was emitted, not a measured zero allocation. The existing first-comparison regression with an eligible bucket remains the evidence against an eagerly materialized quadratic plan.

## Measurements

The 70000-file profile completed all four phases with a 2048 MiB worker PHP budget. The final 70000- and 100000-file repetitions completed all four phases with a 3072 MiB worker budget, including the explicit native-ID/duration/format checks above. No arbitrary-collection capacity guarantee follows from these runs. Small production-image validation with 20 files also passed complete Live Photo pairing/naming, actual mutation, idempotency and the native-counter positive control; a count of three failed before fixture generation.

The first 70000-file attempt used 1024 MiB PHP, 2 GiB container RAM and the same 1 GiB disposable tmpfs. Generation completed (17500 JPEG, 17500 HEIC, 35000 MOV, 63595000 payload bytes). The cold worker exhausted its PHP budget during capture-group construction, before any planned comparison or mutation phase completed. It exited 255; this is a confirmed PHP allocation failure, not evidence of a kernel OOM or a successful default-budget run. Completed-phase RSS/temp measurements were unavailable in that first attempt. Worker fatal-failure telemetry was added afterward; higher-budget results remain separate.

With 2048 MiB PHP / 4 GiB container RAM, the first 100000-file run completed cold analysis (154.132 s), warm analysis (6.681 s) and all 100000 actual renames (7.399 s). Its subsequent idempotency scan exhausted the PHP budget during the Live Photo second pass after 161.337 s. Failure telemetry recorded 2146439168 peak PHP bytes and 2168111104 worker RSS bytes; sampled temporary disk peaked at 455839744 bytes. All recorded phases consumed zero comparison plans and zero worker native launches. This failed final phase is not an idempotency pass or proof of 2-GiB capacity. The larger, successful profile retains the same old cache entries instead of clearing them to hide this problem.

Measurement platform: Intel Pentium Gold 8505, approximately 32 GB host RAM; worker filesystem is the bounded local tmpfs. Docker CPU/RAM limits above constrain the actual profile. No host identifiers, personal filenames or camera metadata are included.

The successful 70000-file run used production code `61c7781102e5e39075a3f8b7432258260ecbcd40` with PHP 8.5.7, the rebuilt production vendor tree and image `sha256:b2c2be39beba6c996cb00e5506de624dee72f4bdb88d4a3dd532e1d4ed21ad33`. The 35000 equal-duration MOVs imply 612482500 potential pairs; their conflicting known IDs caused zero consumed plans and zero worker native launches in every phase.

| Files | Phase | Runtime (s) | PHP peak bytes | Worker peak RSS bytes | Sampled temporary disk bytes |
|---:|---|---:|---:|---:|---:|
| 70000 | Cold | 90.163 | 1442320384 | 1469952000 | 319090688 |
| 70000 | Warm | 4.415 | 1479020544 | 1488990208 | 319090688 |
| 70000 | Mutate | 5.005 | 1479020544 | 1488150528 | 319090688 |
| 70000 | Idempotency | 96.250 | 1611141120 | 1638662144 | 352288768 |

All phases retained 35000 complete Live Photo groups and 70000 items. Mutation executed 70000 moves without errors or fallbacks; the final phase had 70000 no-ops and zero executable actions. The final phase is cold for the renamed pathnames because metadata-cache identity includes the path; the original entries also remain in that disposable cache. It must not be called a warm-cache run. Total collection payload is 63595000 bytes; allocated tmpfs blocks and cache files explain the larger disk footprint. These are single-run measurements; host load and filesystem differ across installations.

The final 100000-file repetition used the same production image/code and hardware with 3072 MiB worker PHP / 4 GiB container RAM. It generated 25000 JPEG, 25000 HEIC and 50000 MOV files, totaling 90850000 payload bytes. Every phase explicitly checked the parsed format mix, distinct non-null capture IDs, matching pair IDs and one-second MOV duration. Its 50000 equal-duration videos imply 1249975000 potential pairs; the known-ID exclusions emitted zero plans and launched zero native worker subprocesses.

| Files | Phase | Runtime (s) | PHP peak bytes | Worker peak RSS bytes | Sampled temporary disk bytes |
|---:|---|---:|---:|---:|---:|
| 100000 | Cold | 152.862 | 2006978560 | 2042392576 | 455839744 |
| 100000 | Warm | 6.753 | 2076708864 | 2095915008 | 455839744 |
| 100000 | Mutate | 7.321 | 2076708864 | 2097147904 | 455839744 |
| 100000 | Idempotency | 171.672 | 2297434112 | 2322632704 | 503263232 |

All 50000 Live Photo pairs survived every phase. Mutation executed 100000 moves without errors/fallbacks, and the final scan produced exactly 100000 no-ops and zero executable items with old cache entries still present. An earlier 3072-MiB run before the additional explicit native assertions also passed, with a final RSS of 2355650560 bytes; run-to-run variation is expected, not a performance guarantee.

The final 70000-file repetition under the same 3072-MiB worker / 4-GiB container budgets also passed the stricter native assertions, all 70000 actual moves and all 70000 final no-ops. Counts, payload and bucket size remain as above; all phases again emitted zero comparison plans and zero worker native launches.

| Files | Phase | Runtime (s) | PHP peak bytes | Worker peak RSS bytes | Sampled temporary disk bytes |
|---:|---|---:|---:|---:|---:|
| 70000 | Cold | 121.918 | 1444941824 | 1471049728 | 319090688 |
| 70000 | Warm | 4.564 | 1479020544 | 1488289792 | 319090688 |
| 70000 | Mutate | 4.969 | 1479020544 | 1487708160 | 319090688 |
| 70000 | Idempotency | 110.535 | 1664094208 | 1689878528 | 352288768 |

## Limits

This is an LP-heavy structural and cache profile on tiny payloads. Distinct known IDs exclude every impossible cross-capture pair; it does not demonstrate cheap processing of large buckets with unknown/equal identifiers, diverse edits, high-resolution images, long videos, network filesystems or arbitrary input distributions. Remaining linear models/caches and quadratic eligible comparisons still matter. Comparison work budgets, native-decoder limits and controlled failure tests remain required. The confirmed remaining linear memory/capacity problem is tracked independently in [#167](https://github.com/magicsunday/photo-renamer/issues/167), with cache freshness/pruning in [#129](https://github.com/magicsunday/photo-renamer/issues/129). Completion of #127 does not mean the failed lower budgets have been fixed.
