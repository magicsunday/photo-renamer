# Usage guide

Examples use the Docker wrapper with your collection mounted at `/media`. Build the runtime first, keep a backup and stop other media writers before mutations. The optional standalone executable accepts ordinary host paths instead.

## Collection workflow

The recommended 5-phase workflow for tidying a large photo/video collection:

### Phase 1: Fix metadata BEFORE renaming

```bash
# Step 1: Analyse the collection
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:verify /media # overview with issue counts
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:verify --detail /media # per-file diagnosis with fix commands
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:verify --detail /media/single-file.mov # check a single file

# Step 2: Fix files with no metadata (writes date extracted from filename)
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:write-date --reason=nodata /media
# Step 3: Fix files using only ModifyDate (0x0132) instead of DateTimeOriginal
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:write-date --reason=fallback /media
# Step 4: Fix QuickTime videos with ambiguous UTC timestamps
# Apple/DJI cameras (CreateDate is real UTC → convert to local time):
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:write-date --reason=timezone --timezone=Europe/Berlin /media
# Android/Panasonic/Canon cameras (CreateDate is already local time stored as "UTC"):
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:write-date --reason=timezone --timezone=Europe/Berlin --local-as-utc /media
```

Rename workflows report scan findings before processing. Affected-file counts are distinct paths; one file may contribute several findings (for example a fallback date and a Live Photo conflict). Each category retains its own count.

The `--detail` flag on `rename:verify` shows per-file diagnostics with copy-pasteable fix commands:

```
  clip.mov (2.3 KB)
     Problem:    Ambiguous timezone — QuickTime UTC without offset
     Metadata:   CreateDate (UTC) = 2025:03:10 14:00:00
     Fix:        rename:write-date --reason=timezone --timezone=Europe/Berlin '/path/to/clip.mov'
```

> **About timestamps:** EXIF `DateTimeOriginal` is always local time (the time shown on the camera display). QuickTime videos (MOV, MP4, M4V) store `CreateDate` in UTC without timezone info — the `[W]` warning flags these. There are two cases:
>
> - **Apple/DJI cameras** store real UTC. `--reason=timezone` converts it to local time: `14:00 UTC` → `16:00+02:00`.
> - **Non-Apple cameras** (Android, Panasonic, Canon) store local time as "UTC". Use `--local-as-utc` to add the offset without converting: `14:00 "UTC"` → `14:00+02:00`.
>
> Both modes write `Keys:CreationDate` with the timezone offset. The default mode preserves raw QuickTime timestamps. With `--local-as-utc`, the local clock is retained in Keys while raw QuickTime `CreateDate`/`ModifyDate` are normalized to actual UTC: local `14:00+02:00` becomes raw `12:00 UTC`.
>
> `--force` repairs timezone tags even when existing Keys appear reliable, using the raw movie-header timestamp. Apply forced local-clock reinterpretation deliberately: repeating `--force --local-as-utc` reinterprets the already normalized raw clock again.

### Phase 2: Rename

```bash
# Step 5: Preview
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:exif --dry-run /media
# Step 6: Execute (prompts for confirmation)
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:exif /media
```

Renames all photos and videos to `YYYY-MM-DD_HH-MM-SS-mmm.ext`. Extensions are normalised automatically (e.g. `JPEG` → `jpg`). Live Photo pairs (still + video) receive the same base name. Files with distinct content sharing the same timestamp get sequential sub-group numbers (`-002`, `-003`, ...), while visually identical files (format conversions, re-imports) are merged via perceptual hashing. True duplicates get `-duplicate-NNN` suffixes.

Use `--target-filename-pattern` (short form `-p`) with a PHP date format to customize the EXIF filename, for example `rename:exif --dry-run -p Y_m_d_H_i_s_v /media`.

Classification distinguishes matching content hashes (`exact`), accepted visual similarity (`perceptual`), rejected comparisons (`different`) and unavailable evidence (`uncertain`). Decode/hash failures, color vetoes and the effective pixel-difference threshold cannot be overridden by a matching filename or an identical Live Photo companion clip. Companion identity keeps pairing intact while distinct still images retain separate subgroups. Classification hashes are analysis evidence; permanent deletion still requires the separate fresh SHA-256 check described below.

Use `--list-all` to see classification reasons in the EXIF decision log. Each group retains one example and a comparison count per reason, rather than every pair. `-vvv` reports individual decisions with available measurements; missing signals are reported as unavailable, never as a measured zero distance.

### Phase 3: Post-rename verification and drift fix

```bash
# Step 7: Confirm all metadata issues resolved
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:verify /media
# Step 8: Fix metadata that disagrees with the (now reliable) filename dates
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:write-date --reason=drift /media
# Step 9: Re-run to apply drift fixes (minimal changes expected)
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:exif /media
```

> **Why drift comes after rename:** `--reason=drift` compares filename dates with metadata dates. On fresh collections with camera names (`IMG_1234.jpg`), there is no filename date to compare. Only after `rename:exif` produces date-based names does drift detection work.

### Phase 4: Final verification

```bash
# Step 10: Verify idempotency (must show 0 changes)
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:exif --dry-run /media
```

### Phase 5: Cleanup

```bash
# Step 11: Preview which duplicates would be moved
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:dedup --dry-run /media
# Step 12: Move duplicates (prompts for confirmation)
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:dedup /media
# Or delete freshly verified byte-identical duplicates
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:dedup --delete /media
```

Files with `-duplicate-NNN` suffixes are cleanup candidates; a suffix alone does not prove identical content. The default action moves candidates to quarantine for review. `--delete` requires fresh SHA-256 equality with a retained original and unchanged file state during verification, after confirmation. Different contents, cross-format conversions and unavailable evidence block deletion and produce a nonzero exit code. `--delete --dry-run` applies the same verification without deleting files. Orphaned candidates are skipped with a warning. State checks do not provide an atomic transaction against concurrent external writers; keep other media writers stopped during cleanup.

Quarantine uses `_duplicates` by default. `--target=review/duplicates` selects another relative subdirectory of the canonical source directory (the containing directory for a single-file source). Absolute paths, parent traversal and the source root itself are rejected. Existing symlinks in any destination component, including dangling links and links pointing inside the source tree, are rejected. The whole batch is checked before any move, with fresh checks around directory creation and before each rename; dry-run validates the same boundary without creating directories. A rejected boundary produces a nonzero exit code and retains affected source files. If a later boundary changes during execution, earlier successful moves remain in quarantine; there is no automatic rollback.

These path checks detect unsafe paths observed at the check boundaries. They do **not** make mkdir/rename atomic against a hostile process swapping directories between a check and the filesystem call. Run cleanup in a directory tree that other users/processes cannot modify concurrently, and stop import/sync jobs for its duration. Application locking alone would not constrain such external writers.

## Additional commands

```bash
# Group identical files by content hash
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:hash --dry-run /media
# Extract and rewrite date fragments in filenames
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:date --dry-run -p "/^{y}-{m}-{d}.{H}-{i}-{s}(.+)$/" -r "{Y}-{m}-{d}_{H}-{i}-{s}" /media
# Lowercase all filenames
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:lower --dry-run /media
# Rename files using a regular expression pattern
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:pattern --dry-run -p "/^(.+)(jpeg)$/" -r '${1}jpg' /media
```

## Output indicators

Each file in the output is prefixed with a status indicator:

| Tag   | Meaning                                                                              |
|-------|--------------------------------------------------------------------------------------|
| `[O]` | **Original** -- file already has the correct name; no action taken.                  |
| `[R]` | **Rename** -- file will be moved to a new name.                                      |
| `[D]` | **Duplicate** -- grouped by exact content or accepted visual similarity and receives a suffix. The comparison source is shown below; byte identity is not guaranteed. |
| `[F]` | **Fallback** -- capture date uses DateTime (0x0132) because DateTimeOriginal/CreateDate is missing. The file is withheld; a Live Photo companion can inherit this restriction from its still.     |
| `[W]` | **Warning** -- ambiguous timezone, excessive date drift or unavailable analysis; file is withheld. Drift warnings show both dates and `--max-date-drift` (default 7 days). |
| `[S]` | **Skipped** -- file has no usable metadata (no capture date found).                   |
| `[E]` | **Error** -- metadata could not be read (parser error).                               |
| `[C]` | **Candidate** -- possible Live Photo pair with different Content Identifiers; skipped for manual review. |
| `[V]` | **Review** -- video comparison needs operator review; no automatic merge. |
| `[I]` | **Info** -- supplemental information. |

`[C]` entries identify the possible counterpart on a separate line. Both filenames use the configured [terminal links](configuration.md#clickable-file-paths-in-terminal-output). This is a heuristic review hint, not proof of a wrong pair, corruption or a changed key photo. The files remain skipped; this diagnostic does not compare video frames or repair metadata.

`[D]` and `[V]` also show a separate comparison-source line with the configured optional terminal link. Duplicate references prefer the non-duplicate source with the same extension (for example MOV → MOV), rather than a future target filename. These links identify input paths at preview time; a completed rename may move those inputs. Grouping alone never authorizes deletion: `rename:dedup --delete` retains its independent fresh-byte checks.

Fallback warnings show the available replacement timestamp and suggest checking the capture date before considering `rename:write-date --reason=fallback`. For an inherited Live Photo restriction, the warning identifies the still as the cause and shows the proposed companion filename. No metadata is repaired automatically.

After processing, the summary separates `Already correctly named`, `Planned renames`, `Withheld for review`, scan skips/read errors and `Would rename` (dry-run) or `Renamed` (observed execution). Non-zero review, collision, duplicate and runtime counters are included. These categories describe different aspects and are not all additive: duplicate counts, for example, overlap planned renames. An active display filter is announced explicitly; all summary counts cover the whole run.

Completion states whether this was a preview or execution and reports the rename count. Skipped files, video review findings, failed operations or incomplete Live Photo groups produce a warning completion with the relevant counts. Existing failure exit codes remain unchanged; a review warning alone does not imply execution failure.

> **Debug output:** Use `-vvv` to see detailed merge decisions for each pairwise comparison, including RMSE, chroma difference, dHash distance, and timing.

## Behaviour and guarantees

- **Failed subgroup analysis:** `rename:exif` skips mutations in the affected capture group and shows the failure reason as a warning. Coherent existing subgroup names remain unchanged; unrelated groups can still be renamed. Retry after resolving the analysis failure. A proposed duplicate filename in a blocked entry is not proof of duplicate identity.
- **Multi-track videos:** Cross-group exact-video matching supports one video stream and at most one audio stream. A matching primary video with additional AV tracks remains a review candidate with an explicit reason; it is not automatically merged.
- **Dry-run first:** Mutating commands support `--dry-run` to preview changes; `rename:verify` is read-only.
- **Idempotent EXIF execution:** Re-running `rename:exif` on an already processed collection preserves the resolved filenames, duplicate suffixes and hash sub-group numbers.
- **Pattern rules:** `rename:pattern` applies the configured regex on every run. It is idempotent only when the generated name is unchanged by that same rule. For example, `/^a/` with replacement `aa` transforms `a.jpg` to `aa.jpg`, then to `aaa.jpg` on the next run. Use a rule that stops matching its output when repeat runs should be no-ops.
- **Smart time formatting:** When a file's EXIF date has no time information (midnight with zero subseconds), the time portion is omitted from the filename (e.g. `2011-09-09.jpg` instead of `2011-09-09_00-00-00-000.jpg`). Works with any `--target-filename-pattern`.
- **Live Photo pairing:** Still images (JPEG/HEIC/HEIF) + video companions (MOV/MP4/M4V) sharing the same Apple Content Identifier are treated as a pair. The video companion always receives the same base name as its still image, even when the video has its own (different) EXIF timestamp.
- **Unified grouping:** All files with the same EXIF date are placed into one group regardless of their Live Photo Content Identifier. This ensures consistent numbering across the entire timestamp.
- **Hash sub-grouping:** When multiple distinct files share the same EXIF date, they are grouped by content hash. True duplicates (same hash) receive `-duplicate-NNN` suffixes, while different files get sequential group numbers (`-002`, `-003`, ...).
- **Perceptual duplicate detection:** Files that are the same capture but have different content hashes (e.g., JPG↔HEIC format backups, re-imports, re-saves) are detected via a multi-stage perceptual pipeline: Stage A computes a multi-signal similarity score (dHash, wHash, HF-energy, color histogram, video duration) using Imagick with proper sRGB color normalization; Stage B applies a conservative merge policy with dHash-adaptive RMSE thresholds (permissive for identical gradient structure, strict for any change) and a chroma-aware merge veto that prevents color→grayscale conversions from being absorbed as duplicates. Still-image Stage B remains required even at dHash distance zero. Matching companion hashes or filenames never override rejected or unavailable still evidence; companion identity controls pairing, not visual equivalence.
- **Canonical selection:** EXIF prioritizes the configured format preference, then existing correct names, directory location and Live Photo metadata. Stable encounter order breaks ties.
- **Safe renames:** Planning indexes occupied paths and resolves collisions; execution rejects observed occupied target leaves, including dangling links. Cooperating mutating CLI processes share a batch lock and fresh source checks. These checks require trusted directories, the same persistent state base and stopped external writers; they do not provide atomic protection against an uncoordinated writer between observation and rename. See [Concurrent mutations](runtime-and-safety.md#concurrent-mutations).
- **Mutation scope:** Rename commands move media without intentionally deleting its contents; `rename:write-date` explicitly modifies metadata. `rename:dedup` moves candidates to quarantine by default; its explicit `--delete` mode permanently removes only freshly verified byte-identical duplicates after confirmation. Classification evidence alone never authorizes deletion. Interruption can leave a partially completed batch; journals support reconciliation, not automatic rollback.
