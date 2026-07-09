# Shared Command Policy Extraction Plan

> **Goal:** Reuse the same domain rules across commands where that brings consistency, without forcing every command through the full `rename:exif` asset-group pipeline.

**Problem:** The project currently has the right architectural instinct for `rename:exif` versus simpler commands, but some policy decisions still live too locally inside individual commands or large services. That creates divergence risk: a user-facing concept such as format priority, extension normalization, media-family compatibility, or date reliability can drift subtly between commands even when the business meaning should be identical.

**Decision:** Keep multiple execution paths where they are justified, but extract shared domain policy into small, focused services. Commands should share rules, not necessarily the same end-to-end pipeline.

**Existing architectural constraint:** End State B remains in force. `rename:exif` stays on the AssetGroup + ExecutionPlan path, the four simple legacy rename commands stay on the bounded legacy path, and `rename:verify`, `rename:write-date`, and `rename:dedup` keep their own pipelines unless a later audit proves a specific, concrete benefit for a narrower shared helper.

**Out of scope:**
- forcing `rename:hash`, `rename:pattern`, `rename:date`, `rename:lower`, `rename:verify`, `rename:write-date`, and `rename:dedup` onto `AssetGroup -> ExecutionPlan`
- duplicating `rename:exif` classification logic in every command
- introducing metadata-heavy processing into cheap filename-only commands unless a clear benefit is proven

---

## Command audit

| Command | Current path | Keep path? | Shared policy opportunity |
|--------|--------------|------------|---------------------------|
| `rename:exif` | AssetGroup + ExecutionPlan | yes | Source of truth for complex rename semantics |
| `rename:dedup` | Standalone filename-based cleanup | yes | Original matching, media-family compatibility, format priority |
| `rename:hash` | Legacy rename path | yes | Extension normalization, duplicate output policy where genuinely shared |
| `rename:pattern` | Legacy rename path | yes | Extension normalization, collision/display policy |
| `rename:date` | Legacy rename path | yes | Filename date parsing and collision/display policy where genuinely shared |
| `rename:lower` | Legacy rename path | yes | Extension normalization, collision/display policy |
| `rename:verify` | Read-only analysis path | yes | `hasReliableDateTime()`, timezone rules, media classification |
| `rename:write-date` | Metadata correction path | yes | `hasReliableDateTime()`, timezone rules, date drift policy |

**Audit result after implementation:**
- `rename:dedup`: extracted shared original matching, format preference, and media-family compatibility.
- `rename:verify` + `rename:write-date`: extracted shared date drift analysis.
- `rename:exif`/legacy pipeline overlap: extracted shared metadata-quality flag resolution for actionable fallback/timezone flags after the main reliability decision.
- extension normalization audit: no further helper justified. Remaining `strtolower($file->getExtension())` sites are either cheap membership/counting checks or intentionally preserve exact extension semantics instead of alias normalization.
- `rename:hash`: keep local. No concrete media-compatibility inconsistency found; command stays content-hash driven.
- `rename:pattern`: keep local. No shared policy need beyond the already centralized legacy renderer/output path.
- `rename:date`: keep local. Filename date parsing is already concentrated in its strategy and low-level `FileHelper` helpers.
- `rename:lower`: keep local. No duplicated domain policy beyond legacy-path collision handling already shared through existing services.

---

## Shared policy targets

### 1. Format preference

**Why:** The same `CANONICAL_FORMAT_PRIORITY` must mean the same thing wherever format preference is needed.

**Current status:** Completed. Extracted into `FormatPriorityResolver` and reused by later shared-policy work.

**Audit result:** No further extraction currently needed. Remaining lowercase-only extension handling falls into one of two buckets:
- pure membership checks such as supported-media or still/video family classification
- exact-extension grouping/counting where alias normalization would change semantics

### 2. Media-family compatibility

**Why:** Commands that compare or group files need a consistent answer to "may these two files represent the same logical asset family?"

**Current status:** Completed. `MediaCompatibilityPolicy` now centralizes the repeated still/video-family compatibility rules that were previously reassembled at call sites.

**Resulting API:**
- still vs still allowed?
- video vs video only?
- cross-family forbidden?
- are aliases like `jpeg` and `jpg` equivalent?

### 3. Date reliability and timezone policy

**Why:** `rename:exif`, `rename:verify`, and `rename:write-date` all expose the same user-facing question: is this timestamp trustworthy?

**Current status:** Audited and partially completed. `ExifMetadataProvider::hasReliableDateTime()` remains the authority. The concrete duplicated secondary rule that remained was the post-reliability fallback/timezone flag extraction, now centralized in `MetadataQualityFlagResolver`.

**Audit result:** No further helper is justified at this time for:
- filename-vs-metadata reconciliation
- QuickTime ambiguous timezone handling
- fallback-date semantics

Those concerns are already correctly anchored on `ExifMetadataProvider` and should not be split into a second authority.

### 4. Date drift policy

**Why:** `rename:verify` and `rename:write-date` both reason about how far filename dates may differ from metadata dates, but that threshold is not the same business concept as metadata reliability.

**Current status:** Completed. The duplicated filename-versus-metadata day-drift calculation is centralized in `DateDriftAnalyzer`.

**Audit result:** Threshold resolution remains correctly shared via `ConfiguresMetadataProvider::resolveMaxDateDrift()`. No additional helper is needed beyond the extracted analyzer.

### 5. Console output blocks

**Why:** Commands that render many file actions benefit from a consistent, scan-friendly output style.

**Current status:** Audited. `rename:dedup` keeps its command-local two-line action block. The legacy rename commands already share `RenameOutputRenderer`, and no second real consumer for the `dedup`-style block was found.

**Audit result:** No extraction performed and none currently justified.

---

## Implementation phases

## Phase 1: Inventory shared-rule candidates

- [x] Inventory direct extension comparisons, env parsing, compatibility checks, and date-reliability decisions across commands/services
- [x] Classify each finding as one of: normalization only, true shared compatibility rule, existing central rule already present, or command-local behavior that should stay local
- [x] Document the concrete duplication before introducing any new policy service

**Target files:**
- `src/Service/MediaTypeClassifier.php`
- `src/Service/DuplicateDetectionService.php`
- `src/Service/HashSubGroupingService.php`
- `src/Service/Pipeline/RoleAssigner.php`
- `src/Service/Dedup/DedupOriginalMatcher.php`
- `src/Command/VerifyCommand.php`
- `src/Command/WriteDateCommand.php`
- candidate legacy services/commands that compare extensions directly

## Phase 2: Extract proven low-risk policies

- [x] Extract `MediaCompatibilityPolicy` only if Phase 1 found repeated compatibility logic that is broader than plain extension normalization
- [x] Keep the API deliberately small: boolean compatibility and normalized-family helpers only
- [x] Replace only proven duplicate logic, not all extension handling blindly

**Primary candidates:**
- `rename:dedup`
- commands/services with repeated still-vs-video compatibility logic

## Phase 3: Audit date-reliability gaps around `ExifMetadataProvider`

- [x] Audit all call sites that reason about fallback dates, ambiguous timezone metadata, or filename reconciliation
- [x] Confirm which parts are already correctly centralized in `ExifMetadataProvider::hasReliableDateTime()`
- [x] Extract a supporting helper only if a concrete secondary rule is duplicated or inconsistent
- [x] Keep `hasReliableDateTime()` as the public business concept; do not create competing definitions

**Primary commands:**
- `rename:exif`
- `rename:verify`
- `rename:write-date`

## Phase 4: Audit date drift policy separately

- [x] Audit drift handling in `rename:verify` and `rename:write-date` without treating it as part of metadata reliability
- [x] Decide whether threshold resolution or drift categorization is duplicated enough for a small shared helper
- [x] Keep drift policy separate from `hasReliableDateTime()` unless an explicit architectural decision changes that

**Primary commands:**
- `rename:verify`
- `rename:write-date`

## Phase 5: Normalize lightweight output helpers

- [x] Review output-heavy commands for repeated inline formatting blocks
- [x] Extract helpers only where multiple commands genuinely benefit
- [x] Avoid over-centralizing command-specific wording
- [x] Keep `rename:dedup` formatting local unless a second real consumer appears

**Primary candidates:**
- `rename:dedup`
- legacy rename commands using summary/action lists

## Phase 6: Re-audit remaining command-local logic

- [x] Revisit `rename:hash`, `rename:pattern`, `rename:date`, and `rename:lower`
- [x] Decide per command: keep local, extract shared policy, or migrate to a shared helper
- [x] Treat `rename:hash` conservatively: no media-compatibility work unless a concrete inconsistency is proven
- [x] Document each keep/extract decision explicitly

---

## Guardrails

- Shared policy extraction must reduce duplicated domain rules, not merely move code around.
- New helper services require an audit-backed problem statement first.
- Commands should only depend on policies they actually need.
- Cheap commands must stay cheap; no metadata or perceptual-hash work should leak into filename-only commands by accident.
- `rename:exif` remains the only source of truth for the full capture-group pipeline unless a later audit proves a real need to broaden that.
- `ExifMetadataProvider::hasReliableDateTime()` remains the source of truth for date reliability unless a documented architectural decision replaces it.
- Date drift remains a separate policy from metadata reliability unless a documented architectural decision unifies them.
- If a new shared service cannot be described in one clear sentence, it is probably too broad.

---

## Acceptance criteria

- Commands that rely on format preference use the same resolver/policy
- Commands that compare file families stop hand-rolling extension compatibility where that compatibility rule is genuinely shared
- Date-reliability semantics are documented and shared where needed, without creating a second authority beside `ExifMetadataProvider`
- Date-drift handling is reviewed separately and only centralized if concrete duplication is proven
- Output helper extraction is done only where it materially improves consistency and has more than one consumer
- No command is migrated to the full `rename:exif` pipeline without a documented rationale
