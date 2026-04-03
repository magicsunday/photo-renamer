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
use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Model\SkippedFile;
use MagicSunday\Renamer\Model\TargetFileResult;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuilder;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuilderInterface;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupBuildState;
use MagicSunday\Renamer\Service\Pipeline\CaptureGroupQualityTracker;
use MagicSunday\Renamer\Service\Pipeline\PendingLivePhotoVideoResolver;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\LivePhotoAwareRenameStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\MetadataAwareRenameStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function in_array;
use function strtolower;

/**
 * Verifies CaptureGroupBuilder: file collection, metadata extraction,
 * capture group formation, and quality flag tracking in PipelineContext.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(CaptureGroupBuilder::class)]
#[UsesClass(CaptureGroupBuildState::class)]
#[UsesClass(CaptureGroupQualityTracker::class)]
#[UsesClass(PendingLivePhotoVideoResolver::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(Constants::class)]
#[UsesClass(PipelineContext::class)]
#[UsesClass(SkippedFile::class)]
#[UsesClass(TargetFileResult::class)]
#[UsesClass(TemporalMetadata::class)]
final class CaptureGroupBuilderTest extends TestCase
{
    /**
     * One file produces one group with one item.
     */
    #[Test]
    public function singleFileCreatesOneGroup(): void
    {
        $file     = new SplFileInfo('/photos/2024-01-01_12-00-00.heic');
        $iterator = $this->createFileIterator([$file]);
        $context  = new PipelineContext('/photos');

        $renameStrategy = $this->createStubRenameStrategy([
            $file->getPathname() => '2024-01-01_12-00-00.heic',
        ]);
        $duplicateStrategy = $this->createStubDuplicateIdentifierStrategy();

        $builder    = $this->createBuilder();
        $collection = $builder->build($iterator, $renameStrategy, $duplicateStrategy, $context);

        self::assertCount(1, $collection);

        $group = $collection->get('2024-01-01_12-00-00');

        self::assertNotNull($group);
        self::assertSame(1, $group->itemCount());
        self::assertSame($file->getPathname(), $group->getItems()[0]->file->getPathname());
    }

    /**
     * Two files with the same target basename land in one group.
     */
    #[Test]
    public function twoFilesWithSameTargetBasenameLandInOneGroup(): void
    {
        $file1 = new SplFileInfo('/photos/IMG_0001.heic');
        $file2 = new SplFileInfo('/photos/IMG_0002.heic');

        $iterator = $this->createFileIterator([$file1, $file2]);
        $context  = new PipelineContext('/photos');

        $renameStrategy = $this->createStubRenameStrategy([
            $file1->getPathname() => '2024-01-01_12-00-00.heic',
            $file2->getPathname() => '2024-01-01_12-00-00.heic',
        ]);
        $duplicateStrategy = $this->createStubDuplicateIdentifierStrategy();

        $builder    = $this->createBuilder();
        $collection = $builder->build($iterator, $renameStrategy, $duplicateStrategy, $context);

        self::assertCount(1, $collection);

        $group = $collection->get('2024-01-01_12-00-00');

        self::assertNotNull($group);
        self::assertSame(2, $group->itemCount());
    }

    /**
     * Two files with different basenames create two groups.
     */
    #[Test]
    public function twoFilesWithDifferentBasenamesCreateTwoGroups(): void
    {
        $file1 = new SplFileInfo('/photos/IMG_0001.heic');
        $file2 = new SplFileInfo('/photos/IMG_0002.heic');

        $iterator = $this->createFileIterator([$file1, $file2]);
        $context  = new PipelineContext('/photos');

        $renameStrategy = $this->createStubRenameStrategy([
            $file1->getPathname() => '2024-01-01_12-00-00.heic',
            $file2->getPathname() => '2024-01-02_12-00-00.heic',
        ]);
        $duplicateStrategy = $this->createStubDuplicateIdentifierStrategy();

        $builder    = $this->createBuilder();
        $collection = $builder->build($iterator, $renameStrategy, $duplicateStrategy, $context);

        self::assertCount(2, $collection);
        self::assertTrue($collection->has('2024-01-01_12-00-00'));
        self::assertTrue($collection->has('2024-01-02_12-00-00'));
    }

    /**
     * Temporal metadata from MetadataAwareRenameStrategyInterface is stored on the item.
     */
    #[Test]
    public function temporalMetadataStoredOnItem(): void
    {
        $file     = new SplFileInfo('/photos/IMG_0001.heic');
        $iterator = $this->createFileIterator([$file]);
        $context  = new PipelineContext('/photos');

        $metadata = new TemporalMetadata(
            new DateTimeImmutable('2024-01-01 12:00:00'),
            null,
        );

        $renameStrategy = $this->createStubMetadataAwareRenameStrategy(
            filenameMap: [$file->getPathname() => '2024-01-01_12-00-00.heic'],
            metadataMap: [$file->getPathname() => $metadata],
        );
        $duplicateStrategy = $this->createStubDuplicateIdentifierStrategy();

        $builder    = $this->createBuilder();
        $collection = $builder->build($iterator, $renameStrategy, $duplicateStrategy, $context);

        $group = $collection->get('2024-01-01_12-00-00');

        self::assertNotNull($group);
        self::assertSame($metadata, $group->getItems()[0]->metadata);
    }

    /**
     * Content identifier from LivePhotoAwareRenameStrategyInterface is stored on the item.
     */
    #[Test]
    public function contentIdentifierStoredOnItem(): void
    {
        $file     = new SplFileInfo('/photos/IMG_0001.heic');
        $iterator = $this->createFileIterator([$file]);
        $context  = new PipelineContext('/photos');

        $renameStrategy = $this->createStubLivePhotoAwareRenameStrategy(
            filenameMap: [$file->getPathname() => '2024-01-01_12-00-00.heic'],
            contentIdMap: [$file->getPathname() => 'abc-123'],
        );
        $duplicateStrategy = $this->createStubDuplicateIdentifierStrategy();

        $mediaTypeClassifier = self::createStub(MediaTypeClassifierInterface::class);
        $mediaTypeClassifier->method('isLivePhotoStill')->willReturn(true);
        $mediaTypeClassifier->method('isVideo')->willReturn(false);

        $builder    = $this->createBuilder(mediaTypeClassifier: $mediaTypeClassifier);
        $collection = $builder->build($iterator, $renameStrategy, $duplicateStrategy, $context);

        $group = $collection->get('2024-01-01_12-00-00');

        self::assertNotNull($group);
        self::assertSame('abc-123', $group->getItems()[0]->contentIdentifier);
    }

    /**
     * Skipped file (no capture date) is recorded in context.
     */
    #[Test]
    public function skippedFileRecordedInContext(): void
    {
        $file     = new SplFileInfo('/photos/IMG_0001.heic');
        $iterator = $this->createFileIterator([$file]);
        $context  = new PipelineContext('/photos');

        // Return null filename -> skipped
        $renameStrategy = $this->createStubRenameStrategy([
            $file->getPathname() => null,
        ]);
        $duplicateStrategy = $this->createStubDuplicateIdentifierStrategy();

        $builder    = $this->createBuilder();
        $collection = $builder->build($iterator, $renameStrategy, $duplicateStrategy, $context);

        self::assertCount(0, $collection);
        self::assertCount(1, $context->getSkippedFiles());
        self::assertSame($file->getPathname(), $context->getSkippedFiles()[0]->getFile()->getPathname());
    }

    /**
     * Fallback date file is tracked in context.
     */
    #[Test]
    public function fallbackDateFlagTrackedInContext(): void
    {
        $file     = new SplFileInfo('/photos/IMG_0001.heic');
        $iterator = $this->createFileIterator([$file]);
        $context  = new PipelineContext('/photos');

        $renameStrategy = $this->createStubMetadataAwareRenameStrategy(
            filenameMap: [$file->getPathname() => '2024-01-01_12-00-00.heic'],
            isFallback: true,
        );
        $duplicateStrategy = $this->createStubDuplicateIdentifierStrategy();

        $builder    = $this->createBuilder();
        $collection = $builder->build($iterator, $renameStrategy, $duplicateStrategy, $context);

        self::assertCount(1, $collection);
        self::assertArrayHasKey($file->getPathname(), $context->getFallbackDateFiles());
    }

    /**
     * Ambiguous timezone file is tracked in context.
     */
    #[Test]
    public function ambiguousTimezoneFlagTrackedInContext(): void
    {
        $file     = new SplFileInfo('/photos/IMG_0001.heic');
        $iterator = $this->createFileIterator([$file]);
        $context  = new PipelineContext('/photos');

        $renameStrategy = $this->createStubMetadataAwareRenameStrategy(
            filenameMap: [$file->getPathname() => '2024-01-01_12-00-00.heic'],
            isAmbiguousTz: true,
        );
        $duplicateStrategy = $this->createStubDuplicateIdentifierStrategy();

        $builder    = $this->createBuilder();
        $collection = $builder->build($iterator, $renameStrategy, $duplicateStrategy, $context);

        self::assertCount(1, $collection);
        self::assertArrayHasKey($file->getPathname(), $context->getAmbiguousTimezoneFiles());
    }

    /**
     * Scanned file count on context matches the number of files in the iterator.
     */
    #[Test]
    public function scannedFileCountSetOnContext(): void
    {
        $files = [
            new SplFileInfo('/photos/IMG_0001.heic'),
            new SplFileInfo('/photos/IMG_0002.heic'),
            new SplFileInfo('/photos/IMG_0003.heic'),
        ];

        $filenameMap = [];

        foreach ($files as $file) {
            $filenameMap[$file->getPathname()] = '2024-01-0' . (count($filenameMap) + 1) . '_12-00-00.heic';
        }

        $iterator          = $this->createFileIterator($files);
        $context           = new PipelineContext('/photos');
        $renameStrategy    = $this->createStubRenameStrategy($filenameMap);
        $duplicateStrategy = $this->createStubDuplicateIdentifierStrategy();

        $builder = $this->createBuilder();
        $builder->build($iterator, $renameStrategy, $duplicateStrategy, $context);

        self::assertSame(3, $context->getScannedFileCount());
    }

    // -------------------------------------------------------------------------
    // Factory helpers
    // -------------------------------------------------------------------------
    /**
     * Creates a CaptureGroupBuilder with optional dependency overrides.
     *
     * @param MediaTypeClassifierInterface|null $mediaTypeClassifier Optional media type classifier stub
     */
    private function createBuilder(
        ?MediaTypeClassifierInterface $mediaTypeClassifier = null,
    ): CaptureGroupBuilderInterface {
        $io = self::createStub(SymfonyStyle::class);

        $nullOutput = new NullOutput();

        $io->method('createProgressBar')->willReturnCallback(
            static fn (int $max = 0): ProgressBar => new ProgressBar($nullOutput, $max),
        );

        $mediaTypeClassifier ??= $this->createDefaultMediaTypeClassifier();

        return new CaptureGroupBuilder(
            $io,
            $mediaTypeClassifier,
        );
    }

    /**
     * Creates a default media type classifier that classifies HEIC/JPG as stills.
     */
    private function createDefaultMediaTypeClassifier(): MediaTypeClassifierInterface
    {
        $classifier = self::createStub(MediaTypeClassifierInterface::class);

        $classifier->method('isLivePhotoStill')
            ->willReturnCallback(
                static fn (SplFileInfo $file): bool => in_array(
                    strtolower($file->getExtension()),
                    ['heic', 'heif', 'jpg', 'jpeg'],
                    true,
                ),
            );

        $classifier->method('isVideo')
            ->willReturnCallback(
                static fn (SplFileInfo $file): bool => in_array(
                    strtolower($file->getExtension()),
                    ['mov', 'mp4', 'm4v'],
                    true,
                ),
            );

        return $classifier;
    }

    /**
     * Creates a RecursiveIteratorIterator wrapping the given files.
     *
     * @param list<SplFileInfo> $files Files to iterate
     *
     * @return RecursiveIteratorIterator<RecursiveArrayIterator<int, SplFileInfo>>
     */
    private function createFileIterator(array $files): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(
            new RecursiveArrayIterator($files, RecursiveArrayIterator::CHILD_ARRAYS_ONLY),
        );
    }

    /**
     * Creates a stub RenameStrategyInterface that returns filenames from a map.
     * Entries with null values cause generateFilename() to return null (skipped).
     *
     * @param array<string, string|null> $filenameMap Source pathname => target filename (or null for skip)
     */
    private function createStubRenameStrategy(array $filenameMap): RenameStrategyInterface
    {
        $strategy = self::createStub(RenameStrategyInterface::class);

        $strategy->method('generateFilename')
            ->willReturnCallback(
                static fn (SplFileInfo $file): ?string => $filenameMap[$file->getPathname()] ?? null,
            );

        return $strategy;
    }

    /**
     * Creates a stub DuplicateIdentifierStrategyInterface that returns the
     * target basename without extension.
     */
    private function createStubDuplicateIdentifierStrategy(): DuplicateIdentifierStrategyInterface
    {
        $strategy = self::createStub(DuplicateIdentifierStrategyInterface::class);

        $strategy->method('generateIdentifier')
            ->willReturnCallback(
                static fn (SplFileInfo $source, SplFileInfo $target): string => $target->getBasename(
                    '.' . $target->getExtension(),
                ),
            );

        return $strategy;
    }

    /**
     * Creates a stub that implements both RenameStrategyInterface and
     * MetadataAwareRenameStrategyInterface.
     *
     * @param array<string, string|null>      $filenameMap   Source pathname => target filename
     * @param array<string, TemporalMetadata> $metadataMap   Source pathname => TemporalMetadata
     * @param bool                            $isFallback    Whether isFallbackDateTime returns true
     * @param bool                            $isAmbiguousTz Whether isAmbiguousTimezone returns true
     */
    private function createStubMetadataAwareRenameStrategy(
        array $filenameMap = [],
        array $metadataMap = [],
        bool $isFallback = false,
        bool $isAmbiguousTz = false,
    ): MetadataAwareRenameStrategyInterface {
        $strategy = self::createStub(MetadataAwareRenameStrategyInterface::class);

        $strategy->method('generateFilename')
            ->willReturnCallback(
                static fn (SplFileInfo $file): ?string => $filenameMap[$file->getPathname()] ?? null,
            );

        $strategy->method('getTemporalMetadata')
            ->willReturnCallback(
                static fn (SplFileInfo $file): ?TemporalMetadata => $metadataMap[$file->getPathname()] ?? null,
            );

        $strategy->method('isFallbackDateTime')
            ->willReturn($isFallback);

        $strategy->method('isAmbiguousTimezone')
            ->willReturn($isAmbiguousTz);

        $strategy->method('hasReliableDateTime')
            ->willReturn(!$isFallback && !$isAmbiguousTz);

        return $strategy;
    }

    /**
     * Creates a stub that implements both RenameStrategyInterface and
     * LivePhotoAwareRenameStrategyInterface.
     *
     * @param array<string, string|null> $filenameMap  Source pathname => target filename
     * @param array<string, string|null> $contentIdMap Source pathname => content identifier
     */
    private function createStubLivePhotoAwareRenameStrategy(
        array $filenameMap = [],
        array $contentIdMap = [],
    ): LivePhotoAwareRenameStrategyInterface {
        $strategy = self::createStub(LivePhotoAwareRenameStrategyInterface::class);

        $strategy->method('generateFilename')
            ->willReturnCallback(
                static fn (SplFileInfo $file): ?string => $filenameMap[$file->getPathname()] ?? null,
            );

        $strategy->method('getLivePhotoContentIdentifier')
            ->willReturnCallback(
                static fn (SplFileInfo $file): ?string => $contentIdMap[$file->getPathname()] ?? null,
            );

        return $strategy;
    }
}
