<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\WriteDate;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Service\DateDriftAnalyzer;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\WriteDate\TimezoneRewritePlanner;
use MagicSunday\Renamer\Service\WriteDate\WriteDateCandidateAnalyzer;
use MagicSunday\Renamer\Service\WriteDate\WriteDatePendingWrite;
use MagicSunday\Renamer\Service\WriteDate\WriteDateReasonAnalyzer;
use MagicSunday\Renamer\Service\WriteDate\WriteDateReasonCatalog;
use MagicSunday\Renamer\Service\WriteDate\WriteDateScanResult;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function file_put_contents;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies the analyzer that scans files for pending write-date operations.
 *
 * The analyzer must preserve the command's first-pass semantics: unsupported
 * writes are counted, missing filename dates are skipped, already-correct files
 * remain counted after filters, and timezone repairs become explicit pending
 * write objects for the execution phase.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(WriteDateCandidateAnalyzer::class)]
#[UsesClass(WriteDatePendingWrite::class)]
#[UsesClass(WriteDateScanResult::class)]
#[UsesClass(WriteDateReasonAnalyzer::class)]
#[UsesClass(WriteDateReasonCatalog::class)]
#[UsesClass(TimezoneRewritePlanner::class)]
#[UsesClass(ExifMetadataProvider::class)]
#[UsesClass(TemporalMetadata::class)]
#[UsesClass(MediaTypeClassifier::class)]
final class WriteDateCandidateAnalyzerTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Verifies that unsupported write targets and missing filename dates are
     * counted without generating pending writes.
     */
    #[Test]
    public function scanCountsUnsupportedWritesAndMissingFilenameDates(): void
    {
        $workspace = $this->createTempWorkspace('writecand_');
        $aviPath   = $workspace . DIRECTORY_SEPARATOR . 'clip.avi';
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . 'scan.jpg';
        file_put_contents($aviPath, 'video');
        file_put_contents($jpgPath, 'photo');

        try {
            $analyzer = $this->createAnalyzer(new StubMetadataExtractor());

            $result = $analyzer->scan(
                [new SplFileInfo($aviPath), new SplFileInfo($jpgPath)],
                7,
                null,
                false,
                false,
                null,
            );

            self::assertSame(2, $result->scannedFiles);
            self::assertSame(1, $result->unsupportedWrite);
            self::assertSame(1, $result->noDateInName);
            self::assertSame([], $result->pendingWrites);
        } finally {
            @unlink($aviPath);
            @unlink($jpgPath);
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that timezone-repair candidates are turned into explicit pending
     * writes with the converted local write timestamp.
     */
    #[Test]
    public function scanCreatesPendingWriteForTimezoneRepair(): void
    {
        $workspace = $this->createTempWorkspace('writecand_');
        $movPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15_13-00-00.mov';
        file_put_contents($movPath, 'video');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $movPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-15T12:00:00+00:00'),
                    null,
                    false,
                    true,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    false,
                    new DateTimeImmutable('2024-01-15T12:00:00+00:00'),
                ),
            );

            $analyzer = $this->createAnalyzer($metadataExtractor);

            $result = $analyzer->scan(
                [new SplFileInfo($movPath)],
                7,
                [WriteDateReasonCatalog::TIMEZONE],
                false,
                false,
                new DateTimeZone('Europe/Berlin'),
            );

            self::assertCount(1, $result->pendingWrites);
            self::assertSame(0, $result->alreadyCorrect);

            $pendingWrite = $result->pendingWrites[0];
            self::assertSame(WriteDateReasonCatalog::TIMEZONE, $pendingWrite->reasonKey);
            self::assertTrue($pendingWrite->isVideo);
            self::assertSame('2024-01-15 13:00:00 Europe/Berlin', $pendingWrite->writeDateTime->format('Y-m-d H:i:s e'));
            self::assertTrue($pendingWrite->preserveCreateDate);
        } finally {
            @unlink($movPath);
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Creates a fully wired candidate analyzer using the same collaborators as
     * the production command, but driven by a stub metadata extractor.
     *
     * @param StubMetadataExtractor $metadataExtractor Stub metadata source for deterministic analyzer behaviour
     *
     * @return WriteDateCandidateAnalyzer Analyzer using the production helper services
     */
    private function createAnalyzer(StubMetadataExtractor $metadataExtractor): WriteDateCandidateAnalyzer
    {
        $metadataProvider    = new ExifMetadataProvider($metadataExtractor);
        $mediaTypeClassifier = new MediaTypeClassifier();

        return new WriteDateCandidateAnalyzer(
            $metadataProvider,
            $mediaTypeClassifier,
            new WriteDateReasonAnalyzer($metadataProvider, new DateDriftAnalyzer(), $mediaTypeClassifier),
            new TimezoneRewritePlanner($metadataProvider),
        );
    }
}
