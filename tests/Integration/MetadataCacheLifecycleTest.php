<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration;

use DateTimeImmutable;
use Generator;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\FilenameDateParser;
use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Metadata\ExifMetadataProvider;
use MagicSunday\Renamer\Metadata\MetadataCache;
use MagicSunday\Renamer\Metadata\MetadataCacheEntry;
use MagicSunday\Renamer\Metadata\MetadataExtractorInterface;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\Collection\AbstractCollection;
use MagicSunday\Renamer\Model\Collection\AbstractList;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\Collection\FileList;
use MagicSunday\Renamer\Model\Collection\RenameList;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoBasenameTargetMap;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoContentIdentifierTarget;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoContentIdentifierTargetMap;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoExistingFilePathnameIndex;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairing;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingCollection;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoPairingService;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use MagicSunday\Renamer\Test\Unit\Service\Fixtures\StubMetadataExtractor;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function dirname;
use function json_decode;
use function json_encode;
use function strrpos;
use function substr;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * Exercises the provider and private disk cache across fresh instances rather
 * than testing serialization alone. Synthetic files verify warm metadata,
 * version invalidation, explicit pruning and the bounded concurrent contract.
 */
#[CoversClass(MetadataCache::class)]
#[CoversClass(ExifMetadataProvider::class)]
#[CoversClass(PerceptualSignalCache::class)]
#[UsesClass(MetadataCacheEntry::class)]
#[UsesClass(TemporalMetadata::class)]
#[UsesClass(PrivateCacheStorage::class)]
#[UsesClass(FilenameDateParser::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(AbstractCollection::class)]
#[UsesClass(AbstractList::class)]
#[UsesClass(FileDuplicateCollection::class)]
#[UsesClass(FileList::class)]
#[UsesClass(RenameList::class)]
#[UsesClass(FileDuplicate::class)]
#[UsesClass(LivePhotoBasenameTargetMap::class)]
#[UsesClass(LivePhotoContentIdentifierTarget::class)]
#[UsesClass(LivePhotoContentIdentifierTargetMap::class)]
#[UsesClass(LivePhotoExistingFilePathnameIndex::class)]
#[UsesClass(LivePhotoPairing::class)]
#[UsesClass(LivePhotoPairingCollection::class)]
#[UsesClass(LivePhotoPairingService::class)]
final class MetadataCacheLifecycleTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Own disposable media/cache tree, never an original collection.
     */
    private string $workspace;

    /**
     * Creates an isolated workspace so persisted entries never cross test cases.
     *
     * @return void
     */
    #[Override]
    protected function setUp(): void
    {
        $this->workspace = $this->createTempWorkspace('metadata-lifecycle-');
    }

    /**
     * Removes only synthetic files created by this test.
     *
     * @return void
     */
    #[Override]
    protected function tearDown(): void
    {
        $this->removeWorkspace($this->workspace);
    }

    /**
     * Persists marker-only, index-only, fully populated and negative metadata,
     * then reads through a new provider whose extractor must never run. Live
     * Photo eligibility markers and date-quality decisions must match cold
     * results; negative results remain null without inventing usable metadata.
     *
     * @return void
     */
    #[Test]
    public function warmProviderRetainsMarkersQualityAndNegativeResults(): void
    {
        $metadata = [
            new TemporalMetadata(null, null, hasQuickTimeLivePhotoMarker: true),
            new TemporalMetadata(null, null, livePhotoVideoIndex: 0),
            new TemporalMetadata(
                new DateTimeImmutable('2024-01-15T10:30:00.123456+01:00'),
                ' SYNTHETIC-ID ',
                true,
                true,
                8192,
                'Synthetic Make',
                'Synthetic Model',
                'Test Software',
                0.25,
                -0.5,
                2.6,
                true,
                new DateTimeImmutable('2024-01-15T09:00:00.654321+00:00'),
            ),
            null,
        ];
        $cache     = $this->createCache();
        $extractor = new StubMetadataExtractor();
        $cold      = new ExifMetadataProvider($extractor);
        $cold->setCache($cache);

        $warmExtractor = self::createMock(MetadataExtractorInterface::class);
        $warmExtractor->expects(self::never())->method('extractTemporalMetadata');

        foreach ($metadata as $index => $expected) {
            $file = $this->createFile('synthetic-' . $index . '.mov');
            $extractor->withResponse($file->getPathname(), $expected);
            self::assertSame($expected, $cold->getTemporalMetadata($file));
            $quality = $cold->hasReliableDateTime($file);
            $cache->flush();
            $warm = new ExifMetadataProvider($warmExtractor);
            $warm->setCache($this->createCache());
            $actual = $warm->getTemporalMetadata($file);
            self::assertEquals($expected, $actual);
            self::assertSame($quality, $warm->hasReliableDateTime($file));
            self::assertSame($expected?->hasStillLivePhotoMarker(), $actual?->hasStillLivePhotoMarker());
            self::assertSame($expected?->hasVideoLivePhotoMarker(), $actual?->hasVideoLivePhotoMarker());
            self::assertSame($expected?->getNormalizedLivePhotoId(), $warm->getContentIdentifier($file));
        }

        $emptyFile = $this->createFile('empty.mov');
        $cache->set($emptyFile, new TemporalMetadata(null, null));
        $cache->flush();

        $negative = new ExifMetadataProvider($warmExtractor);
        $negative->setCache($this->createCache());
        self::assertNull($negative->getTemporalMetadata($emptyFile));
    }

    /**
     * Feeds the real pairing service a marker/index-only basename pair and an
     * exact-ID pair. Warm provider instances must yield the same two pairing
     * plans and every date-quality decision without re-running extraction;
     * an unrelated negative video must not become a companion.
     *
     * @return void
     */
    #[Test]
    public function pairingPlansAndQualityDecisionsAreIdenticalColdAndWarm(): void
    {
        $stills    = [$this->createFile('pair.jpg'), $this->createFile('exact.jpg')];
        $videos    = [$this->createFile('pair.mov'), $this->createFile('different.mov'), $this->createFile('unrelated.mov')];
        $files     = [...$stills, ...$videos];
        $extractor = new StubMetadataExtractor();
        $extractor->withResponse($stills[0]->getPathname(), new TemporalMetadata(null, null, livePhotoVideoIndex: 0));
        $extractor->withResponse($videos[0]->getPathname(), new TemporalMetadata(null, null, hasQuickTimeLivePhotoMarker: true));
        $extractor->withResponse($stills[1]->getPathname(), new TemporalMetadata(new DateTimeImmutable('2024-01-15T10:30:00Z'), ' SYNTHETIC-ID '));
        $extractor->withResponse($videos[1]->getPathname(), new TemporalMetadata(null, 'synthetic-id'));

        $cache = $this->createCache();
        $cold  = new ExifMetadataProvider($extractor);
        $cold->setCache($cache);

        $quality = [];

        foreach ($files as $file) {
            $quality[$file->getPathname()] = $cold->hasReliableDateTime($file);
        }

        $groups = new FileDuplicateCollection();

        foreach ($stills as $index => $still) {
            $groups->set('synthetic-group-' . $index, new FileDuplicate(new FileList(), new RenameList())
                ->addFile($still)
                ->setTarget(new SplFileInfo($this->workspace . '/target-' . $index . '.jpg')));
        }

        $service   = new LivePhotoPairingService();
        $coldPairs = $service->pairByContentIdentifier(
            new RecursiveIteratorIterator(new RecursiveArrayIterator($files, RecursiveArrayIterator::CHILD_ARRAYS_ONLY)),
            $groups,
            $cold->getContentIdentifier(...),
        )->toList();
        self::assertCount(2, $coldPairs);
        $cache->flush();
        $warmExtractor = self::createMock(MetadataExtractorInterface::class);
        $warmExtractor->expects(self::never())->method('extractTemporalMetadata');
        $warm = new ExifMetadataProvider($warmExtractor);
        $warm->setCache($this->createCache());

        $warmPairs = $service->pairByContentIdentifier(
            new RecursiveIteratorIterator(new RecursiveArrayIterator($files, RecursiveArrayIterator::CHILD_ARRAYS_ONLY)),
            $groups,
            $warm->getContentIdentifier(...),
        )->toList();
        self::assertEquals($coldPairs, $warmPairs);

        foreach ($files as $file) {
            self::assertSame($quality[$file->getPathname()], $warm->hasReliableDateTime($file));
        }
    }

    /**
     * Changes schema and parser identities independently in a persisted cache
     * while the source bytes, mtime and size remain untouched. Each incompatible
     * identity must reject the previously warm metadata and expose a diagnostic.
     *
     * @return void
     */
    #[Test]
    public function schemaAndParserChangesInvalidateUnchangedMedia(): void
    {
        $file  = $this->createFile('photo.jpg');
        $cache = $this->createCache();
        $cache->set($file, new TemporalMetadata(new DateTimeImmutable('2024-01-15T10:30:00Z'), null));
        $cache->flush();

        $filesystem = new Filesystem();
        $original   = $filesystem->readFile($this->cachePath());

        foreach (['_version', '_parser'] as $key) {
            $payload = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertIsInt($payload['_version']);
            $payload[$key] = $key === '_version' ? $payload['_version'] + 1 : 'incompatible-test-parser';
            $filesystem->dumpFile($this->cachePath(), json_encode($payload, JSON_THROW_ON_ERROR));
            $fresh = $this->createCache();
            self::assertNull($fresh->get($file));
            self::assertNotNull($fresh->getDiagnostic());
        }
    }

    /**
     * Removes one cached source and explicitly prunes missing entries. Existing
     * paths stay warm after flush/reload, and repeated pruning does no work.
     *
     * @return void
     */
    #[Test]
    public function explicitPruningRemovesMissingPathsWithoutEvictingExistingFiles(): void
    {
        $retained = $this->createFile('retained.jpg');
        $removed  = $this->createFile('removed.jpg');
        $cache    = $this->createCache();
        $cache->set($retained, null);
        $cache->set($removed, null);
        $cache->flush();
        new Filesystem()->remove($removed->getPathname());
        self::assertSame(1, $cache->pruneMissing());
        self::assertSame(0, $cache->pruneMissing());
        $cache->flush();
        $fresh = $this->createCache();
        self::assertNotNull($fresh->get($retained));
        self::assertNull($fresh->get($removed));
    }

    /**
     * Two writers start from the same empty snapshot and flush distinct entries.
     * The last complete snapshot wins; the first writer's addition becomes a
     * cache miss rather than partially written JSON or invented metadata.
     *
     * @return void
     */
    #[Test]
    public function interleavedWritersLoseOnlyCacheWorkWithLastSnapshotWinning(): void
    {
        $firstFile = $this->createFile('first.jpg');
        $lastFile  = $this->createFile('last.jpg');
        $first     = $this->createCache();
        $last      = $this->createCache();
        $first->set($firstFile, null);
        $last->set($lastFile, null);
        $first->flush();
        $last->flush();
        $fresh = $this->createCache();
        self::assertNull($fresh->get($firstFile));
        self::assertNotNull($fresh->get($lastFile));
    }

    /**
     * Writes deliberately truncated JSON and incompatible legacy payloads. Both
     * must start a cold cache with an inspectable reason, not silently hydrate
     * old values or crash a provider before it can re-extract metadata.
     *
     * @return void
     */
    #[Test]
    public function damagedAndLegacyPayloadsStartDiagnosableColdCaches(): void
    {
        $file    = $this->createFile('photo.jpg');
        $storage = new PrivateCacheStorage(new Filesystem());

        foreach (['{"entries":', '{"legacy": {}}'] as $payload) {
            $storage->write($this->cachePath(), $payload);
            $cache = $this->createCache();
            self::assertNull($cache->get($file));
            self::assertNotNull($cache->getDiagnostic());
        }
    }

    /**
     * Changes values inside an otherwise compatible metadata snapshot. Invalid
     * dates and coercible scalar flags must trigger cold re-extraction rather
     * than silently becoming null timestamps or altered quality decisions.
     *
     * @return void
     */
    #[Test]
    public function malformedMetadataValuesCannotBecomeSilentCacheHits(): void
    {
        $file  = $this->createFile('photo.jpg');
        $cache = $this->createCache();
        $cache->set($file, new TemporalMetadata(new DateTimeImmutable('2024-01-15T10:30:00Z'), null));
        $cache->flush();

        $filesystem = new Filesystem();
        $original   = $filesystem->readFile($this->cachePath());

        foreach (['captureDateTime' => 'invalid-date', 'isFallback' => 'false'] as $field => $value) {
            $payload = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertIsArray($payload['entries']);
            self::assertIsArray($payload['entries'][$file->getPathname()]);
            $payload['entries'][$file->getPathname()][$field] = $value;
            $filesystem->dumpFile($this->cachePath(), json_encode($payload, JSON_THROW_ON_ERROR));
            $fresh = $this->createCache();
            self::assertNull($fresh->get($file));
            self::assertNotNull($fresh->getDiagnostic());
        }
    }

    /**
     * Simulates an ordinary read error for existing owned snapshots while real
     * ownership and permission checks still run. Both caches must remain cold
     * and expose a safe reason instead of confusing an unreadable file with a
     * valid negative metadata entry or suppressing the diagnostic entirely.
     *
     * @return void
     */
    #[Test]
    public function ordinaryReadFailuresProduceSafeColdCacheDiagnostics(): void
    {
        $file        = $this->createFile('photo.jpg');
        $realStorage = new PrivateCacheStorage(new Filesystem());
        $realStorage->write($this->cachePath(), '{}');
        $realStorage->write($this->signalCachePath(), '{}');

        $filesystem   = self::getMockBuilder(Filesystem::class)->onlyMethods(['readFile', 'chmod'])->getMock();
        $metadataPath = $this->cachePath();
        $filesystem->method('chmod')->willReturnCallback(static function (string $path, int $mode) use ($metadataPath): void {
            // The stream really encounters denied read access after validation;
            // the ordinary Filesystem read double still covers perceptual JSON.
            new Filesystem()->chmod($path, $path === $metadataPath ? 0 : $mode);
        });
        $filesystem->expects(self::once())->method('readFile')->willThrowException(new IOException('Controlled ordinary read failure.'));
        $storage  = new PrivateCacheStorage($filesystem);
        $metadata = new MetadataCache($this->cachePath(), $storage);
        $signals  = new PerceptualSignalCache($this->signalCachePath(), $storage);
        self::assertNull($metadata->get($file));
        self::assertNull($signals->get($file));
        self::assertSame('Metadata cache could not be read; starting cold.', $metadata->getDiagnostic());
        self::assertSame('Perceptual cache could not be read; starting cold.', $signals->getDiagnostic());
    }

    /**
     * A late corrupt row or truncated/trailing envelope discards all earlier rows.
     */
    #[Test]
    public function streamedSnapshotFailureCannotPublishPartialWarmMetadata(): void
    {
        $first = $this->createFile('first.jpg');
        $last  = $this->createFile('last.jpg');
        $cache = $this->createCache();
        $cache->set($first, new TemporalMetadata(new DateTimeImmutable('2024-01-01T12:00:00Z'), 'first'));
        $cache->set($last, new TemporalMetadata(new DateTimeImmutable('2024-01-02T12:00:00Z'), 'last'));
        $cache->flush();

        $filesystem = new Filesystem();
        $complete   = $filesystem->readFile($this->cachePath());
        $filesystem->dumpFile($this->cachePath(), $complete . " \n");
        $whitespace = $this->createCache();
        self::assertNull($whitespace->getDiagnostic());
        self::assertNotNull($whitespace->get($first));
        $lastPayload = json_decode($complete, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($lastPayload);
        self::assertIsArray($lastPayload['entries']);
        self::assertIsArray($lastPayload['entries'][$last->getPathname()]);
        $badRow               = $lastPayload['entries'][$last->getPathname()];
        $badRow['isFallback'] = 'false';
        $lastOffset           = strrpos($complete, ',' . json_encode($last->getPathname(), JSON_THROW_ON_ERROR) . ':');
        self::assertNotFalse($lastOffset);
        $lateInvalid = substr($complete, 0, $lastOffset)
            . ',' . json_encode($last->getPathname(), JSON_THROW_ON_ERROR) . ':' . json_encode($badRow, JSON_THROW_ON_ERROR) . "\n}}";
        self::assertIsString($lateInvalid);

        foreach ([substr($complete, 0, -2), $complete . 'unexpected', $lateInvalid] as $broken) {
            $filesystem->dumpFile($this->cachePath(), $broken);
            $fresh = $this->createCache();
            self::assertNotNull($fresh->getDiagnostic());
            self::assertNull($fresh->get($first));
            self::assertNull($fresh->get($last));
        }
    }

    /**
     * Historical rows must load and flush within a budget that rejects eager decoding.
     */
    #[Test]
    public function largeHistoricalSnapshotUsesBoundedRowDecoding(): void
    {
        $file  = $this->createFile('retained.jpg');
        $cache = $this->createCache();
        $cache->set($file, new TemporalMetadata(new DateTimeImmutable('2024-01-15T10:30:00Z'), 'synthetic'));
        $cache->flush();

        $filesystem = new Filesystem();
        $payload    = json_decode($filesystem->readFile($this->cachePath()), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['entries']);
        $row    = json_encode($payload['entries'][$file->getPathname()], JSON_THROW_ON_ERROR);
        $header = '{"_version":' . json_encode($payload['_version'], JSON_THROW_ON_ERROR)
            . ',"_parser":' . json_encode($payload['_parser'], JSON_THROW_ON_ERROR) . ',"entries":{' . "\n";
        $chunks = static function () use ($header, $row, $file): Generator {
            yield $header;

            for ($index = 0; $index < 20000; ++$index) {
                $path = $index === 0 ? $file->getPathname() : '/synthetic-historical-' . $index . '.jpg';
                yield ($index === 0 ? '' : ',') . json_encode($path, JSON_THROW_ON_ERROR) . ':' . $row . "\n";
            }

            yield '}}';
        };
        new PrivateCacheStorage($filesystem)->write($this->cachePath(), $chunks());
        $script = $this->workspace . '/bounded-cache.php';
        $filesystem->dumpFile($script, <<<'PHP'
            <?php
            require $argv[1];
            $filesystem = new Symfony\Component\Filesystem\Filesystem();
            $storage = new MagicSunday\Renamer\Helper\PrivateCacheStorage($filesystem);
            $cache = new MagicSunday\Renamer\Metadata\MetadataCache($argv[2], $storage);
            $file = new SplFileInfo($argv[3]);
            if (($cache->getDiagnostic() !== null) || ($cache->get($file)?->getContentId() !== 'synthetic')) {
                throw new RuntimeException('Bounded cache must be warm.');
            }
            $cache->set($file, new MagicSunday\Renamer\Metadata\TemporalMetadata(new DateTimeImmutable('2024-01-15T10:30:00Z'), 'synthetic'));
            $cache->flush();
            unset($cache);
            $fresh = new MagicSunday\Renamer\Metadata\MetadataCache($argv[2], $storage);
            if (($fresh->getDiagnostic() !== null) || ($fresh->get($file)?->getContentId() !== 'synthetic')) {
                throw new RuntimeException('Mixed retained/fresh rows must reload.');
            }
            $lines = 0;
            foreach (new SplFileObject($argv[2]) as $line) { ++$lines; }
            if ($lines !== 20002) { throw new RuntimeException('Historical rows were lost.'); }
            echo 'bounded-cache-pass';
            PHP);
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=32M', $script, dirname(__DIR__, 2) . '/.build/vendor/autoload.php', $this->cachePath(), $file->getPathname()]);
        $process->setTimeout(30);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame('bounded-cache-pass', $process->getOutput());
        self::assertSame('', $process->getErrorOutput());
    }

    /**
     * Interleaves two perceptual snapshots, verifies only the last entry remains
     * warm and explicitly prunes that entry after its synthetic source vanishes.
     * JSON zero values must reload as floats, preserving the typed signal API.
     *
     * @return void
     */
    #[Test]
    public function perceptualSnapshotsHaveTheSameExplicitPruningAndWriterContract(): void
    {
        $firstFile = $this->createFile('first.jpg');
        $lastFile  = $this->createFile('last.jpg');
        $first     = $this->createSignalCache();
        $last      = $this->createSignalCache();
        $signals   = ['dhash' => '0123456789abcdef', 'whash' => null, 'hf' => 0.0, 'hist' => [0.0, 1.0]];
        $first->set($firstFile, $signals);
        $last->set($lastFile, $signals);
        $first->flush();
        $last->flush();
        $fresh = $this->createSignalCache();
        self::assertNull($fresh->get($firstFile));
        self::assertSame($signals, $fresh->get($lastFile));
        new Filesystem()->remove($lastFile->getPathname());
        self::assertSame(1, $fresh->pruneMissing());
        self::assertSame(0, $fresh->pruneMissing());
        $fresh->flush();
        self::assertNull($this->createSignalCache()->get($lastFile));
    }

    /**
     * Corrupts individual fields inside otherwise valid versioned signal JSON.
     * Invalid stat rows, signal types and histogram elements must reject the
     * snapshot before they can cause type errors or unavailable-analysis reuse.
     *
     * @return void
     */
    #[Test]
    public function malformedSignalRowsStartDiagnosableColdCaches(): void
    {
        $file  = $this->createFile('photo.jpg');
        $cache = $this->createSignalCache();
        $cache->set($file, ['dhash' => '0123456789abcdef', 'whash' => null, 'hf' => 0.0, 'hist' => [0.0]]);
        $cache->flush();

        $filesystem = new Filesystem();
        $path       = $this->signalCachePath();
        $original   = $filesystem->readFile($path);

        foreach (['mtime' => 'bad', 'hf' => true, 'hist' => ['bad']] as $field => $value) {
            $payload = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertIsArray($payload['entries']);
            self::assertIsArray($payload['entries'][$file->getPathname()]);
            $payload['entries'][$file->getPathname()][$field] = $value;
            $filesystem->dumpFile($path, json_encode($payload, JSON_THROW_ON_ERROR));
            $fresh = $this->createSignalCache();
            self::assertNull($fresh->get($file));
            self::assertNotNull($fresh->getDiagnostic());
        }
    }

    /**
     * Opens a fresh perceptual snapshot in the same isolated owned cache child.
     *
     * @return PerceptualSignalCache New instance without shared in-memory entries
     */
    private function createSignalCache(): PerceptualSignalCache
    {
        return new PerceptualSignalCache($this->signalCachePath(), new PrivateCacheStorage(new Filesystem()));
    }

    /**
     * Resolves the signal snapshot separately from the metadata JSON.
     *
     * @return string Owned synthetic signal cache path
     */
    private function signalCachePath(): string
    {
        $storage = new PrivateCacheStorage(new Filesystem());

        return $storage->directory($this->workspace) . '/signals.json';
    }

    /**
     * Opens a new real cache instance against the same owned synthetic JSON.
     *
     * @return MetadataCache Fresh instance with no reused in-memory entries
     */
    private function createCache(): MetadataCache
    {
        return new MetadataCache($this->cachePath(), new PrivateCacheStorage(new Filesystem()));
    }

    /**
     * Resolves a private cache pathname without touching production cache state.
     *
     * @return string Owned temporary JSON path
     */
    private function cachePath(): string
    {
        $storage = new PrivateCacheStorage(new Filesystem());

        return $storage->directory($this->workspace) . '/metadata.json';
    }

    /**
     * Creates a tiny regular file so real stat-based cache freshness is exercised.
     *
     * @param string $name Synthetic leaf name
     *
     * @return SplFileInfo Existing source with stable size and mtime
     */
    private function createFile(string $name): SplFileInfo
    {
        $path = $this->workspace . '/' . $name;
        new Filesystem()->dumpFile($path, 'synthetic media');

        return new SplFileInfo($path);
    }
}
