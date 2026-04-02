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

---

## Shared policy targets

### 1. Format preference

**Why:** The same `CANONICAL_FORMAT_PRIORITY` must mean the same thing wherever format preference is needed.

**Current status:** Extracted into `FormatPriorityResolver`.

**Next step:** Replace ad-hoc env parsing anywhere else with this resolver or a typed interface around it.

### 2. Media-family compatibility

**Why:** Commands that compare or group files need a consistent answer to "may these two files represent the same logical asset family?"

**Current status:** `MediaTypeClassifier` exists, but policy still gets reassembled at call sites.

**Next step:** Audit actual call sites first. Only introduce a dedicated policy service, e.g. `MediaCompatibilityPolicy`, if the audit finds real duplicated compatibility rules rather than one-off extension handling. That service, if justified, should answer:
- still vs still allowed?
- video vs video only?
- cross-family forbidden?
- are aliases like `jpeg` and `jpg` equivalent?

### 3. Date reliability and timezone policy

**Why:** `rename:exif`, `rename:verify`, and `rename:write-date` all expose the same user-facing question: is this timestamp trustworthy?

**Current status:** `ExifMetadataProvider::hasReliableDateTime()` is already the documented source of truth for reliability decisions. Supporting behavior around timezone handling and filename reconciliation must be evaluated relative to that existing authority, not redefined beside it. Date drift is a separate command-level policy and must not be silently folded into metadata reliability.

**Next step:** Audit concrete gaps around the existing source of truth. Only extract an additional policy/helper if a specific supporting rule is duplicated or inconsistent. Likely audit targets:
- filename-vs-metadata reconciliation
- QuickTime ambiguous timezone handling
- fallback-date semantics

### 4. Date drift policy

**Why:** `rename:verify` and `rename:write-date` both reason about how far filename dates may differ from metadata dates, but that threshold is not the same business concept as metadata reliability.

**Current status:** Drift is handled at command level and already uses shared low-level filename parsing helpers. The remaining question is whether any threshold resolution or drift categorization is duplicated enough to justify a small shared helper.

**Next step:** Audit drift handling separately from `hasReliableDateTime()` and only extract shared policy if the audit finds concrete duplication in threshold resolution or drift classification.

### 5. Console output blocks

**Why:** Commands that render many file actions benefit from a consistent, scan-friendly output style.

**Current status:** `rename:dedup` now uses a two-line action block. Other commands still vary.

**Next step:** Treat this as a low-priority audit, not a default extraction. Shared output helpers should only move into common infrastructure if at least two separate commands already need the same structure. `rename:dedup` may keep command-local formatting if no second consumer exists.

---

## Implementation phases

## Phase 1: Inventory shared-rule candidates

- [ ] Inventory direct extension comparisons, env parsing, compatibility checks, and date-reliability decisions across commands/services
- [ ] Classify each finding as one of: normalization only, true shared compatibility rule, existing central rule already present, or command-local behavior that should stay local
- [ ] Document the concrete duplication before introducing any new policy service

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

- [ ] Extract `MediaCompatibilityPolicy` only if Phase 1 found repeated compatibility logic that is broader than plain extension normalization
- [ ] Keep the API deliberately small: boolean compatibility and normalized-family helpers only
- [ ] Replace only proven duplicate logic, not all extension handling blindly

**Primary candidates:**
- `rename:dedup`
- commands/services with repeated still-vs-video compatibility logic

## Phase 3: Audit date-reliability gaps around `ExifMetadataProvider`

- [ ] Audit all call sites that reason about fallback dates, ambiguous timezone metadata, or filename reconciliation
- [ ] Confirm which parts are already correctly centralized in `ExifMetadataProvider::hasReliableDateTime()`
- [ ] Extract a supporting helper only if a concrete secondary rule is duplicated or inconsistent
- [ ] Keep `hasReliableDateTime()` as the public business concept; do not create competing definitions

**Primary commands:**
- `rename:exif`
- `rename:verify`
- `rename:write-date`

## Phase 4: Audit date drift policy separately

- [ ] Audit drift handling in `rename:verify` and `rename:write-date` without treating it as part of metadata reliability
- [ ] Decide whether threshold resolution or drift categorization is duplicated enough for a small shared helper
- [ ] Keep drift policy separate from `hasReliableDateTime()` unless an explicit architectural decision changes that

**Primary commands:**
- `rename:verify`
- `rename:write-date`

## Phase 5: Normalize lightweight output helpers

- [ ] Review output-heavy commands for repeated inline formatting blocks
- [ ] Extract helpers only where multiple commands genuinely benefit
- [ ] Avoid over-centralizing command-specific wording
- [ ] Keep `rename:dedup` formatting local unless a second real consumer appears

**Primary candidates:**
- `rename:dedup`
- legacy rename commands using summary/action lists

## Phase 6: Re-audit remaining command-local logic

- [ ] Revisit `rename:hash`, `rename:pattern`, `rename:date`, and `rename:lower`
- [ ] Decide per command: keep local, extract shared policy, or migrate to a shared helper
- [ ] Treat `rename:hash` conservatively: no media-compatibility work unless a concrete inconsistency is proven
- [ ] Document each keep/extract decision explicitly

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
