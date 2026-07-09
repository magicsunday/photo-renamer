<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Pipeline;

use DateTimeImmutable;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Service\Pipeline\CaptureAssetCandidateExtractor;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuildState;
use MagicSunday\Renamer\Strategy\RenameStrategy\LivePhotoAwareRenameStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\MetadataAwareRenameStrategyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies candidate extraction for the EXIF capture pipeline.
 *
 * The extractor must hydrate AssetItem metadata/content identifiers and update
 * the mutable build-state maps that later Live Photo and conflict logic consume.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(CaptureAssetCandidateExtractor::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(CaptureGroupBuildState::class)]
#[UsesClass(TemporalMetadata::class)]
final class CaptureAssetCandidateExtractorTest extends TestCase
{
    /**
     * Verifies that extracted temporal metadata is attached to the returned item
     * and stored in the build state's temporal metadata map.
     */
    #[Test]
    public function extractStoresTemporalMetadataInItemAndState(): void
    {
        $file      = new SplFileInfo('/photos/IMG_0001.heic');
        $state     = new CaptureGroupBuildState();
        $metadata  = new TemporalMetadata(new DateTimeImmutable('2024-01-01 12:00:00'), null);
        $extractor = new CaptureAssetCandidateExtractor();
        $strategy  = new readonly class($metadata) implements MetadataAwareRenameStrategyInterface {
            /**
             * @param TemporalMetadata $metadata Metadata payload returned by the test double.
             */
            public function __construct(
                private TemporalMetadata $metadata,
            ) {
            }

            public function generateFilename(SplFileInfo $splFileInfo): string
            {
                return 'unused.heic';
            }

            public function isFallbackDateTime(SplFileInfo $splFileInfo): bool
            {
                return false;
            }

            public function isAmbiguousTimezone(SplFileInfo $splFileInfo): bool
            {
                return false;
            }

            public function hasReliableDateTime(SplFileInfo $splFileInfo): bool
            {
                return true;
            }

            public function getTemporalMetadata(SplFileInfo $splFileInfo): TemporalMetadata
            {
                return $this->metadata;
            }
        };

        $item = $extractor->extract($file, $strategy, $state);

        self::assertSame($metadata, $item->metadata);
        self::assertSame($metadata, $state->temporalMetadataMap[$file->getPathname()]);
    }

    /**
     * Verifies that an exposed Live Photo content identifier is attached to the
     * returned item and stored in the build state's content identifier map.
     */
    #[Test]
    public function extractStoresContentIdentifierInItemAndState(): void
    {
        $file      = new SplFileInfo('/photos/IMG_0001.heic');
        $state     = new CaptureGroupBuildState();
        $extractor = new CaptureAssetCandidateExtractor();
        $strategy  = new class implements LivePhotoAwareRenameStrategyInterface {
            public function generateFilename(SplFileInfo $splFileInfo): string
            {
                return 'unused.heic';
            }

            public function getLivePhotoContentIdentifier(SplFileInfo $splFileInfo): string
            {
                return 'abc-123';
            }
        };

        $item = $extractor->extract($file, $strategy, $state);

        self::assertSame('abc-123', $item->contentIdentifier);
        self::assertSame('abc-123', $state->contentIdentifierMap[$file->getPathname()]);
    }

    /**
     * Verifies that metadata/content-identifier read failures are swallowed into
     * a conservative no-metadata/no-content-id result instead of aborting the build.
     */
    #[Test]
    public function extractSuppressesMetadataReadFailures(): void
    {
        $file      = new SplFileInfo('/photos/IMG_0001.heic');
        $state     = new CaptureGroupBuildState();
        $extractor = new CaptureAssetCandidateExtractor();
        $strategy  = new class implements MetadataAwareRenameStrategyInterface, LivePhotoAwareRenameStrategyInterface {
            public function generateFilename(SplFileInfo $splFileInfo): string
            {
                return 'unused.heic';
            }

            public function isFallbackDateTime(SplFileInfo $splFileInfo): bool
            {
                return false;
            }

            public function isAmbiguousTimezone(SplFileInfo $splFileInfo): bool
            {
                return false;
            }

            public function hasReliableDateTime(SplFileInfo $splFileInfo): bool
            {
                return true;
            }

            public function getTemporalMetadata(SplFileInfo $splFileInfo): ?TemporalMetadata
            {
                throw new TargetFilenameException('metadata boom');
            }

            public function getLivePhotoContentIdentifier(SplFileInfo $splFileInfo): ?string
            {
                throw new TargetFilenameException('content id boom');
            }
        };

        $item = $extractor->extract($file, $strategy, $state);

        self::assertNull($item->metadata);
        self::assertNull($item->contentIdentifier);
        self::assertArrayNotHasKey($file->getPathname(), $state->temporalMetadataMap);
        self::assertArrayNotHasKey($file->getPathname(), $state->contentIdentifierMap);
    }
}
