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
use MagicSunday\Renamer\Helper\DateDriftCalculator;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Service\DateDriftAnalyzer;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\WriteDate\WriteDateReasonAnalyzer;
use MagicSunday\Renamer\Service\WriteDate\WriteDateReasonCatalog;
use MagicSunday\Renamer\Service\WriteDate\WriteDateReasonDecision;
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
 * Verifies the reason-selection policy used by write-date before metadata writes
 * are scheduled.
 *
 * The analyzer must preserve the command's precedence rules: missing metadata
 * wins immediately, large drift outranks reliability flags, reliable metadata
 * suppresses writes unless forced, and forced video rewrites fall back to the
 * timezone reason when no other issue explains the change.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(WriteDateReasonAnalyzer::class)]
#[UsesClass(WriteDateReasonCatalog::class)]
#[UsesClass(ExifMetadataProvider::class)]
#[UsesClass(TemporalMetadata::class)]
#[UsesClass(DateDriftCalculator::class)]
#[UsesClass(DateDriftAnalyzer::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(WriteDateReasonDecision::class)]
final class WriteDateReasonAnalyzerTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Verifies that files without any capture metadata are classified as `nodata`
     * so write-date can recover the timestamp from the filename.
     */
    #[Test]
    public function analyzeReturnsNoDataWhenCaptureDateIsMissing(): void
    {
        $workspace = $this->createTempWorkspace('writereason_');
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15_10-20-30.jpg';
        file_put_contents($jpgPath, 'photo-data');

        try {
            $analyzer = $this->createAnalyzer(new StubMetadataExtractor());

            $decision = $analyzer->analyze(
                new SplFileInfo($jpgPath),
                null,
                new DateTimeImmutable('2024-01-15 10:20:30'),
                7,
            );

            self::assertNotNull($decision);
            self::assertSame(WriteDateReasonCatalog::NODATA, $decision->key);
        } finally {
            @unlink($jpgPath);
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that large drift is reported before other reliability checks so
     * obviously wrong metadata can be corrected even when tags exist.
     */
    #[Test]
    public function analyzeReturnsDriftWhenMetadataDateExceedsThreshold(): void
    {
        $workspace = $this->createTempWorkspace('writereason_');
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15_10-20-30.jpg';
        file_put_contents($jpgPath, 'photo-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $jpgPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-30T10:20:30+00:00'),
                    null,
                ),
            );

            $analyzer = $this->createAnalyzer($metadataExtractor);

            $decision = $analyzer->analyze(
                new SplFileInfo($jpgPath),
                new DateTimeImmutable('2024-01-30T10:20:30+00:00'),
                new DateTimeImmutable('2024-01-15 10:20:30'),
                7,
            );

            self::assertNotNull($decision);
            self::assertSame(WriteDateReasonCatalog::DRIFT, $decision->key);
            self::assertStringContainsString('15', $decision->label);
        } finally {
            @unlink($jpgPath);
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that already reliable metadata suppresses any write recommendation
     * when the command is not running in forced repair mode.
     */
    #[Test]
    public function analyzeReturnsNullForReliableMetadataWithoutForce(): void
    {
        $workspace = $this->createTempWorkspace('writereason_');
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15_10-20-30.jpg';
        file_put_contents($jpgPath, 'photo-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $jpgPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-15T10:20:30+00:00'),
                    null,
                ),
            );

            $analyzer = $this->createAnalyzer($metadataExtractor);

            $decision = $analyzer->analyze(
                new SplFileInfo($jpgPath),
                new DateTimeImmutable('2024-01-15T10:20:30+00:00'),
                new DateTimeImmutable('2024-01-15 10:20:30'),
                7,
            );

            self::assertNull($decision);
        } finally {
            @unlink($jpgPath);
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that fallback-only metadata is classified as `fallback` once the
     * command has ruled out the reliable and drift cases.
     */
    #[Test]
    public function analyzeReturnsFallbackForFallbackOnlyMetadata(): void
    {
        $workspace = $this->createTempWorkspace('writereason_');
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15_10-20-30.jpg';
        file_put_contents($jpgPath, 'photo-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $jpgPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-15T10:20:30+00:00'),
                    null,
                    true,
                ),
            );

            $analyzer = $this->createAnalyzer($metadataExtractor);

            $decision = $analyzer->analyze(
                new SplFileInfo($jpgPath),
                new DateTimeImmutable('2024-01-15T10:20:30+00:00'),
                new DateTimeImmutable('2024-01-15 10:20:30'),
                0,
                true,
            );

            self::assertNotNull($decision);
            self::assertSame(WriteDateReasonCatalog::FALLBACK, $decision->key);
        } finally {
            @unlink($jpgPath);
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that forced video rewrites without a more specific issue fall back
     * to the timezone reason so incorrect previous timezone repairs can be redone.
     */
    #[Test]
    public function analyzeReturnsForcedTimezoneForForcedVideoRewrite(): void
    {
        $workspace = $this->createTempWorkspace('writereason_');
        $movPath   = $workspace . DIRECTORY_SEPARATOR . '2024-01-15_10-20-30.mov';
        file_put_contents($movPath, 'video-data');

        try {
            $metadataExtractor = new StubMetadataExtractor();
            $metadataExtractor->withResponse(
                $movPath,
                new TemporalMetadata(
                    new DateTimeImmutable('2024-01-15T10:20:30+00:00'),
                    null,
                ),
            );

            $analyzer = $this->createAnalyzer($metadataExtractor);

            $decision = $analyzer->analyze(
                new SplFileInfo($movPath),
                new DateTimeImmutable('2024-01-15T10:20:30+00:00'),
                new DateTimeImmutable('2024-01-15 10:20:30'),
                0,
                true,
            );

            self::assertNotNull($decision);
            self::assertSame(WriteDateReasonCatalog::TIMEZONE, $decision->key);
            self::assertSame('forced re-write of timezone metadata', $decision->label);
        } finally {
            @unlink($movPath);
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Creates a fully wired analyzer with lightweight test doubles.
     *
     * @param StubMetadataExtractor $metadataExtractor Stub metadata source for the analyzer under test
     *
     * @return WriteDateReasonAnalyzer Analyzer using the same supporting services as the real command
     */
    private function createAnalyzer(StubMetadataExtractor $metadataExtractor): WriteDateReasonAnalyzer
    {
        return new WriteDateReasonAnalyzer(
            new ExifMetadataProvider($metadataExtractor),
            new DateDriftAnalyzer(),
            new MediaTypeClassifier(),
        );
    }
}
