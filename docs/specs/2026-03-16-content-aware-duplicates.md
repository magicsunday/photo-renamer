# Content-aware Duplicate Detection

## Problem

All rename commands group files purely by target filename. When multiple different files produce the same target name (e.g., two photos taken in the same second without millisecond precision), they are treated as duplicates and receive `-duplicate-NNN` suffixes. This is incorrect when the files have different content (e.g., an original and a grayscale edit).

## Solution

When a naming conflict occurs (multiple files mapping to the same target name), compare content hashes (xxh128) to distinguish genuine duplicates from distinct files.

- **Different hash** = distinct file → sequential number (`-001`, `-002`, `-003`)
- **Same hash** = genuine duplicate → `-duplicate-NNN` suffix on the sub-group number

## Scope

Applies to all rename commands via `DuplicateDetectionService`, EXCEPT `rename:hash` which already groups by content hash and skips the sub-grouping phase.

## Naming scheme

### No conflict (single file per target name)

No change. File gets the target name as-is.

```
2025-01-01_00-02-28.jpg
2025-01-01_00-02-28.mov           (companion)
```

### Conflict with distinct content

All files get sequential numbers. Companions inherit their image's number.

```
2025-01-01_00-02-28-001.jpg       (hash abc123)
2025-01-01_00-02-28-001.mov       (companion of -001)
2025-01-01_00-02-28-002.jpg       (hash def456, grayscale edit)
2025-01-01_00-02-28-003.jpg       (hash ghi789, different crop)
```

### Conflict with genuine duplicates

Duplicates attach to the sub-group they belong to.

```
2025-01-01_00-02-28-001.jpg                  (hash abc123, original)
2025-01-01_00-02-28-001.mov                  (companion)
2025-01-01_00-02-28-001-duplicate-001.jpg    (hash abc123, copy of original)
2025-01-01_00-02-28-002.jpg                  (hash def456, grayscale edit)
2025-01-01_00-02-28-002-duplicate-001.jpg    (hash def456, copy of edit)
```

### Backward compatibility

Files renamed under the old scheme (`basename-duplicate-NNN`) are still recognized on rerun. They are treated as duplicates in the first sub-group (number -001).

## Performance

Content hashing is lazy: only computed when a naming conflict exists (group has >1 file). Single-file groups skip hashing entirely.

## Output labels

- `[R]` = rename (distinct file, gets sequential number or plain name)
- `[D]` = duplicate (genuine duplicate, has `-duplicate-NNN` in name)
- `[O]` = no-op (source = target, nothing to do)

Sequential-numbered files (`-001`, `-002`) are NOT duplicates and receive `[R]`. Only files with `-duplicate-` in their suffix receive `[D]`. The `--skip-duplicates` flag skips only `[D]` files, never `[R]` files.

## Implementation

### Changes to DuplicateDetectionService

`createDuplicateFilenames()` gains a new phase between rename creation and suffix assignment:

1. **Create initial renames** (existing) — map source files to target filenames
2. **NEW: Sub-group by content hash** — for groups with >1 file (excluding companions):
   - Compute xxh128 hash for each file via `SafeHashCalculator`
   - Sub-group files by hash value
   - Assign sequential numbers to each unique hash (`-001`, `-002`, ...)
   - Sub-group ordering: first occurrence in the iterator determines the number
   - Within each sub-group, first file is canonical, rest are duplicates
3. **Assign suffixes** (modified) — combine sub-group number + duplicate suffix

### Skipping for rename:hash

The sub-grouping phase is skipped when files are already grouped by content hash. Detection: if the group's duplicate identifier was produced by `ContentHashStrategy` (i.e., is a raw hash value, not a `live-photo:` prefix or filename-based), sub-grouping adds no value. Implementation: add a `bool $contentHashGrouping` flag passed from the command to `createDuplicateFilenames()`, defaulting to `false`. `RenameByHashCommand` sets it to `true`.

### New dependency

`DuplicateDetectionService` receives `SafeHashCalculator` via constructor injection. With autowiring enabled in `Services.yaml`, no manual wiring needed.

### Idempotency

The `preAssignExistingDuplicateSuffixes()` regex must handle both formats:
- Legacy: `basename-duplicate-NNN` (from old runs)
- New: `basename-NNN-duplicate-NNN` (from new runs)
- New: `basename-NNN` (sequential number without duplicate)

Files with existing suffixes keep their names. Sub-group number assignment skips reserved numbers.

### isDuplicateTarget update

`FileSystemService::renameFiles()` currently determines `isDuplicateTarget` by comparing basenames. This must change: a file is a duplicate target only if its name contains `DUPLICATE_IDENTIFIER` (`-duplicate-`). Sequential-numbered files (`-001`) without `-duplicate-` are NOT duplicate targets.

This is already the current implementation (changed during the cleanup), so no further changes needed.

### Companion handling

Live Photo companions inherit the sub-group number of their paired image. Companions are excluded from hashing (different media type). Companion-to-companion comparison is not performed — companions always follow their image's sub-group.

### Error handling

If `SafeHashCalculator` throws `HashComputationException` for a file, log the error and treat the file as having a unique hash (its own sub-group). The file receives its own sequential number.

### Deterministic ordering

Sub-groups are numbered by first occurrence in the file iterator. Within a sub-group, the first file encountered is canonical. This ensures consistent numbering across reruns if the directory structure and file set are unchanged.

### Summary metrics

The summary table gains a new metric: `Naming collisions` (count of groups where hashing was needed). Existing `Duplicates found` counts only genuine duplicates (same hash).

## What does NOT change

- Single files per target name: no hash, no number
- Live Photo pairing: Content-ID-based as today
- `rename:hash` command: skips sub-grouping, behavior unchanged
- `--copy`, `--dry-run`: work as before
- `--skip-duplicates`: skips only genuine duplicates (`[D]`), not sequential-numbered files (`[R]`)
