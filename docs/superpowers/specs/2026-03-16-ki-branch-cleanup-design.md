# KI-Branch Cleanup: Design Spec

## Ziel

Den KI-Branch gründlich aufräumen, bevor er in main gemergt wird. Over-Engineering und toten Code entfernen, alle Features und die Testabdeckung bewahren. Die Metadata-Extraktion soll ausschließlich über das ImageMeta-Repo laufen.

## Kontext

Der KI-Branch enthält 69 Commits mit +9.571/-1.225 Zeilen über 110 Dateien. Die Features (Live-Photo-Pairing, ImageMeta-Integration, Progress Bars, rename summary metrics) sind wertvoll. Die Architektur enthält jedoch toten Code und über-engineerte Abstraktionen, die entfernt bzw. vereinfacht werden müssen.

## Ansatz

Chirurgisch aufräumen: Toten Code löschen, Regex-DTOs vereinfachen, Meta-Dateien entfernen. Gut designte Bereiche (LivePhoto-DTOs, Collections, Pattern-Modelle, Safe-Wrapper) unangetastet lassen.

## Validierungsstrategie

Zwischen jeder Phase die vollständige CI-Pipeline laufen lassen (`composer ci:test` = lint + PHPStan + Rector + CGL + Unit-Tests). Erst wenn alle Checks grün sind, mit der nächsten Phase fortfahren.

---

## Phase 1: Toten Code und Meta-Bloat löschen

### 1.1 ExifValue-Hierarchie löschen (14 Dateien)

Die gesamte ExifValue-Hierarchie ist toter Code. Kein aktiver Produktions-Consumer außerhalb der deprecated `SafeExifReader` nutzt diese Klassen. Die aktive Metadata-Pipeline läuft über `MetadataExtractor` → `TemporalMetadata` → `ExifMetadataProvider` → `ExifData`.

**Dateien löschen:**

- `src/Strategy/RenameStrategy/Dto/ExifValue.php`
- `src/Strategy/RenameStrategy/Dto/AbstractExifValue.php`
- `src/Strategy/RenameStrategy/Dto/ExifStringValue.php`
- `src/Strategy/RenameStrategy/Dto/ExifIntegerValue.php`
- `src/Strategy/RenameStrategy/Dto/ExifFloatValue.php`
- `src/Strategy/RenameStrategy/Dto/ExifBooleanValue.php`
- `src/Strategy/RenameStrategy/Dto/ExifArrayValue.php`
- `src/Strategy/RenameStrategy/Dto/ExifNullValue.php`
- `src/Strategy/RenameStrategy/Dto/ExifValueFactory.php`
- `src/Strategy/RenameStrategy/Dto/ExifMetadataCollection.php`
- `src/Strategy/RenameStrategy/Dto/ExifRawMetadata.php`
- `src/Strategy/RenameStrategy/Dto/MetadataEntryCollection.php`
- `src/Strategy/RenameStrategy/Dto/MetadataEntry.php`
- `src/Service/Dto/ExifMetadataResult.php`

### 1.2 Deprecated Code löschen (2 Dateien + 3 QuickTime-DTOs + Tests)

**Dateien löschen:**

- `src/Service/SafeExifReader.php` (deprecated, `exif_read_data()` bereits deaktiviert)
- `src/Strategy/RenameStrategy/QuickTime/QuickTimeContentIdentifierExtractor.php` (deprecated, manuelles Binary-Parsing ersetzt durch ImageMeta)

**Verwaiste QuickTime-DTOs löschen** (einziger Consumer ist der deprecated Extractor):

- `src/Strategy/RenameStrategy/Dto/QuickTimeKey.php`
- `src/Strategy/RenameStrategy/Dto/QuickTimeMetadata.php`
- `src/Strategy/RenameStrategy/Dto/QuickTimeValue.php`

**Tests löschen:**

- `test/Unit/Service/SafeExifReaderTest.php`
- `test/Unit/Strategy/RenameStrategy/QuickTime/QuickTimeContentIdentifierExtractorTest.php`

**Verzeichnisse löschen:**

- `src/Strategy/RenameStrategy/QuickTime/` (nach Extractor-Löschung leer)
- `test/Unit/Strategy/RenameStrategy/QuickTime/` (nach Test-Löschung leer)

### 1.3 Meta-Dateien löschen (5 Dateien)

KI-generierte Dokumentation ohne Mehrwert für ein persönliches Projekt:

- `AGENTS.md`
- `config/AGENTS.md`
- `src/AGENTS.md`
- `test/AGENTS.md`
- `CONTRIBUTING.md`

### 1.4 composer.json anpassen

- `ext-exif` aus `require` entfernen (nicht mehr benötigt nach SafeExifReader-Löschung)
- `ext-dom` aus `require` entfernen (nicht im Produktionscode verwendet)

**Hinweis:** Vor dem Entfernen von `ext-exif` und `ext-dom` prüfen, ob `magicsunday/imagemeta` diese Extensions als transitive Dependency deklariert. Falls ImageMeta sie selbst benötigt ohne sie in seiner `composer.json` zu deklarieren, müssen sie im Projekt verbleiben. Falls nur Dev-Tools (PHPStan, phpdocumentor) `ext-dom` benötigen, nach `require-dev` verschieben statt löschen.

### Phase-1-Gate

`composer ci:test` muss grün sein, bevor Phase 2 beginnt.

---

## Phase 2: Regex-DTOs vereinfachen

### 2.1 Drei Klassen entfernen

**`RegexExecutionOutcome` löschen** (`src/Service/Dto/RegexExecutionOutcome.php`)

Nur intern in `SafeRegex.execute()` verwendet. Caller sehen dieses Objekt nie.

**`RegexResultInterface` löschen** (`src/Service/Dto/RegexResultInterface.php`)

Reines Marker-Interface ohne Methoden. Kein Consumer typisiert dagegen.

**`RegexReplaceResult` löschen** (`src/Service/Dto/RegexReplaceResult.php`)

Wraps einen einzelnen String. Unnötige Indirektion.

### 2.2 Behaltene Klassen anpassen

**`RegexMatchResult`** und **`RegexMatchAllResult`**: `implements RegexResultInterface` entfernen und den zugehörigen `use`-Import löschen. Die Klassen bleiben ansonsten unverändert.

### 2.3 SafeRegex umschreiben

**Imports bereinigen:** `use`-Statements für `RegexExecutionOutcome`, `RegexReplaceResult` und `RegexResultInterface` entfernen.

**`execute()` vereinfachen** -- wird zu einem reinen Error-Handler-Wrapper:

```php
/**
 * @template T
 *
 * @param callable(): T $operation
 *
 * @return T
 */
private function execute(callable $operation, string $pattern, string $context): mixed
{
    set_error_handler(
        static function (int $severity, string $message) use ($pattern, $context): never {
            throw new RegexExecutionException(
                sprintf('Regex failure while %s with pattern "%s": %s', $context, $pattern, $message),
            );
        },
    );

    try {
        return $operation();
    } finally {
        restore_error_handler();
    }
}
```

**Öffentliche Methoden** -- Null/False-Check und Exception direkt in jeder Methode:

```php
public function replace(string $pattern, string $replacement, string $subject): string
{
    return $this->execute(
        static function () use ($pattern, $replacement, $subject): string {
            $result = preg_replace($pattern, $replacement, $subject);

            if (!is_string($result)) {
                throw new RegexExecutionException(...);
            }

            return $result;
        },
        $pattern,
        'executing preg_replace',
    );
}
```

Analog für `replaceCallback()` (gibt `string` zurück), `match()` (gibt `RegexMatchResult` zurück) und `matchAll()` (gibt `RegexMatchAllResult` zurück).

### 2.4 Caller anpassen

**`PatternFilenameStrategy`**: `->result()` Aufrufe entfernen, da `replace()` jetzt direkt `string` zurückgibt.

**`DatePatternFilenameStrategy`**: `->result()` Aufrufe bei `replaceCallback()` entfernen. `match()` und `matchAll()` bleiben unverändert (gaben bereits typisierte Objekte zurück).

### 2.5 Behalten (4 Klassen)

- `SafeRegex` -- Kern-Service mit Fehlerbehandlung
- `RegexMatchResult` -- typisiertes `match()`-Ergebnis, wird von `RegexMatchCollection` konsumiert
- `RegexMatchAllResult` -- typisiertes `matchAll()`-Ergebnis, wird von `RegexMatchCollection` konsumiert
- `RegexMatchCollection` + `RegexMatchGroup` -- aktiv von `DatePatternFilenameStrategy` genutzt

### Phase-2-Gate

`composer ci:test` muss grün sein, bevor Phase 3 beginnt.

---

## Phase 3: Aufräumarbeiten

### 3.1 Leere Verzeichnisse prüfen

- `src/Strategy/RenameStrategy/QuickTime/` (bereits in Phase 1 gelöscht)
- `test/Unit/Strategy/RenameStrategy/QuickTime/` (bereits in Phase 1 gelöscht)

### 3.2 PHPStan / Rector

Keine Konfigurationsänderungen nötig -- beide Tools scannen generisch `src/` und `test/` ohne spezifische Dateireferenzen. PHPStan-Baseline (`.build/phpstan-baseline.neon`) enthält keine Referenzen auf gelöschte Klassen.

### 3.3 Finaler CI-Lauf

Vollständige Pipeline: `composer ci:test` (lint + PHPStan Level 10 + Rector + CGL + Unit-Tests). Alle Checks müssen grün sein.

---

## Nicht anfassen

Folgende Bereiche sind gut designed und bleiben unverändert:

- **LivePhoto-DTOs**: `LivePhotoPairing`, `LivePhotoPairingCollection`, `LivePhotoBasenameTargetMap`, `LivePhotoContentIdentifierTargetMap`, `LivePhotoContentIdentifierTarget`, `LivePhotoExistingFilePathnameIndex`
- **Aktive DTOs**: `ExifData`, `ContentIdentifier`, `TemporalMetadata`
- **Services**: `MetadataExtractor`, `ExifMetadataProvider`, `LivePhotoPairingService`, `DuplicateDetectionService`, `FileSystemService`
- **Safe-Wrapper**: `SafeFileReader`, `SafeHashCalculator`, `SafeRegex`
- **Collections**: `AbstractCollection`, `FileList`, `RenameList`, `FileDuplicateCollection`
- **Pattern-Modelle**: `DatePlaceholderExpressionMap`, `PatternExpression`, `PatternMatch`, `PatternMatchSet`
- **Interfaces**: `FileSystemServiceInterface`, `DuplicateDetectionServiceInterface`, `CollectionInterface`
- **Exceptions**: `ExifMetadataReadException`, `FileReadException`, `HashComputationException`, `RegexExecutionException`

---

## Zusammenfassung

| Metrik | Vorher | Nachher |
|---|---|---|
| Zu löschende Dateien (src) | -- | 22 (14 ExifValue + 3 QuickTime-DTOs + 2 deprecated + 3 Regex-DTOs) |
| Zu löschende Dateien (test) | -- | 2 (SafeExifReaderTest + QuickTimeExtractorTest) |
| Zu löschende Dateien (meta) | -- | 5 (4x AGENTS.md + CONTRIBUTING.md) |
| Zu ändernde Dateien | -- | 5 (SafeRegex + RegexMatchResult + RegexMatchAllResult + 2 Strategy-Caller) + composer.json |
| Geschätzte LOC-Einsparung | -- | ~1.800 |
| Verbleibende Src-Dateien | 83 | ~61 |
| Features | alle | alle erhalten |
| Risiko | -- | gering (toter Code + Indirektionen, CI-Gates zwischen Phasen) |
