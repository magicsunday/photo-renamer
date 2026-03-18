# Design Principles Refactor

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix KISS/SOLID/DRY/YAGNI/LoD/SoC violations introduced by the skipped-files and show-filter features.

**Architecture:** Extract `OutputEntryTag` enum as single source of truth for tag letters/formatting (DRY). Replace `$lastSkipReason` side-effect with explicit `TargetFileResult` return (LoD). Break `FileSystemService::renameFiles()` god method into focused private methods (SRP/SoC).

**Tech Stack:** PHP 8.5, PHPUnit 12, PHPStan max level

---

### Task 1: OutputEntryTag Enum

Eliminates the DRY violation: tag letter, formatted tag string, and color are currently derived in two separate places. The enum becomes the single source of truth.

**Files:**
- Create: `src/Model/OutputEntryTag.php`
- Create: `tests/Unit/Model/OutputEntryTagTest.php`

- [ ] **Step 1: Write test for OutputEntryTag**

```php
#[CoversClass(OutputEntryTag::class)]
final class OutputEntryTagTest extends TestCase
{
    #[Test]
    public function itReturnsCorrectLetters(): void
    {
        self::assertSame('R', OutputEntryTag::Rename->letter());
        self::assertSame('D', OutputEntryTag::Duplicate->letter());
        self::assertSame('O', OutputEntryTag::Original->letter());
        self::assertSame('S', OutputEntryTag::Skipped->letter());
        self::assertSame('E', OutputEntryTag::Error->letter());
    }

    #[Test]
    public function itReturnsFormattedTags(): void
    {
        self::assertSame('<fg=green>[R]</>', OutputEntryTag::Rename->formattedTag());
        self::assertSame('<fg=red>[D]</>', OutputEntryTag::Duplicate->formattedTag());
        self::assertSame('<fg=blue>[O]</>', OutputEntryTag::Original->formattedTag());
        self::assertSame('<fg=gray>[S]</>', OutputEntryTag::Skipped->formattedTag());
        self::assertSame('<fg=red>[E]</>', OutputEntryTag::Error->formattedTag());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make unit`
Expected: FAIL — class not found

- [ ] **Step 3: Implement OutputEntryTag**

```php
enum OutputEntryTag: string
{
    case Rename    = 'R';
    case Duplicate = 'D';
    case Original  = 'O';
    case Skipped   = 'S';
    case Error     = 'E';

    public function letter(): string
    {
        return $this->value;
    }

    public function formattedTag(): string
    {
        return sprintf('<fg=%s>[%s]</>', $this->color(), $this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Rename   => 'green',
            self::Duplicate => 'red',
            self::Original => 'blue',
            self::Skipped  => 'gray',
            self::Error    => 'red',
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make test`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git commit -m "refactor: extract OutputEntryTag enum as single source of truth for tag formatting"
```

---

### Task 2: TargetFileResult Value Object

Replaces the `$lastSkipReason` side-effect property with an explicit return value from `getTargetFileInfo()`. Fixes the Law of Demeter violation (implicit state communication).

**Files:**
- Create: `src/Model/TargetFileResult.php`
- Create: `tests/Unit/Model/TargetFileResultTest.php`
- Modify: `src/Service/DuplicateDetectionService.php` — use TargetFileResult, remove `$lastSkipReason`

- [ ] **Step 1: Write test for TargetFileResult**

```php
#[CoversClass(TargetFileResult::class)]
final class TargetFileResultTest extends TestCase
{
    #[Test]
    public function successCarriesTargetFile(): void
    {
        $file   = new SplFileInfo('/tmp/target.jpg');
        $result = TargetFileResult::success($file);

        self::assertSame($file, $result->getTargetFile());
        self::assertNull($result->getSkipReason());
        self::assertFalse($result->isSkipped());
        self::assertFalse($result->isError());
    }

    #[Test]
    public function skippedCarriesReason(): void
    {
        $result = TargetFileResult::skipped('no capture date');

        self::assertNull($result->getTargetFile());
        self::assertSame('no capture date', $result->getSkipReason());
        self::assertTrue($result->isSkipped());
        self::assertFalse($result->isError());
    }

    #[Test]
    public function errorCarriesReasonAndFlag(): void
    {
        $result = TargetFileResult::error('audio sample entry vendor must be 0');

        self::assertNull($result->getTargetFile());
        self::assertTrue($result->isSkipped());
        self::assertTrue($result->isError());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

- [ ] **Step 3: Implement TargetFileResult**

```php
final readonly class TargetFileResult
{
    private function __construct(
        private ?SplFileInfo $targetFile,
        private ?string $skipReason,
        private bool $isError,
    ) {
    }

    public static function success(SplFileInfo $targetFile): self
    {
        return new self($targetFile, null, false);
    }

    public static function skipped(string $reason): self
    {
        return new self(null, $reason, false);
    }

    public static function error(string $reason): self
    {
        return new self(null, $reason, true);
    }

    // Getters: getTargetFile(), getSkipReason(), isSkipped(), isError()
}
```

- [ ] **Step 4: Run tests**

- [ ] **Step 5: Refactor DuplicateDetectionService to use TargetFileResult**

In `getTargetFileInfo()`:
- Return `TargetFileResult::success(...)`, `::skipped(...)`, or `::error(...)`
- Change return type to `TargetFileResult`
- Remove `$lastSkipReason` property

In `groupFilesByDuplicateIdentifier()`:
- Replace `if (!$targetFileInfo instanceof SplFileInfo)` with `if ($result->isSkipped())`
- Create `SkippedFile` from `$result->getSkipReason()` and `$result->isError()` directly

- [ ] **Step 6: Run tests, verify DuplicateDetectionServiceTest still passes**

- [ ] **Step 7: Commit**

```bash
git commit -m "refactor: replace lastSkipReason side-effect with explicit TargetFileResult return"
```

---

### Task 3: Break Up FileSystemService::renameFiles() God Method

Extract three private methods to separate the concerns: entry building, rendering, and summary. Use `OutputEntryTag` enum throughout.

**Files:**
- Modify: `src/Service/FileSystemService.php`

- [ ] **Step 1: Store `OutputEntryTag` in arrays instead of duplicated tag logic**

Replace:
```php
if ($isDuplicateTarget) {
    $statusTag = '<fg=red>[D]</>';
} elseif ($isCanonicalEntry) {
    $statusTag = '<fg=blue>[O]</>';
} else {
    $statusTag = '<fg=green>[R]</>';
}
```

With:
```php
if ($isDuplicateTarget) {
    $tag = OutputEntryTag::Duplicate;
} elseif ($isCanonicalEntry) {
    $tag = OutputEntryTag::Original;
} else {
    $tag = OutputEntryTag::Rename;
}
```

Store `'tag' => $tag` in the array. Derive formatted tag via `$entry['tag']->formattedTag()`.

For skip entries: `'tag' => $skippedFile->isError() ? OutputEntryTag::Error : OutputEntryTag::Skipped`.

Remove the second tag-letter computation (lines 279-288) — use `$entry['tag']->letter()` for filtering.

- [ ] **Step 2: Extract `buildOutputEntries()` private method**

Signature:
```php
/**
 * @return list<array{...}> Sorted output entries
 */
private function buildOutputEntries(
    FileDuplicateCollection $fileDuplicateCollection,
    RenameOptions $options,
    ?string $sourceBaseDirectory,
    ?string $targetBaseDirectory,
): array
```

Moves: lines 170-243 (entry building + sorting) into this method.

- [ ] **Step 3: Extract `renderOutputEntries()` private method**

Signature:
```php
/**
 * @param list<array{...}> $outputEntries
 * @param list<string>|null $showFilter
 *
 * @return array{fileCount: int, duplicateCount: int, plannedMoves: int, plannedCopies: int, plannedSkips: int}
 */
private function renderOutputEntries(
    array $outputEntries,
    int $maxFilenameLength,
    RenameOptions $options,
    array &$occupiedPaths,
): array
```

Moves: the output loop (lines 254-329) + counting logic.

- [ ] **Step 4: Extract `renderSummary()` private method**

Signature:
```php
private function renderSummary(
    int $scannedFiles,
    int $skippedCount,
    int $errorCount,
    int $fileCount,
    int $duplicateCount,
    int $plannedMoves,
    int $plannedCopies,
    int $plannedSkips,
    int $livePhotoGroups,
    int $namingCollisions,
    bool $dryRun,
): void
```

Moves: lines 331-391. Hmm, too many parameters. Better: accept a summary DTO or just pass `RenameOptions` + counters array.

Simpler: use a single array parameter:
```php
/** @param array<string, int> $counters */
private function renderSummary(array $counters, bool $dryRun): void
```

- [ ] **Step 5: The `renameFiles()` method becomes an orchestrator**

```php
public function renameFiles(...): void
{
    $sourceBase = $this->normalizeBaseDirectory($options->sourceBaseDirectory);
    $targetBase = $this->normalizeBaseDirectory($options->targetBaseDirectory);

    [$outputEntries, $maxFilenameLength, $totalOperations, $livePhotoGroups, $skippedCount, $errorCount]
        = $this->buildOutputEntries($fileDuplicateCollection, $options, $sourceBase, $targetBase);

    $occupiedPaths = $this->buildOccupiedPaths($fileDuplicateCollection, $targetBase, $sourceBase);

    $this->io->newLine();
    $this->io->text(sprintf('<fg=cyan>%s files</>', $options->copyFiles ? 'Copying' : 'Renaming'));
    $this->io->newLine();

    $counters = $this->renderOutputEntries($outputEntries, $maxFilenameLength, $options, $occupiedPaths);

    $this->renderSummary(
        scannedFiles: $options->scannedFiles ?? $totalOperations,
        skippedCount: $skippedCount,
        errorCount: $errorCount,
        livePhotoGroups: $livePhotoGroups,
        namingCollisions: $options->namingCollisions,
        dryRun: $options->dryRun,
        ...$counters,
    );
}
```

- [ ] **Step 6: Run `make test`, all green**

- [ ] **Step 7: Commit**

```bash
git commit -m "refactor: extract output entry building, rendering, and summary from renameFiles()"
```

---

### Task 4: Clean up RenameOptions

Remove `showFilter` from `RenameOptions` — it's a presentation concern, not a pipeline option. Pass it as a separate parameter.

**Files:**
- Modify: `src/Model/RenameOptions.php` — remove `showFilter`
- Modify: `src/Service/FileSystemService.php` — accept `showFilter` as parameter
- Modify: `src/Service/FileSystemServiceInterface.php` — update signature
- Modify: `src/Command/AbstractRenameCommand.php` — pass `showFilter` separately
- Modify: `tests/Unit/Model/RenameOptionsTest.php` — remove showFilter assertion

- [ ] **Step 1: Remove `showFilter` from `RenameOptions`**

- [ ] **Step 2: Add `?array $showFilter = null` parameter to `renameFiles()`**

- [ ] **Step 3: Update `FileSystemServiceInterface`**

- [ ] **Step 4: Update `AbstractRenameCommand::processAndRenameFiles()`**

Pass `$this->showFilter` as second argument.

- [ ] **Step 5: Run `make test`, all green**

- [ ] **Step 6: Commit**

```bash
git commit -m "refactor: move showFilter out of RenameOptions into direct parameter"
```
