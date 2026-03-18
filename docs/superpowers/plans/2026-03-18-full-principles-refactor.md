# Full Design Principles Refactor

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all KISS/SOLID/DRY/YAGNI/GRASP/LoD/SoC violations found in the full codebase review (14 findings).

**Architecture:** Bottom-up — start with leaf-level DRY helpers and consistency fixes, then work up to interface changes and structural refactorings. Each task is self-contained and leaves all 188+ tests green.

**Tech Stack:** PHP 8.5, PHPUnit 12, PHPStan max level, Docker (`make test`)

---

### Task 1: DRY Helpers on Constants (#3, #8)

Two patterns repeated across 5+ files: `$file->getBasename('.' . $file->getExtension())` (11 occurrences) and duplicate-suffix stripping regex (2 places with inconsistent anchoring/escaping).

**Files:**
- Modify: `src/Constants.php` — add two static helper methods
- Modify: `src/Strategy/RenameStrategy/InheritFilenameStrategy.php` — use Constants helper
- Modify: `src/Service/FileSystemService.php` — use Constants helper (findAvailableDuplicateTarget + buildOutputEntries)
- Modify: `src/Service/DuplicateDetectionService.php` — use basename helper
- Modify: `src/Service/HashSubGroupingService.php` — use basename helper
- Modify: `src/Service/LivePhoto/LivePhotoBasenameTargetMap.php` — use basename helper
- Modify: `src/Service/LivePhoto/LivePhotoPairingService.php` — use basename helper
- Test: existing tests cover all call sites

- [ ] Add `Constants::basenameWithoutExtension(SplFileInfo $file): string` — returns `$file->getBasename('.' . $file->getExtension())`, handles empty extension edge case
- [ ] Add `Constants::stripDuplicateSuffix(string $basename): string` — uses `preg_replace` with `preg_quote` and `$` anchor
- [ ] Write unit tests for both helpers (empty extension, no suffix, nested suffix)
- [ ] Replace all 11 `getBasename('.' . getExtension())` call sites with `Constants::basenameWithoutExtension()`
- [ ] Replace `InheritFilenameStrategy::removeDuplicateFileIdentifier()` body with `Constants::stripDuplicateSuffix()`
- [ ] Replace `FileSystemService::findAvailableDuplicateTarget()` inline regex with `Constants::stripDuplicateSuffix()`
- [ ] Run `make test`, commit: `refactor: extract DRY helpers for basename and duplicate suffix stripping`

---

### Task 2: Command Consistency (#9, #10, #13)

Three consistency issues across commands: missing `is_dir()` guard, inconsistent error handling style, inconsistent strategy caching.

**Files:**
- Modify: `src/Command/AbstractRenameCommand.php` — add `is_dir()` check in base `createFileIterator()`
- Modify: `src/Command/RenameByDatePatternCommand.php:101-102` — replace `throw RuntimeException` with `$this->io->error()` + `return self::FAILURE`
- Modify: `src/Command/RenameByHashCommand.php` — cache strategies in properties
- Modify: `src/Command/RenameLowerCaseCommand.php` — cache strategies in properties
- Modify: `src/Command/RenameByPatternCommand.php` — cache strategies in properties
- Modify: `src/Command/RenameByDatePatternCommand.php` — cache strategies in properties

- [ ] Add `is_dir($this->sourceDirectory)` guard in `AbstractRenameCommand::createFileIterator()` that throws `RuntimeException` if directory doesn't exist — all subclass overrides benefit because they call `$this->fileSystemService->createFileIterator($this->sourceDirectory, ...)` which would otherwise throw `UnexpectedValueException`
- [ ] In `RenameByDatePatternCommand::executeCommand()` line 101-102: replace `throw new RuntimeException(...)` with `$this->io->error('...')` + `return self::FAILURE` to match `RenameByPatternCommand` style
- [ ] Add lazy-init caching (`$this->renameStrategy ??= new ...()`) for strategies in all 4 non-Exif commands, matching the pattern established by `RenameByExifDateCommand`
- [ ] Run `make test`, commit: `refactor: standardize command error handling, directory validation, and strategy caching`

---

### Task 3: ExifMetadataProvider Exception Type (#14)

Re-wrapping `ExifMetadataReadException` as `TargetFilenameException` loses the specific type. Since `ExifMetadataReadException extends TargetFilenameException`, just re-throw the original.

**Files:**
- Modify: `src/Metadata/ExifMetadataProvider.php:115-124`

- [ ] Replace `throw new TargetFilenameException($exception->getMessage(), ...)` with `throw $exception` (the original `ExifMetadataReadException` IS a `TargetFilenameException`, so all catch sites still work)
- [ ] Remove unused `TargetFilenameException` import
- [ ] Run `make test`, commit: `fix: preserve ExifMetadataReadException type instead of re-wrapping`

---

### Task 4: DatePatternFilenameStrategy Cleanup (#11, #12)

30-line closure should be a named method; dead `'$1'` concatenation in constructor.

**Files:**
- Modify: `src/Strategy/RenameStrategy/DatePatternFilenameStrategy.php`

- [ ] Remove the dead `'$1'` concatenation from the `matchAll` call in the constructor (line ~60): change `$this->replacement . '$1'` to `$this->replacement`
- [ ] Extract the `replaceCallback` closure body into a private method `buildFormattedDateFromMatches(array $matches): string`
- [ ] Run `make test`, commit: `refactor: extract date format callback, remove dead concatenation`

---

### Task 5: ISP — Extract isLivePhotoStill (#4)

`HashSubGroupingServiceInterface` bundles hash sub-grouping with media type classification. These are unrelated responsibilities.

**Files:**
- Create: `src/Service/MediaTypeClassifierInterface.php` — single method `isLivePhotoStill(SplFileInfo): bool`
- Create: `src/Service/MediaTypeClassifier.php` — implementation (move logic from HashSubGroupingService)
- Modify: `src/Service/HashSubGroupingServiceInterface.php` — remove `isLivePhotoStill()`
- Modify: `src/Service/HashSubGroupingService.php` — implement `MediaTypeClassifierInterface` instead, keep `LIVE_PHOTO_STILL_EXTENSIONS` const
- Modify: `src/Service/DuplicateDetectionService.php` — inject `MediaTypeClassifierInterface` for `isLivePhotoStill()` calls
- Modify: `src/Dependencies.php` — wire new interface
- Test: add `MediaTypeClassifierTest`, update `DuplicateDetectionServiceTest` if needed

- [ ] Write test for `MediaTypeClassifier::isLivePhotoStill()` with jpg/heic/mov/mp4 extensions
- [ ] Create interface + implementation
- [ ] Remove `isLivePhotoStill()` from `HashSubGroupingServiceInterface`
- [ ] Have `HashSubGroupingService` implement both interfaces (ISP: callers depend only on what they use)
- [ ] Update `DuplicateDetectionService` constructor to accept `MediaTypeClassifierInterface`
- [ ] Update DI wiring in `Dependencies.php`
- [ ] Run `make test`, commit: `refactor: extract MediaTypeClassifier from HashSubGroupingService (ISP)`

---

### Task 6: Split RenameOptions into Config + Results (#7)

`RenameOptions` mixes user-supplied config with pipeline-computed results.

**Files:**
- Modify: `src/Model/RenameOptions.php` — remove `scannedFiles`, `namingCollisions`, `skippedFiles`
- Create: `src/Model/RenameResult.php` — carries `scannedFiles`, `namingCollisions`, `skippedFiles`
- Modify: `src/Service/FileSystemService.php` — accept `RenameResult` as additional parameter
- Modify: `src/Service/FileSystemServiceInterface.php` — update signature
- Modify: `src/Command/AbstractRenameCommand.php` — create `RenameResult` separately
- Update: `tests/Unit/Model/RenameOptionsTest.php` — remove result fields
- Create: `tests/Unit/Model/RenameResultTest.php`

- [ ] Write test for `RenameResult` (value object with scannedFiles, namingCollisions, skippedFiles)
- [ ] Create `RenameResult` class
- [ ] Remove result fields from `RenameOptions`
- [ ] Add `RenameResult` parameter to `FileSystemService::renameFiles()` and interface
- [ ] Update `AbstractRenameCommand::processAndRenameFiles()` to pass both objects
- [ ] Update `RenameOptionsTest`
- [ ] Run `make test`, commit: `refactor: split RenameOptions into config (RenameOptions) and results (RenameResult)`

---

### Task 7: DRY Occupied Paths Scanning (#6)

Identical target-directory scanning logic in `DuplicateDetectionService` and `FileSystemService`.

**Files:**
- Modify: `src/Service/FileSystemService.php` — make `buildOccupiedPaths()` public static or move to shared helper
- Modify: `src/Service/DuplicateDetectionService.php` — use the shared method instead of inline scanning

The simplest approach: make `FileSystemService::buildOccupiedPaths()` a public static method (it has no instance dependencies) and call it from both places. Or extract to a standalone `OccupiedPathIndex` class.

- [ ] Extract occupied paths building into a standalone static method or class
- [ ] Replace both inline scanning blocks with the shared method
- [ ] Run `make test`, commit: `refactor: centralize occupied paths scanning (DRY)`

---

### Task 8: Temporal Coupling in DuplicateDetectionService (#5)

`setSourceDirectory()`/`setTargetDirectory()` must be called before `groupFilesByDuplicateIdentifier()` but the API doesn't enforce this.

**Files:**
- Modify: `src/Service/DuplicateDetectionService.php` — pass directories as method parameters
- Modify: `src/Service/DuplicateDetectionServiceInterface.php` — update signatures
- Modify: `src/Command/AbstractRenameCommand.php` — pass directories to method calls
- Modify: `src/Command/RenameByExifDateCommand.php` — if it accesses directories

- [ ] Add `string $sourceDirectory, string $targetDirectory` parameters to `groupFilesByDuplicateIdentifier()` and `createDuplicateFilenames()`
- [ ] Remove `setSourceDirectory()`, `setTargetDirectory()` and the uninitialized properties
- [ ] Update interface
- [ ] Update callers in `AbstractRenameCommand` (currently in `normalizeDirectoryPaths()` + `processAndRenameFiles()`)
- [ ] Run `make test`, commit: `refactor: remove temporal coupling from DuplicateDetectionService`

---

### Task 9: SRP — Extract Rendering from FileSystemService (#1)

`FileSystemService` mixes file I/O with console output rendering. The private methods we extracted (`buildOutputEntries`, `renderOutputEntries`, `renderSummary`) are rendering concerns that belong in a dedicated class.

**Files:**
- Create: `src/Service/RenameOutputRenderer.php` — rendering methods
- Create: `src/Service/RenameOutputRendererInterface.php` — contract
- Modify: `src/Service/FileSystemService.php` — delegate rendering to injected renderer
- Modify: `src/Service/FileSystemServiceInterface.php` — simplify (no rendering)
- Modify: `src/Dependencies.php` — wire new service
- Create: `tests/Unit/Service/RenameOutputRendererTest.php`

- [ ] Extract `buildOutputEntries()`, `renderOutputEntries()`, `renderSummary()`, `relativizePath()`, `isLivePhotoIdentifier()` into `RenameOutputRenderer`
- [ ] `FileSystemService` keeps: `createFileIterator()`, `copyOrMoveFile()`, `findAvailableDuplicateTarget()`, `buildOccupiedPaths()`
- [ ] The orchestration in `renameFiles()` moves to `RenameOutputRenderer` or stays in `FileSystemService` as a thin coordinator
- [ ] Wire in DI container
- [ ] Run `make test`, commit: `refactor: extract RenameOutputRenderer from FileSystemService (SRP)`

---

### Task 10: SRP — Break Up groupFilesByDuplicateIdentifier (#2)

The 200-line method handles sorting, indexing, progress bars, content ID caching, skip tracking, and group management. Extract the content identifier cache logic into a private method.

**Files:**
- Modify: `src/Service/DuplicateDetectionService.php`

- [ ] Extract content identifier cache management into `resolveContentIdentifierCache()` or similar
- [ ] Extract the skip/pending-file handling block into `handleSkippedFile()`
- [ ] Keep the main loop as an orchestrator that calls these helpers
- [ ] Run `make test`, commit: `refactor: break up groupFilesByDuplicateIdentifier into focused methods`
