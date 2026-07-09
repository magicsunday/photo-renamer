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
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AbstractCollection;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Service\ContentIdentifierCacheEntry;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuildState;
use MagicSunday\Renamer\Service\Pipeline\PendingLivePhotoVideoResolver;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the fallback resolution of deferred Live Photo videos without a still image.
 *
 * This covers the second-chance path after the main scan where videos with a
 * content identifier never found a still-image anchor and must therefore be
 * replayed into the remembered date-based group instead of being dropped.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(PendingLivePhotoVideoResolver::class)]
#[UsesClass(CaptureGroupBuildState::class)]
#[UsesClass(ContentIdentifierCacheEntry::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(AbstractCollection::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(TemporalMetadata::class)]
final class PendingLivePhotoVideoResolverTest extends TestCase
{
    /**
     * Verifies that unresolved pending videos are attached to the fallback group
     * derived from the remembered target and keep their cached metadata context.
     */
    #[Test]
    public function pendingVideosAreResolvedIntoFallbackGroup(): void
    {
        $pendingVideoA = new SplFileInfo('/photos/IMG_0001.mov');
        $pendingVideoB = new SplFileInfo('/photos/IMG_0002.mov');
        $target        = new SplFileInfo('/photos/2024-01-01_12-00-00.heic');
        $state         = new CaptureGroupBuildState();
        $cacheEntry    = new ContentIdentifierCacheEntry();

        $cacheEntry->addPendingFile($pendingVideoA);
        $cacheEntry->addPendingFile($pendingVideoB);
        $cacheEntry->rememberFallbackTarget($target);

        $state->contentIdentifierCache['live-photo:abc']            = $cacheEntry;
        $state->contentIdentifierMap[$pendingVideoA->getPathname()] = 'live-photo:abc';
        $state->contentIdentifierMap[$pendingVideoB->getPathname()] = 'live-photo:abc';
        $state->temporalMetadataMap[$pendingVideoA->getPathname()]  = new TemporalMetadata(
            new DateTimeImmutable('2024-01-01 12:00:00'),
            null,
        );

        $duplicateIdentifierStrategy = self::createStub(DuplicateIdentifierStrategyInterface::class);
        $duplicateIdentifierStrategy
            ->method('generateIdentifier')
            ->willReturn('2024-01-01_12-00-00');

        $collection = new AssetGroupCollection();
        $resolver   = new PendingLivePhotoVideoResolver();

        $resolver->resolve($state, $duplicateIdentifierStrategy, $collection);

        $group = $collection->get('2024-01-01_12-00-00');

        self::assertInstanceOf(AssetGroup::class, $group);
        self::assertSame(2, $group->itemCount());
        self::assertSame('live-photo:abc', $group->getItems()[0]->contentIdentifier);
        self::assertSame(
            '2024-01-01 12:00:00',
            $group->getItems()[0]->metadata?->getCaptureDateTime()?->format('Y-m-d H:i:s'),
        );
    }
}
