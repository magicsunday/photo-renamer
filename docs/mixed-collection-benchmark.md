# Synthetic mixed-collection profile

Issue #127 requires measurements with real files in addition to virtual candidates and the isolated stream-cache benchmark. Run `make runtime-build`, then `make mixed-collection-benchmark COUNT=70000` or `COUNT=100000`. A small smoke profile is available with `COUNT=20`; accepted counts are multiples of four between 4 and 100000. Original collections are never mounted or accepted.

The generator creates native 64×64 JPEG/HEIC pixels and one-second 64×64 MPEG-4 video in MOV containers. Minimal TIFF/Apple MakerNotes and QuickTime keys carry generated content identifiers and capture dates; no camera metadata, personal media, coordinates or serial numbers are copied. Each capture has one still and one companion; half the stills are JPEG and half HEIC. All MOVs have equal duration but distinct known IDs and belong to separate capture groups. This deliberately stresses a large plausible Live Photo duration bucket while preserving its cheap identity exclusions.

The image contains the actual production code, dependencies, native tools and ImageMagick policy. Only benchmark scripts are mounted read-only. It has no network, development credentials, original-media mount or persistent application cache volume. Current explicit profile limits are 4 GiB RAM without additional swap, 1 CPU, 128 PIDs, read-only root, 1 GiB non-executable `/tmp`, and 3072 MiB PHP per worker. Symfony Process enforces a 20-minute timeout for each worker, including during polling. A separate 1 MiB executable tmpfs holds two fixed shell counters delegating to the installed ffprobe/ffmpeg binaries; a positive control validates instrumentation. These increased profile budgets and instrumentation differ from ordinary runtime defaults; they are not changes to application defaults.

Four fresh PHP workers load the application's service configuration, use the actual file collector, imagemeta parser, persistent metadata cache, EXIF pipeline and execution-plan builder, and validate every proposed still/companion name. The final script also requires a non-null unique content ID for every capture, an equal parsed ID in both pair members, exactly one second of parsed MOV duration, and the expected native 25 % JPEG / 25 % HEIC / 50 % MOV mix:

1. **Cold:** no persistent metadata entries exist.
2. **Warm:** fresh process reloads unchanged-file metadata from the previous disk cache. This is not the in-process fingerprint cache measured by the separate video experiment.
3. **Mutate:** execute real renames on the generated files, checking successful moves, no errors/fallbacks and complete pair plans.
4. **Idempotency:** rescan renamed paths, require every item to be a no-op and no executable action.

The original measurements below profile the active services directly with a non-buffering progress observer; that harness did not measure CLI rendering or confirmation prompts. The #167 comparison described later adds the actual Console renderer with a non-buffering sink and the mutating CLI's DI-shared lock, source-identity and journal scope. It still does not measure an interactive terminal or confirmation latency. Known-ID exclusions should yield zero consumed comparison plans and zero native fingerprint/decode launches in this profile. The generator's video encoding and the positive instrumentation control are excluded from worker subprocess counts. Existing service/command tests and the separate unknown-ID stream benchmark cover conservative AV vetoes and real expensive comparisons.

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


## GH-167: retained targets and planned analysis stops (2026-10-11)

The incremental change stores **computed Live Photo target pathnames** instead of retaining a native `SplFileInfo` target for each content identifier. `getTarget()` creates a short-lived view when a consumer needs it. Pending source videos keep their original collected objects and encounter order: replaying fresh source views while the sorted list still owns the originals would increase allocations. Completed content-ID cache/map state is released after pending resolution, before building the existing second-pass pairing bridge. Pairing, known/unknown ID exclusions, AV-track vetoes, collision rules and fresh mutation/deletion checks remain independent.

A controlled PHP 8.5.7 Linux probe retained 2000 synthetic native file objects: **16613112 bytes** above baseline. Copying their path strings while those objects remained alive retained **16650032 bytes**, providing no reduction. Actually releasing the native objects left **149112 bytes** for the path list (PHP peak 18874368). This is an object-lifetime measurement, not a media-capacity result. A regression using weak references verifies that cached canonical/fallback targets release their original native objects while preserving exact target pathnames; source queues explicitly reuse their original objects. The large-profile checkpoints below identify the same allocation in the application.

### Revised, identical comparison harness

Both comparison images run the same frozen benchmark scripts with the finite limits stated above: **3072 MiB worker PHP, 4 GiB container RAM/swap, 1 CPU, 128 PIDs, 1 GiB media/cache tmpfs, 20 minutes per worker**. A separate 1-MiB executable tmpfs only contains launch counters. The effective cgroup limit, PHP ini limit and tmpfs capacity are read inside each worker and recorded; a missing/mismatching container limit fails the profile.

- Before: main commit `0a36436a48e2965d29bea3a13922d456c0876c77`, production image `sha256:67e91554853fdd57df7eeb3ac3c4aa3fab3736004a661006b6871de2c0ed14de`.
- After: GH-167 production-source snapshot, image `sha256:92fc06c93f57139f1e9c762968a57c1a7ea22a18e4465267b2196f83698a976d`.
- Frozen `BenchmarkProgressReporter.php` SHA-256: `80c04b6abe8024198682e2d1b8250255816be8bf924bd4ebe7963659032abe7e`.
- Frozen `benchmark-mixed-collection.php` SHA-256: `6ab985fd00d2f8bd7835217f9597345bcf88627493a862391f9867379780e306`.

Both images use PHP 8.5.7 and the same 23 production package versions/references (excluding the root package): sorted package/version/reference JSON SHA-256 `cd59f3e7f2055b890992f8001329580f99e4af608532e25a000ca85e1dd7f817`. In particular, imagemeta reference is `77bf7d288abfab87701df068f99cd497a56f5b46`; Symfony Console/DI are 8.1.8. Images stay pinned during profiling; mounted script snapshots do not change between workers. Timing remains a single measurement affected by concurrent host work; use allocation differences for the optimization claim.

The revised worker starts the shared mutation lock, journal and fresh streamed source-identity pass **before metadata-cache loading**, as the application does. It uses the actual renderer through `NullOutput`, which formats entries without retaining their output bytes. These scope/rendering costs distinguish the new comparison from the historical measurements above. An intermediate experiment loading caches before starting the mutation scope is not used as the final before/after evidence.

Checkpoint JSON uses bytes throughout:

- `php_current_bytes`: live PHP allocations (`memory_get_usage(false)`).
- `php_allocated_bytes`: allocated PHP pages, including currently unused pages.
- `php_peak_bytes` and `peak_rss_bytes`: cumulative worker peaks, **not** independent per-stage maxima. Linux `ru_maxrss` is converted from KiB.
- `file_list_and_indexes`: after sorted collection **and** disk/build indexes, not an isolated file-list allocation.
- `group_scan_finished`, `pairing_bridge`, `pairing_finished`, `groups_built`, `classification_finished`: overlapping group/pairing/classification lifetimes observed through existing progress boundaries.
- `source_scope_ready`, `before_cache_load`, `cache_loaded`, `pipeline_complete`, `plan_built`, `output_rendered`, `execution_complete`, `before_cache_flush`, `cache_flushed`: fixed worker checkpoints. No full per-file or per-comparison history is retained.

### Complete before/after results

All four workers and both complete parent profiles passed after the change for **70000 and 100000 real synthetic files** under the explicit 3072-MiB/4-GiB budgets. Every worker retained the expected complete Live Photo groups, parsed ID/date/duration/format assertions and names. Actual mutation moved every source without errors/fallbacks; idempotency retained all no-ops with zero executable actions. Historical cache entries from the original paths were **not** cleared before rescanning renamed files. All recorded comparison plans and worker native launches were zero, with positive native-counter calibration.

| Files | Phase | Before PHP peak bytes | After PHP peak bytes | Before RSS bytes | After RSS bytes | PHP reduction |
|---:|---|---:|---:|---:|---:|---:|
| 70000 | cold | 1444941824 | 1149243392 | 1471586304 | 1168838656 | 20.46% |
| 70000 | warm | 1479020544 | 1183322112 | 1488027648 | 1192984576 | 19.99% |
| 70000 | mutate | 1509429248 | 1213730816 | 1518329856 | 1221779456 | 19.59% |
| 70000 | idempotency | 1664094208 | 1315966976 | 1690415104 | 1341468672 | 20.92% |
| 100000 | cold | 2006978560 | 1566662656 | 2040438784 | 1593667584 | 21.94% |
| 100000 | warm | 2076708864 | 1653084160 | 2096263168 | 1671958528 | 20.40% |
| 100000 | mutate | 2129137664 | 1693978624 | 2142109696 | 1712570368 | 20.44% |
| 100000 | idempotency | 2297434112 | 1873809408 | 2320842752 | 1899659264 | 18.44% |

The 70000-file idempotency peak falls from **1.550 to 1.226 GiB PHP**; the 100000-file peak falls from **2.140 to 1.745 GiB**. This is a measured reduction of retained target lifetimes, not lower logical group/plan counts or hidden cache deletion. Linux worker peak RSS falls correspondingly. Original-media decoding, unknown-ID worst cases and arbitrary throughput remain outside this tiny-file distribution.

The cold-worker live PHP checkpoints isolate the allocation (cumulative peaks are in the raw records):

| Files | Checkpoint | Before live PHP bytes | After live PHP bytes | Reduction bytes |
|---:|---|---:|---:|---:|
| 70000 | `file_list_and_indexes` | 604786768 | 604810816 | -24048 |
| 70000 | `group_scan_finished` | 1047361824 | 757530840 | 289830984 |
| 70000 | `pairing_bridge` | 1360938352 | 1056857920 | 304080432 |
| 70000 | `groups_built` | 733889080 | 733913128 | -24048 |
| 70000 | `plan_built` | 815805376 | 815829424 | -24048 |
| 70000 | `cache_flushed` | 818649008 | 818673056 | -24048 |
| 100000 | `file_list_and_indexes` | 853426768 | 853450816 | -24048 |
| 100000 | `group_scan_finished` | 1475449528 | 1060461216 | 414988312 |
| 100000 | `pairing_bridge` | 1915778832 | 1484738400 | 431040432 |
| 100000 | `groups_built` | 1029889560 | 1029913608 | -24048 |
| 100000 | `plan_built` | 1144205856 | 1144229904 | -24048 |
| 100000 | `cache_flushed` | 1147049488 | 1147073536 | -24048 |

The collected-file/index footprint and final group/plan/cache footprint remain essentially unchanged. The reduction occurs specifically while computed targets would otherwise overlap with capture-group and pairing-bridge state. At 70000 files, the cold scan releases approximately **290 MB** of retained target allocation; at 100000 files it releases approximately **415 MB**. Final pipeline models were not replaced with copied file wrappers. Linear source-object and metadata-cache costs remain visible.

Full per-phase metrics, effective limits, durations, sampled temporary disk and all fixed checkpoints are versioned as synthetic-only JSON:

- [Before 70000](benchmarks/gh167-before-70000.json), [after 70000](benchmarks/gh167-after-70000.json).
- [Before 100000](benchmarks/gh167-before-100000.json), [after 100000](benchmarks/gh167-after-100000.json).

Reproduce the after profile by rebuilding the production image and running `make mixed-collection-benchmark COUNT=70000` and `COUNT=100000`. For a fair historical comparison, build the pre-change application revision separately and mount the **same revised benchmark scripts** read-only; retain its pinned image ID and script hashes. Running the old application with its older harness omits the added scope/rendering costs and is not the identical comparison shown here. The historical lower-budget failures above remain failures; no defaults were increased by this change.

### Low-budget behavior and remaining boundaries

`AnalysisMemoryBudget` observes actual allocated PHP pages at source-identity, file collection/sorting, index/group/pairing growth, pipeline phase transitions, execution-plan growth and the final command gate before mutation. It reserves one eighth of the effective PHP budget, bounded to **8–64 MiB**, for reporting and cleanup. A planned boundary throws an explicit incomplete-analysis error rather than treating a partial plan as usable. EXIF skips optional full-cache serialization on this stop, preserving previous disk snapshots. The reserve is an operational margin, not a decoder/container memory estimate.

A real CLI regression with **32 MiB PHP and 6000 synthetic source files** checks both dry-run and an explicitly confirmed mutating invocation. Both exit 1 during file collection with the explained incomplete-analysis message, retain every source name/byte and the previous cache snapshot, and emit no `Allowed memory size` fatal or success message. The mutating run records failure with no mutation intents and releases its batch lock. It still captures fresh source identities and uses the real application/journal scope. The test's small text payloads are suitable for proving the pre-parser boundary, not format/capacity profiling; the real-media profiles remain separate.

**Production defaults remain 1024 MiB PHP / 2 GiB container RAM.** The measured large distribution still exceeds the 1-GiB PHP budget after this reduction; the higher, finite profile budgets are an explicit test configuration. Neither raising a limit without native/host reserves nor an arbitrary-collection guarantee follows from these runs.

Single large PHP allocations between checkpoints (including full JSON load/serialization), native decoder memory and kernel OOM are not reliably catchable by these checkpoints. A failure after mutation has started can leave completed or uncertain actions; reconcile the persistent journal with actual files and generate a fresh plan. No automatic rollback, OOM-derived success, guard removal, manual cache clearing before idempotency or naive collection partitioning is used.
