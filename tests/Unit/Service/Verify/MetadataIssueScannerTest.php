<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Verify;

use DateTimeImmutable;
use DateTimeInterface;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Service\DateDriftAnalyzer;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\Verify\LivePhotoContentIdMap;
use MagicSunday\Renamer\Service\Verify\LivePhotoContentIdObservation;
use MagicSunday\Renamer\Service\Verify\MetadataIssueScanner;
use MagicSunday\Renamer\Service\Verify\VerifyCategoryCatalog;
use MagicSunday\Renamer\Service\Verify\VerifyScanResult;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function file_put_contents;
use function sprintf;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies the first-pass verify scanner that classifies per-file metadata
 * issues before the separate Live Photo completeness analysis runs.
 *
 * The scanner must preserve the command semantics for unsupported types,
 * ambiguous timezone/fallback/drift detection, and content-id collection while
 * remaining agnostic of final console formatting.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(MetadataIssueScanner::class)]
#[UsesClass(VerifyCategoryCatalog::class)]
#[UsesClass(VerifyScanResult::class)]
#[UsesClass(LivePhotoContentIdMap::class)]
#[UsesClass(LivePhotoContentIdObservation::class)]
#[UsesClass(ExifMetadataProvider::class)]
#[UsesClass(TemporalMetadata::class)]
#[UsesClass(DateDriftAnalyzer::class)]
#[UsesClass(MediaTypeClassifier::class)]
final class MetadataIssueScannerTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Verifies that unsupported file types are reported as `filetype` findings
     * while files without usable metadata become `nodata`.
     */
    #[Test]
    public function scanReportsFileTypeAndNoDataCategories(): void
    {
        $workspace = $this->createTempWorkspace('verifyscan_');
        $txtPath   = $workspace . DIRECTORY_SEPARATOR . 'notes.txt';
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . 'empty.jpg';
        file_put_contents($txtPath, 'notes');
        file_put_contents($jpgPath, 'photo');

        try {
            $scanner = $this->createScanner(new StubMetadataExtractor());

            $result = $scanner->scan(
                [new SplFileInfo($txtPath), new SplFileInfo($jpgPath)],
                $workspace,
                7,
                static fn (string $relativePath, string $absolutePath, string $category, ?DateTimeInterface $captureDateTime): string => sprintf(
                    '%s|%s',
                    $category,
                    $relativePath,
                ),
            );

            self::assertSame(2, $result->scannedFiles);
            self::assertSame(['notes.txt'], $result->categories[VerifyCategoryCatalog::FILETYPE]);
            self::assertSame(['nodata|empty.jpg'], $result->categories[VerifyCategoryCatalog::NODATA]);
        } finally {
            @unlink($txtPath);
            @unlink($jpgPath);
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that the scanner reports ambiguous timezone and drift findings and
     * still aggregates Live Photo content identifiers for the later second pass.
     */
    #[Test]
    public function scanReportsTimezoneAndDriftAndCollectsContentIdentifiers(): void
    {
        $workspace = $this->createTempWorkspace('verifyscan_');
        $movPath   = $workspace . DIRECTORY_SEPARATOR . 'clip.mov';
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-01_09-00-00.jpg';
        file_put_contents($movPath, 'video');
        file_put_contents($jpgPath, 'photo');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $movPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-15T12:00:00+00:00'),
                    'LIVE-ID',
                    false,
                    true,
                ),
            );
            $metadataExtractor->withResponse(
                $jpgPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-20T09:00:00+00:00'),
                    null,
                ),
            );

            $scanner = $this->createScanner($metadataExtractor);

            $result = $scanner->scan(
                [new SplFileInfo($movPath), new SplFileInfo($jpgPath)],
                $workspace,
                7,
                static fn (string $relativePath, string $absolutePath, string $category, ?DateTimeInterface $captureDateTime): string => sprintf(
                    '%s|%s',
                    $category,
                    $relativePath,
                ),
            );

            self::assertSame(['timezone|clip.mov'], $result->categories[VerifyCategoryCatalog::TIMEZONE]);
            self::assertSame(['2024-01-01_09-00-00.jpg'], $result->categories[VerifyCategoryCatalog::DRIFT]);
            self::assertContains($workspace, $result->livePhotoContentIdMap->directories());
            self::assertTrue($result->livePhotoContentIdMap->hasBucket($workspace, 'live-id'));
        } finally {
            @unlink($movPath);
            @unlink($jpgPath);
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Creates a scanner wired like the production command but with a stubbed
     * metadata extractor.
     *
     * @param StubMetadataExtractor $metadataExtractor Stub metadata source driving scanner behaviour
     *
     * @return MetadataIssueScanner Scanner using the real metadata and media-type helpers
     */
    private function createScanner(StubMetadataExtractor $metadataExtractor): MetadataIssueScanner
    {
        return new MetadataIssueScanner(
            new ExifMetadataProvider($metadataExtractor),
            new DateDriftAnalyzer(),
            new MediaTypeClassifier(),
        );
    }
}
