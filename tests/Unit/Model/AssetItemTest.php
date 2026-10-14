<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model;

use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\ItemRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the immutable value-object contract of AssetItem, ensuring
 * that all with*() methods return new instances without mutating the original.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(AssetItem::class)]
#[UsesClass(TemporalMetadata::class)]
final class AssetItemTest extends TestCase
{
    /**
     * Verifies that the constructor sets correct default values for all properties
     * of a new AssetItem.
     */
    #[Test]
    public function constructorSetsDefaults(): void
    {
        $file = new SplFileInfo('/tmp/photo.heic');
        $item = new AssetItem($file);

        self::assertSame($file, $item->file);
        self::assertSame(ItemRole::Canonical, $item->role);
        self::assertNull($item->metadata);
        self::assertNull($item->contentIdentifier);
        self::assertNull($item->proposedName);
        self::assertFalse($item->renameRequired);
        self::assertSame(0, $item->priorityScore);
        self::assertSame([], $item->reasoning);
        self::assertNull($item->sequenceNumber);
        self::assertNull($item->clusterId);
    }

    /**
     * Verifies that withRole() returns a new instance with the updated role
     * while the original remains unchanged.
     */
    #[Test]
    public function withRoleReturnsNewInstance(): void
    {
        $file     = new SplFileInfo('/tmp/photo.heic');
        $original = new AssetItem($file);

        $updated = $original->withRole(ItemRole::Duplicate);

        self::assertNotSame($original, $updated);
        self::assertSame(ItemRole::Duplicate, $updated->role);
        self::assertSame(ItemRole::Canonical, $original->role);
    }

    /**
     * Verifies that proposing a name different from the current pathname
     * correctly sets renameRequired to true.
     */
    #[Test]
    public function withProposedNameComputesRenameRequired(): void
    {
        $file = new SplFileInfo('/tmp/IMG_0001.heic');
        $item = new AssetItem($file);

        $updated = $item->withProposedName('/tmp/2024-08-31_14-22-08.heic');

        self::assertSame('/tmp/2024-08-31_14-22-08.heic', $updated->proposedName);
        self::assertTrue($updated->renameRequired);
    }

    /**
     * Verifies that proposing a name that is identical to the current pathname
     * correctly sets renameRequired to false.
     */
    #[Test]
    public function withProposedNameDetectsAlreadyCorrect(): void
    {
        $pathname = '/tmp/2024-08-31_14-22-08.heic';
        $file     = new SplFileInfo($pathname);
        $item     = new AssetItem($file);

        $updated = $item->withProposedName($pathname);

        self::assertSame($pathname, $updated->proposedName);
        self::assertFalse($updated->renameRequired);
    }

    /**
     * Verifies that withScore() returns a new instance with updated priority score
     * and reasoning, preserving the original's state.
     */
    #[Test]
    public function withScoreReturnsNewInstance(): void
    {
        $file     = new SplFileInfo('/tmp/photo.heic');
        $original = new AssetItem($file);

        $reasoning = ['HEIC preferred over JPEG', 'already correctly named'];
        $updated   = $original->withScore(42, $reasoning);

        self::assertNotSame($original, $updated);
        self::assertSame(42, $updated->priorityScore);
        self::assertSame($reasoning, $updated->reasoning);
        self::assertSame(0, $original->priorityScore);
        self::assertSame([], $original->reasoning);
    }

    /**
     * Verifies that withSequenceNumber() returns a new instance with the assigned sequence number.
     */
    #[Test]
    public function withSequenceNumberReturnsNewInstance(): void
    {
        $file     = new SplFileInfo('/tmp/photo.heic');
        $original = new AssetItem($file);

        $updated = $original->withSequenceNumber(3);

        self::assertNotSame($original, $updated);
        self::assertSame(3, $updated->sequenceNumber);
        self::assertNull($original->sequenceNumber);
    }

    /**
     * Verifies that withClusterId() returns a new instance with the assigned cluster ID.
     */
    #[Test]
    public function withClusterIdReturnsNewInstance(): void
    {
        $file     = new SplFileInfo('/tmp/photo.heic');
        $original = new AssetItem($file);

        $updated = $original->withClusterId('cluster-abc');

        self::assertNotSame($original, $updated);
        self::assertSame('cluster-abc', $updated->clusterId);
        self::assertNull($original->clusterId);
    }

    /**
     * Verifies that matchesProposedNameExactly() returns true if the current file
     * already resides at the proposed path.
     */
    #[Test]
    public function matchesProposedNameExactlyWhenCorrect(): void
    {
        $pathname = '/tmp/2024-08-31_14-22-08.heic';
        $file     = new SplFileInfo($pathname);
        $item     = new AssetItem($file)->withProposedName($pathname);

        self::assertTrue($item->matchesProposedNameExactly());
    }

    /**
     * Verifies that matchesProposedNameExactly() returns false if no name has been proposed yet.
     */
    #[Test]
    public function matchesProposedNameExactlyReturnsFalseWithoutProposal(): void
    {
        $file = new SplFileInfo('/tmp/photo.heic');
        $item = new AssetItem($file);

        self::assertFalse($item->matchesProposedNameExactly());
    }

    /**
     * Verifies that matchesProposedNameExactly() returns false if the current path
     * and proposed path differ.
     */
    #[Test]
    public function matchesProposedNameExactlyReturnsFalseWhenDifferent(): void
    {
        $file = new SplFileInfo('/tmp/IMG_0001.heic');
        $item = new AssetItem($file)->withProposedName('/tmp/2024-08-31_14-22-08.heic');

        self::assertFalse($item->matchesProposedNameExactly());
    }

    /**
     * Verifies that matchesNamingPattern() correctly identifies files that already
     * follow the target naming scheme (YYYY-MM-DD_HH-mm-ss).
     */
    #[Test]
    public function matchesNamingPatternDetectsDatePattern(): void
    {
        $file = new SplFileInfo('/tmp/2024-08-31_14-22-08-123.heic');
        $item = new AssetItem($file);

        self::assertTrue($item->matchesNamingPattern());
    }

    /**
     * Verifies that matchesNamingPattern() returns false for files with generic
     * camera names like IMG_0001.
     */
    #[Test]
    public function matchesNamingPatternReturnsFalseForOriginalName(): void
    {
        $file = new SplFileInfo('/tmp/IMG_0001.heic');
        $item = new AssetItem($file);

        self::assertFalse($item->matchesNamingPattern());
    }

    /**
     * Verifies that extension() returns the file extension in lowercase.
     */
    #[Test]
    public function extensionReturnsLowercaseExtension(): void
    {
        $file = new SplFileInfo('/tmp/photo.HEIC');
        $item = new AssetItem($file);

        self::assertSame('heic', $item->extension());
    }

    /**
     * Verifies the calculation of directory depth, ensuring it reflects the number
     * of parent directories in the path.
     */
    #[Test]
    public function dirDepthComputesCorrectly(): void
    {
        $root   = new AssetItem(new SplFileInfo('/photo.heic'));
        $subdir = new AssetItem(new SplFileInfo('/photos/photo.heic'));
        $deep   = new AssetItem(new SplFileInfo('/photos/2024/08/photo.heic'));

        self::assertSame(1, $root->dirDepth());
        self::assertSame(2, $subdir->dirDepth());
        self::assertSame(4, $deep->dirDepth());
    }

    /**
     * Replacing metadata uses the explicitly supplied identifier rather than the
     * metadata object's ID. Omission clears a previous identifier; null metadata
     * remains supported. Source identity, independent score and original state stay.
     *
     * @return void
     */
    #[Test]
    public function withMetadataReplacesMetadataAndIdentifier(): void
    {
        $file             = new SplFileInfo('/synthetic/source.heic');
        $originalMetadata = new TemporalMetadata(null, 'old-metadata-id');
        $item             = new AssetItem($file, metadata: $originalMetadata, contentIdentifier: 'old-content-id', priorityScore: 42);
        $metadata         = new TemporalMetadata(null, 'new-metadata-id');
        $changed          = $item->withMetadata($metadata, 'explicit-content-id');

        self::assertNotSame($item, $changed);
        self::assertSame($metadata, $changed->metadata);
        self::assertSame('explicit-content-id', $changed->contentIdentifier);
        self::assertSame($file, $changed->file);
        self::assertSame(42, $changed->priorityScore);
        self::assertSame($originalMetadata, $item->metadata);
        self::assertSame('old-content-id', $item->contentIdentifier);
        self::assertNull($changed->withMetadata($metadata)->contentIdentifier);
        self::assertNull($changed->withMetadata(null)->metadata);
    }
}
