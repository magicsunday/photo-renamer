# Decision Matrix

Reference for how every type of media file is handled by the rename pipeline.

## Date Extraction Priority

The pipeline tries these tags in order. The first match wins:

| Priority | Tag | Source | Example |
|----------|-----|--------|---------|
| 1 | DateTimeOriginal (0x9003) | EXIF | `2025:01:15 14:30:00` |
| 2 | CreateDate (0x9004) / Keys:CreationDate | EXIF / QuickTime | `2025:01:15 14:30:00+01:00` |
| 3 | QuickTime CreateDate | QuickTime atom (UTC) | `2025:01:15 13:30:00` |
| 4 | DateTime (0x0132) | EXIF ModifyDate | `2025:01:15 14:30:00` |
| 5 | Filename pattern | `rename:write-date` only | `2025-01-15_14-30-00.jpg` |

Priority 4 produces tag `[F]` (fallback). Priority 5 is a manual last resort.

## Timezone Decision Matrix

| Has EXIF 0x9003? | Has Keys:CreationDate + offset? | QuickTime container? | Result |
|---|---|---|---|
| Yes | * | * | **Reliable** -- local time |
| No | Yes | Yes | **Reliable** -- has TZ info |
| No | No | Yes (MOV/MP4/M4V) | **Ambiguous** -- `[W]` |
| No | No | HEIC/HEIF (no EXIF) | **Ambiguous** -- `[W]` |
| No | No | No (JPG/AVI) | **Reliable** -- local time assumed |
| * | * | HEIC/HEIF + has EXIF | **Reliable** -- BMFF exception |

## File Relationship Categories

### A: Byte-Identical (same content hash)

Backup copies, re-imports, cloud syncs without conversion. Result: `-duplicate-NNN`.

### B: Format Conversions (same visual content)

HEIC-to-JPG, MOV-to-MP4, JPEG quality re-save. Detected via perceptual hashing (dHash/wHash). When dHash distance = 0, Stage B (local difference analysis) is skipped to avoid false positives from compression artifacts. Result: `-duplicate-NNN`.

### C: Intentional Edits (different content, same capture moment)

Color edits, crops, retouches. Different perceptual hash, same timestamp. Result: sequential sub-group (`-002`, `-003`).

### D: Video Transformations

Trimmed videos, slow-motion exports. Different duration triggers sub-grouping. Result: `-002`.

### E: Live Photo Pairs

JPG/HEIC still + MOV video linked by Apple Content Identifier. Receive the same base name. The still is authoritative -- its quality flags (`[W]`, `[F]`) propagate to the companion video (LP atomicity).

Note: In practice, JPG/HEIC stills with EXIF DateTimeOriginal never have `[W]` (ambiguous timezone is a QuickTime-only issue). The `[W]` propagation from still to video is covered by unit tests but cannot occur with real iPhone captures. The `[F]` propagation (fallback date) is the more common real-world case.

### F: Independent Files (same timestamp)

Burst photos, HDR brackets. Different content at the same second. Result: `-002`, `-003`.

### G: Files Outside Pipeline Scope

No EXIF metadata (`[S]`), unsupported formats (PNG, RAW, MKV), corrupted files (`[E]`). These are skipped or filtered by the file iterator.

## Output Tags

| Tag | Name | Meaning |
|-----|------|---------|
| `[R]` | Rename | File will be renamed |
| `[O]` | Original | File already has the correct name |
| `[D]` | Duplicate | Byte-identical copy, gets `-duplicate-NNN` |
| `[F]` | Fallback | Date from ModifyDate (0x0132) -- unreliable |
| `[W]` | Warning | Ambiguous timezone or date drift -- skipped |
| `[C]` | Candidate | Live Photo conflict -- skipped |
| `[S]` | Skipped | No capture date found |
| `[E]` | Error | Metadata read failed |

**Tag priority chain:** `[C]` > `[W]` > `[F]` > `[D]` > `[O]` > `[R]`

Exception: `[O]` wins for no-ops (file already correctly named).

## Supported Formats

| Format | Read | Write (write-date) | Notes |
|--------|------|-------------------|-------|
| JPG/JPEG | Yes | Yes | EXIF DateTimeOriginal |
| HEIC/HEIF | Yes | Yes | ISO BMFF + EXIF |
| MOV | Yes | Yes | QuickTime atoms |
| MP4 | Yes | Yes | QuickTime atoms |
| M4V | Yes | Yes | ISO BMFF |
| AVI | Yes | No | RIFF container, no QuickTime atoms |
| PNG | No | No | No EXIF metadata |
| MKV/WebM | No | No | Not supported by imagemeta |
| RAW (CR2, ARW, NEF, ...) | No | No | Camera-specific formats |
