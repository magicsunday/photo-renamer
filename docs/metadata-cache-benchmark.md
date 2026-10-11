# Synthetic metadata cache measurements

Measured on 2026-10-11 for [#129](https://github.com/magicsunday/photo-renamer/issues/129). These measurements isolate the versioned metadata cache lifecycle; full-media collection capacity remains separate [#167](https://github.com/magicsunday/photo-renamer/issues/167).

## Reproduce

```bash
make runtime-build
make metadata-cache-benchmark COUNT=70000
make metadata-cache-benchmark COUNT=100000
```

The wrapper mounts only `scripts/` read-only and accepts a synthetic entry count, never a media directory. It creates a disposable synthetic workspace inside an unprivileged runtime container with network disabled, a read-only root, all capabilities dropped and no-new-privileges. Limits: 2 GiB RAM with no extra swap, one CPU, 128 PIDs, 256 MiB non-executable temporary tmpfs, 1024 MiB PHP memory and 300 seconds wall time. Production defaults remain unchanged. Counts outside 1,000–200,000 are rejected; accepting a count does not promise it fits the finite budgets.

Each fresh container generates a current-version JSON snapshot with fully populated synthetic dates, content ID, device fields, marker/index, duration and GPS values. One tiny synthetic source exists; every other pathname is historical and missing. It then:

1. Loads the real `MetadataCache`, including envelope/parser and row validation, and verifies a warm hit for the existing file.
2. Marks that entry dirty and measures a complete snapshot flush.
3. Prunes missing paths, flushes the reduced snapshot and opens a new cache to verify that the retained entry is still warm and pruning is idempotent.

The final two runs were sequential, with no concurrent repository build/test jobs. Filesystem and host load are not controlled, and these are single local observations rather than statistical throughput estimates.

## Results

All sizes are bytes; times are seconds. PHP peak and Linux `ru_maxrss` cover the **whole worker**, including synthetic fixture construction and all cache phases. They are not an isolated allocation delta for `MetadataCache`. Prune time excludes the subsequent reduced-snapshot flush.

| Entries | Load s | Full flush s | Prune s | Snapshot bytes | Removed entries | Reduced bytes | PHP peak bytes | Linux peak RSS bytes |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 70,000 | 0.569388 | 0.240567 | 0.099045 | 34,498,968 | 69,999 | 567 | 293,605,376 | 321,236,992 |
| 100,000 | 1.578864 | 0.661237 | 0.275480 | 49,288,968 | 99,999 | 567 | 442,503,168 | 471,023,616 |

Both runs passed the retained-entry, exact removal-count and fresh-instance reload assertions. PHP peaks were 280 MiB and 422 MiB. Snapshot sizes grew from roughly 32.9 MiB to 47.0 MiB; pruning reduced both snapshots to a single 567-byte entry. Historical path retention therefore has measurable storage and worker memory cost. Load/flush still process complete snapshots; pruning is explicit maintenance, not a bounded-memory cache redesign.

## Measured artifact

- Parent repository commit: `c25b9aa2ceccc5ee0733d47116ce6ad77ee0c231`, plus the #129 changes in this report's commit.
- Runtime image: `sha256:b96fee42568ec42ae0437cdc08cf339964c400f6115c962cb408667fe829c8b4`.
- PHP: `8.5.7`.
- Installed imagemeta reference: `77bf7d288abfab87701df068f99cd497a56f5b46`.
- `src/Metadata/MetadataCache.php` SHA-256: `f894d6fe3155f3a0a43b8a8798b2d1c60a8cf981c7038816fd79f312bf647bf8`.
- `scripts/benchmark-metadata-cache.php` SHA-256: `c5280edd619b8b13df74be7d076f5c25c334100562a09adeaf6f9eb8dedc2d63`.
- `scripts/benchmark-metadata-cache.sh` SHA-256: `e0b1c9e6ee7ef12cc760c0ab83c55f6e67c12f53446a5ca5ab5b6dc19510e6d8`.

The image was checked with `make runtime-image-check`. This report contains synthetic data only; no original media, private paths or personal metadata were used.

## Interpretation and limits

This does not parse JPEG/HEIC/MOV files, build the rename model, decode images/video, hash source bytes, write mutation journals, compare unknown Live Photo IDs or rename media. The single retained source and deliberately missing historical paths do not represent a fully present collection or its filesystem latency. Perceptual cache histogram storage is also outside this profile.

The existing [mixed-collection profile](mixed-collection-benchmark.md) measured a different full-pipeline baseline with larger profiling budgets. Its total RSS must not be attributed to metadata caching, and these cache-only results do not supersede its documented standard-budget failures. In particular, no claim is made that a real 70,000/100,000-media collection fits the default application budgets. #167 must measure and address that capacity separately, including the newer source-identity and journal work.

Use explicit pruning only with the expected media paths mounted and every cache writer stopped. It discards cache work for non-visible paths, never media; missing or inaccessible mounts can discard useful entries. Mutating CLI runs coordinate through the shared lock, while read-only verify/dry-run writers retain documented last-writer-wins behavior.
