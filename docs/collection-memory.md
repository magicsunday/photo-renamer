# Collection memory and capacity

`rename:exif` keeps a complete grouping and execution plan before mutating media. It retains paths and interpretation results, rather than a native filesystem object for every source. The normal PHP default remains 1024 MiB; Docker and decoder limits, the analysis reserve and fresh mutation/deletion checks remain in force.

## Original retained-object costs (GH-246 measurements)

The previous implementation retained the sorted `SplFileInfo` list, asset source objects and a complete second representation of all groups for the Live Photo second pass. The bridge contained legacy `FileDuplicate` objects and additional native target objects. These lifetimes overlapped with metadata, lookup maps and cache entries.

A controlled PHP 8.5.11 runtime probe retained 10,000 synthetic filesystem objects: **83,669,176 bytes** above baseline. Keeping their pathnames and actually releasing the native objects left **1,189,176 bytes**. Merely copying pathnames while retaining the objects does not save that memory. These costs come from native object buffers, not the photo/video payload size.

On the preceding runtime, a real synthetic 70,000-file cold profile used approximately **604.6 MB live PHP** after file collection/indexing, **760.5 MB** after group scanning and **1,060.5 MB** at the pairing bridge. The cold PHP peak was **1,124,077,568 bytes**. The normal 1024-MiB budget, including its 64-MiB reporting reserve, cannot accommodate that bridge. The earlier [retained-target improvement](mixed-collection-benchmark.md#gh-167-retained-targets-and-planned-analysis-stops-2026-10-11) reduced this cost but its successful large profiles used an explicitly increased 3072-MiB profiling budget. They did not prove normal-budget capacity.

Removing retained native views exposed a separate peak: `MetadataCache::flush()` built an array containing every serialized row and then one complete JSON string. A first 512-MiB attempt passed cold/warm analysis and all actual renames, but failed during the final rescan's cache flush with old cache paths still present. This failed intermediate result is retained in the measurements; it is not an idempotency pass.

An earlier entry point explicitly set `memory_limit=-1` before the finite-limit change (`1816d89`). A completed historical run therefore does not prove it stayed below 1 GiB. The measured model/bridge/serialization costs explain the current failure; this analysis does not infer the peak of any original private collection.

## Current allocation lifetimes

- `SortedFileIteratorCollector::collectAndSortPaths()` retains lossless path strings in the same parent-first, bytewise order. The legacy object-returning API still checks its budget while materializing objects.
- `AssetItem` stores its source pathname. `getFile()` supplies a temporary `SplFileInfo` to existing filesystem, metadata and comparison APIs. Immutable updates share metadata and preserve path bytes; native view identity is an implementation detail.
- Deferred Live Photo queues retain paths in encounter order. Replay constructs one view at a time, preserving first-fallback-wins and group resolution.
- The second pass feeds one group adapter at a time into the shared pairing service. Its indexes retain target paths, so the complete legacy bridge and its native target/source buffers do not overlap with the collection.
- Metadata JSON is encoded in approximately 64-KiB chunks. The existing storage boundary appends them to a private 0600 temporary inode and atomically replaces the destination only after serialization succeeds. It does not construct a second collection-wide row array or complete JSON string. An interrupted stream preserves the previous snapshot and removes the temporary file. The JSON schema, parser revision checks and last-writer-wins policy remain. Current snapshots write one JSON member per line so they can also be loaded incrementally.

The loader validates bounded rows from one owned 0600 snapshot inode. It keeps loaded rows as positional JSON strings with one shared canonical field order, hydrating metadata only for requested sources instead of retaining dates and objects for every historical pathname. It rejects the entire snapshot after a late invalid/truncated row; no earlier partial hits escape construction. Small compact snapshots remain readable. Larger old compact snapshots start cold once and are rewritten after extraction; this loses cache work, never media data. A row exceeding the 64-KiB read bound also starts cold. Compatible streamed snapshots retain historical rows; pruning stays explicit.

Global capture classification, naming, collision resolution and cycle validation still finish before execution. The occupancy index is released after collision resolution. Validation stores duplicate counts and uses a single spelling for ordinary case keys, allocating spelling lists only for actual case conflicts. The validated group collection is drained in encounter order while constructing the complete execution plan; analysis models are released before building sorted output. No file mutation is streamed during planning. Paths do not authorize file operations: fresh source identity, streamed hashes, independent duplicate-deletion proof, locks and journaling remain separate. The same budget checks protect the new points where a large individual group materializes transient views.

## Alternatives and trade-offs

| Approach | Benefit | Cost / decision |
|---|---|---|
| Keep every native filesystem object | Convenient access and stable object identity | About 8 KiB per retained view in this runtime; unnecessary for planning. Retain paths instead. |
| Raise the PHP limit | Immediate additional capacity | Preserves the allocation problem and reduces native/host headroom. Defaults remain unchanged. |
| Analyze independent directory batches | Smaller working set | Could miss cross-directory/group matches and collisions. Not equivalent to the current contract. |
| Stream paths and cache output while keeping the global plan | Removes the measured duplicate representations | Short-lived view construction and bounded append calls; existing grouping and safety contracts remain intact. This is the implemented choice. |
| Share field names in loaded cache rows | Avoids repeating the same JSON keys for every historical path | Positional values exist only in memory, using the canonical entry serializer’s order; lookup/publication restore named JSON one row at a time. No second disk schema or dependency. |
| Move indexes/cache into a database or partitioned external store | Could reduce remaining linear RAM costs for much larger collections | Additional schema, native/build dependencies and failure boundaries. Measure the remaining bottleneck before introducing it. |

## Automated regression boundary

`RuntimeMemoryLimitTest::distinctCapturesCompleteWithinFiniteBudget()` generates **6000 distinct valid JPEG captures** and executes the real CLI with **96 MiB**. The shared source/PHAR/native acceptance runner checks cold preview, confirmed renaming and cold/warm repeated runs with historical cache paths retained, at 128 descriptors and including every name and byte hash. The test fails against the preceding retained-object design, before the normal scan finishes, and passes with the path-based implementation.

The separate low-budget failure tests continue to require explained incomplete analysis before mutation. Weak-reference checks cover resource lifetimes and encounter order. Existing command/file-state checks cover metadata interpretation, complete Live Photo pairing, collisions, duplicate refusal, dry-run immutability and repeated execution. Cache tests verify private publication and preservation of the previous snapshot after a streamed serialization failure. A fresh child also loads, updates, flushes and reloads 20,000 valid historical rows at **32 MiB**, retaining all rows and producing no runtime diagnostics. That regression fails on the preceding eager decoder with an actual 32-MiB exhaustion and passes with row loading. Late corrupted/truncated/trailing snapshots must discard every earlier row; legal trailing JSON whitespace remains accepted.

Small command smoke cases alone do not establish collection capacity. Likewise, a successful benchmark under an increased PHP budget does not establish capacity under the normal default. Native, PHAR and source execution remain separate verification targets.

## Earlier GH-246 measurements

The comparison uses only generated 64×64 JPEG/HEIC stills and one-second MOV companions, distinct synthetic Apple identifiers, native parsing, persistent caches, full planning and actual renames. Each pair's ID, format, timestamp and proposed name are validated. The final rescan retains historical cache paths and must produce only no-ops. No original media or private production caches are mounted.

Each fresh worker has one CPU, a 4-GiB container limit without extra swap, 128 PIDs, a 1-GiB non-executable media/cache tmpfs and a 20-minute deadline. A separate 1-MiB executable tmpfs contains only fixed subprocess counters. Worker PHP budgets are selected explicitly. The parent has 256 MiB. JSON records effective limits, live/allocated PHP checkpoints, cumulative PHP peak and Linux worker peak RSS, sampled temporary disk and native comparison counts. Some profiles ran concurrently; elapsed times are observations, not a throughput comparison.

| Files | Phase | Before PHP peak (MiB) | GH-246 PHP peak (MiB) | GH-246 worker RSS peak (MiB) | PHP budget (MiB) |
|---:|---|---:|---:|---:|---:|
| 70000 | cold | 1072.0 | 308.0 | 329.8 | 512 |
| 70000 | warm | 1132.5 | 380.0 | 395.2 | 512 |
| 70000 | mutate | 1163.5 | 409.0 | 424.1 | 512 |
| 70000 | idempotency | 1301.0 | 430.0 | 453.9 | 512 |
| 100000 | cold | — | 414.0 | 442.7 | 1024 |
| 100000 | warm | — | 518.0 | 541.4 | 1024 |
| 100000 | mutate | — | 557.0 | 580.8 | 1024 |
| 100000 | idempotency | — | 588.0 | 622.1 | 1024 |

All four workers completed for both collection sizes. There were **35,000 / 50,000 complete Live Photo groups**, **70,000 / 100,000 actual successful renames**, and then exactly **70,000 / 100,000 no-ops**. Historical cache entries were retained. Known distinct IDs produced zero comparison plans/native matching subprocesses, with a separate positive counter control. The 70k maximum falls from **1301 MiB to 430 MiB**, about **67%**, without raising defaults. The 100k maximum is **588 MiB** under the unchanged 1024-MiB application budget.

The GH-246 source snapshot is runtime image `sha256:bdbe6e9f9910c4d7f2c7d843b5b23dbe5ec46f7eafb4f7609200d3fbe2c129c5`; the baseline is `sha256:cc498a2c75f15eebd603ee287b7ea79da3b30e8a91a5a75f733a2603b34705fb`. Both use PHP 8.5.11 and the same locked production packages, including the corrected imagemeta stream lifetime. The exact same frozen harness was mounted read-only into both images; raw records include its SHA-256 values and source/lock/package provenance.

Raw synthetic-only records: [before 70k](benchmarks/gh246-before-70000.json), [current 70k](benchmarks/gh246-after-70000.json), [current 100k](benchmarks/gh246-after-100000.json), [failed intermediate cache-flush attempt](benchmarks/gh246-intermediate-70000.json).

At 70k cold collection/indexing, live PHP drops from **604,647,040 to 25,037,320 bytes**. At the first pairing scan with its indexes ready, compare the same named checkpoint in the raw records; the materialized bridge is no longer retained. These are live allocations, distinct from allocated pages or cumulative RSS/PHP peaks.

Rebuild the intended runtime image, then run:

```bash
make mixed-collection-benchmark COUNT=70000 MEMORY_MIB=512
make mixed-collection-benchmark COUNT=100000 MEMORY_MIB=1024
```

`MEMORY_MIB` accepts integers from 16 to 3072; the historical default remains 3072. Selecting a smaller worker budget does not change application defaults or container/native limits. For a fair before/after comparison, mount the same frozen benchmark scripts into both immutable runtime images and retain image/source/lock digests.

## Larger collection profile

The separate 250,000-file target uses the unchanged 1024-MiB PHP default. It exercises five fresh workers: cold and warm analysis, confirmed mutation, the first rescan under renamed paths, then a fully warm rescan that reloads both historical and renamed cache rows. The 70,000-file comparison uses 512 MiB. There are 125,000 / 35,000 distinct complete Live Photo pairs respectively, with every planned name, format, ID and video duration checked.

Above 100,000 files only the synthetic media/cache tmpfs grows to 2 GiB so fixture inode allocation, journals and atomic cache snapshots fit. The container remains 4 GiB without additional swap, one CPU and 128 PIDs; each worker still has a 20-minute deadline. This profiling workspace is separate from the production memory defaults. The benchmark mirrors provider/index/group lifetimes and checks the same pre-mutation headroom gate as the CLI.

The preceding implementation's corrected 250k profile completed cold analysis near 986 MiB, then failed the warm phase during target naming before any media mutation. The initial profile run also exposed a benchmark expectation that missed the documented date-only naming of midnight captures; correcting that expectation does not change production naming. Failed intermediate streaming/group-lifetime attempts remain failures, including the confirmed-mutation plan-projection stops. An intermediate run retaining named JSON strings completed four phases at 760/812/908/942 MiB, then stopped the warm repeat at the unchanged 960-MiB analysis boundary. Sharing the canonical JSON field names once removes their repetition from loaded rows without changing the named disk format. Pure `gc_mem_caches()` did not lower allocated pages in the isolated cache probe, so it was not added as a workaround.

The current 70k profile completes all five workers at a maximum of **269 MiB PHP** (512-MiB budget), including 70,000 successful renames and two runs with exactly 70,000 no-ops. The final warm run reloads all **140,000 historical plus renamed cache rows**. This is about 38% below the earlier GH-246 430-MiB maximum and 79% below the original 1301-MiB maximum.

All five 250k workers complete under **1024 MiB PHP**, with a maximum of **884 MiB**. Confirmed mutation executes all **250,000 renames**; both subsequent runs produce exactly **250,000 no-ops** across **125,000 complete Live Photo groups**. The final warm worker reloads and retains **500,000 cache rows**. Neither PHP defaults nor the 64-MiB reporting reserve changed.

| Phase | 70k PHP peak (MiB) | 70k RSS peak (MiB) | 250k PHP peak (MiB) | 250k RSS peak (MiB) |
|---|---:|---:|---:|---:|
| cold | 240.5 | 265.4 | 760.0 | 795.8 |
| warm | 234.5 | 252.3 | 754.0 | 780.3 |
| mutate | 263.5 | 280.8 | 850.0 | 878.5 |
| idempotency | 269.0 | 302.0 | 884.0 | 917.8 |
| warm_idempotency | 259.0 | 282.9 | 864.0 | 889.9 |

Raw synthetic-only evidence: [current 70k](benchmarks/gh248-after-70000.json), [current 250k](benchmarks/gh248-after-250000.json), [failed preceding 250k baseline](benchmarks/gh248-before-250000.json), [failed intermediate attempts](benchmarks/gh248-intermediate-250000.json). The final profiles use immutable image `sha256:970f93bd60122a6869bc3df522fce8df0f2538bd7151f6958f77000af5136b8c`; every production PHP source file was compared by SHA-256 with the reviewed implementation. Its manifest honestly records the modified pre-commit workspace. The raw files include source, lock, package and frozen-harness digests. The older failed baseline uses the same PHP/parser but earlier Symfony Filesystem/Lock/Process references; elapsed times and harness-lifetime differences prevent a controlled throughput comparison.

### What still occupies memory

The confirmed 250k run starts near 9 MiB live PHP, adds about 96 MiB for source fingerprints, 83 MiB for 250,000 loaded cache rows, and 38 MiB for collection paths/initial indexes. Scanning/grouping then adds about 392 MiB for metadata, assets, group membership and related indexes. These checkpoint differences are not isolated object-size measurements. Additional naming/validation/projection allocations overlap later.

After plan projection and output, that run has about **425 MiB live PHP** while PHP retains **832 MiB allocated pages**; its cumulative peak is **850 MiB**. Allocated pages, live retained data and RSS are different quantities. Releasing an object graph does not require PHP's allocator to return every page immediately.

The next architectural lever would be the global working set, rather than another cache file encoding: store selected indexes/metadata/plan rows on disk and visit bounded windows while preserving global pairing, collision/cycle validation and mutation eligibility. That would add I/O and storage/error-handling complexity. It is not part of this fix, and should be evaluated against a concrete lower RAM target with the same file-state contracts; directory-by-directory mutation is not an equivalent replacement.

Reproduce the current profile with a rebuilt production image:

```bash
make mixed-collection-benchmark COUNT=250000 MEMORY_MIB=1024
make mixed-collection-benchmark COUNT=70000 MEMORY_MIB=512
```

## Remaining costs and limits

Memory is still linear in observed source paths, serialized cache rows, global groups and plan entries. Row decoding and publication use bounded temporary buffers; the entire grouping/plan is not a disk-backed constant-memory algorithm. Historical paths still increase retained serialized cache data until explicit maintenance. Skipped/error entries and a very large individual classification group can retain additional native views. Native image decoding and external processes have separate memory needs that these tiny-media structural profiles do not represent.

Consequently, these measurements prove the stated synthetic workloads and regression partitions. They do not guarantee a fixed memory ceiling for arbitrary high-resolution media, corrupt inputs, huge same-time groups, unknown-ID comparisons, unbounded historical caches or concurrent foreign writers. A lower PHP peak is not a reason to remove comparison budgets, decoder limits or controlled pre-mutation aborts.
