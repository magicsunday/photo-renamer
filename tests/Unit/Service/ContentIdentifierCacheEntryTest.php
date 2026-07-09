<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Service\ContentIdentifierCacheEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies deferred content-identifier bookkeeping shared by both execution paths.
 *
 * The cache entry must queue pending files, remember exactly one fallback
 * target, and switch to a resolved duplicate-group state once a still image
 * anchors the content identifier.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(ContentIdentifierCacheEntry::class)]
final class ContentIdentifierCacheEntryTest extends TestCase
{
    /**
     * Verifies that fallback target storage is first-write-wins and that
     * pending files can be queued and later cleared after attachment.
     */
    #[Test]
    public function rememberFallbackTargetAndPendingFilesBehavePredictably(): void
    {
        $entry        = new ContentIdentifierCacheEntry();
        $firstTarget  = new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'first.mov');
        $secondTarget = new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'second.mov');
        $pendingA     = new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'a.mov');
        $pendingB     = new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'b.mov');

        $entry->rememberFallbackTarget($firstTarget);
        $entry->rememberFallbackTarget($secondTarget);
        $entry->addPendingFile($pendingA);
        $entry->addPendingFile($pendingB);

        self::assertSame($firstTarget->getPathname(), $entry->getTarget()?->getPathname());
        self::assertTrue($entry->hasPendingFiles());
        self::assertSame(
            [$pendingA->getPathname(), $pendingB->getPathname()],
            [
                $entry->getPendingFiles()[0]->getPathname(),
                $entry->getPendingFiles()[1]->getPathname(),
            ],
        );

        $entry->clearPendingFiles();

        self::assertFalse($entry->hasPendingFiles());
        self::assertSame([], $entry->getPendingFiles());
    }

    /**
     * Verifies that resolving the group stores both the duplicate identifier and
     * the canonical target so later companion videos can attach to the same
     * group in either execution path.
     */
    #[Test]
    public function rememberResolvedGroupStoresIdentifierAndTarget(): void
    {
        $entry  = new ContentIdentifierCacheEntry();
        $target = new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2019-09-28_16-57-59.jpg');

        $entry->rememberResolvedGroup('live-photo:abc123', $target);

        self::assertSame('live-photo:abc123', $entry->getDuplicateIdentifier());
        self::assertSame($target->getPathname(), $entry->getTarget()?->getPathname());
    }
}
