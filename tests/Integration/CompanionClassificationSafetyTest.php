<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration;

use Imagick;
use ImagickPixel;
use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Model\MergeDecision;
use MagicSunday\Renamer\Model\MergeDecisionKind;
use MagicSunday\Renamer\Model\MergeDecisionReason;
use MagicSunday\Renamer\Model\Rename;
use MagicSunday\Renamer\Service\ComparisonWorkLimit;
use MagicSunday\Renamer\Service\HashSubGroupingService;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\PerceptualHash\ImagickImageLoader;
use MagicSunday\Renamer\Service\PerceptualHash\LocalDifferenceAnalyzer;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculator;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashMath;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualSignalCache;
use MagicSunday\Renamer\Service\Pipeline\OrphanLivePhotoVideoReconciler;
use MagicSunday\Renamer\Service\Pipeline\SubgroupClassifier;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Service\SafeHashCalculatorInterface;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;

use function file_get_contents;
use function file_put_contents;
use function hash;

/**
 * Exercises the public subgroup service with real image analysis and synthetic
 * companion bytes. Equal companion hashes must never supersede a rejected
 * still-image analysis; these probes do not claim to decode Live Photo videos.
 */
#[CoversNothing]
final class CompanionClassificationSafetyTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Different valid pixels and unavailable pixels both stay separate despite
     * identical companion bytes. The strict operator threshold remains binding.
     *
     * @param string|null         $secondColor Second image color, or null for unavailable pixels
     * @param bool                $merge       Expected positive grouping verdict
     * @param MergeDecisionKind   $kind        Expected evidence category
     * @param MergeDecisionReason $reason      Expected terminal reason
     */
    #[Test]
    #[DataProvider('stillAnalysisCases')]
    public function companionHashCannotOverrideStillAnalysis(?string $secondColor, bool $merge, MergeDecisionKind $kind, MergeDecisionReason $reason): void
    {
        $workspace = $this->createTempWorkspace();

        try {
            $this->writeImage($workspace . '/a.jpg', 'red');

            if ($secondColor !== null) {
                $this->writeImage($workspace . '/b.jpg', $secondColor);
            } else {
                file_put_contents($workspace . '/b.jpg', 'unrelated-invalid-image');
            }

            $group   = new FileDuplicate();
            $renames = [];
            $ids     = [];

            foreach (['a.jpg', 'a.mov', 'b.jpg', 'b.mov'] as $name) {
                $file = new SplFileInfo($workspace . '/' . $name);

                if ($file->getExtension() === 'mov') {
                    file_put_contents($file->getPathname(), 'identical-synthetic-companion-bytes');
                }

                $rename = new Rename($file, new SplFileInfo($workspace . '/capture.' . $file->getExtension()));
                $group->addFile($file)->addRename($rename);
                $renames[$name]            = $rename;
                $ids[$file->getPathname()] = $name[0] . '-synthetic-content-id';
            }

            $group->setTarget($renames['a.jpg']->getTarget());
            $media   = new MediaTypeClassifier();
            $loader  = new ImagickImageLoader($media);
            $service = new HashSubGroupingService(new SafeHashCalculator(), new NullProgressReporter(), $media, new PerceptualHashCalculator($loader, new PerceptualHashMath()), new LocalDifferenceAnalyzer(), $loader, new ComparisonWorkLimit(100000));
            $service->setMaxMergeRmse(0.0);
            /** @var list<MergeDecision> $decisions */
            $decisions = [];
            $result    = $service->apply($group, $renames['a.jpg'], $renames['a.mov'], $ids, static fn (SplFileInfo $file, string $name): string => $file->getPath() . '/' . $name, onDecision: static function (MergeDecision $decision) use (&$decisions): void {
                $decisions[] = $decision;
            });

            self::assertSame($merge, $result === null);
            self::assertCount(1, $decisions);
            self::assertSame($kind, $decisions[0]->kind);
            self::assertSame($reason, $decisions[0]->reason);
            self::assertSame($merge, $decisions[0]->permitsMerge());

            if (!$merge) {
                self::assertIsArray($result);
                self::assertNotSame($result[$workspace . '/a.jpg'], $result[$workspace . '/b.jpg']);
            }

            if ($reason === MergeDecisionReason::RmseVeto) {
                self::assertNotNull($decisions[0]->rmse);
                self::assertGreaterThan(0.1, $decisions[0]->rmse);
                self::assertSame(0.0, $decisions[0]->mergeThreshold);
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Covers both a successful analysis with RMSE above zero and an unavailable
     * image, rather than relying only on injected similarity verdicts.
     *
     * @return iterable<string, array{string|null, bool, MergeDecisionKind, MergeDecisionReason}> Valid and invalid image cases
     */
    public static function stillAnalysisCases(): iterable
    {
        yield 'RMSE veto' => ['blue', false, MergeDecisionKind::Different, MergeDecisionReason::RmseVeto];
        yield 'unavailable decode' => [null, false, MergeDecisionKind::Uncertain, MergeDecisionReason::SignalUnavailable];
        yield 'verified identical pixels' => ['red', true, MergeDecisionKind::Perceptual, MergeDecisionReason::VisualMatch];
    }

    /**
     * Preserves the original public-service reproduction: two existing invalid
     * HEIC/JPG files, real collaborators, one target stem and strict threshold
     * zero. Their unavailable pixels must remain uncertain on every run.
     */
    #[Test]
    public function existingUndecodableCrossFormatFilesStayUncertain(): void
    {
        $workspace = $this->createTempWorkspace();

        try {
            $group = new FileDuplicate();

            foreach (['a.heic' => 'completely-different-content-A', 'b.jpg' => 'unrelated-content-B'] as $name => $bytes) {
                $file = new SplFileInfo($workspace . '/' . $name);
                file_put_contents($file->getPathname(), $bytes);
                $group->addFile($file)->addRename(new Rename($file, new SplFileInfo($workspace . '/capture.' . $file->getExtension())));
            }

            $canonical = $group->getRenames()->get(0);
            self::assertInstanceOf(Rename::class, $canonical);
            $group->setTarget($canonical->getTarget());
            $service = $this->createService();
            $service->setMaxMergeRmse(0.0);

            for ($run = 0; $run < 2; ++$run) {
                /** @var list<MergeDecision> $decisions */
                $decisions = [];
                $result    = $service->apply($group, $canonical, null, [], static fn (SplFileInfo $file, string $name): string => $file->getPath() . '/' . $name, onDecision: static function (MergeDecision $decision) use (&$decisions): void {
                    $decisions[] = $decision;
                });
                self::assertIsArray($result);
                self::assertNotSame($result[$workspace . '/a.heic'], $result[$workspace . '/b.jpg']);
                self::assertCount(1, $decisions);
                self::assertSame(MergeDecisionKind::Uncertain, $decisions[0]->kind);
                self::assertSame(MergeDecisionReason::SignalUnavailable, $decisions[0]->reason);
                self::assertFalse($decisions[0]->permitsMerge());
                self::assertNull($decisions[0]->contentHash);
                self::assertNull($decisions[0]->rmse);
                self::assertStringNotContainsString('dHash=', $decisions[0]->describe(), 'Missing gradient signals are not an exact zero-distance measurement.');
                $service->clearCache();
            }

            self::assertSame('completely-different-content-A', file_get_contents($workspace . '/a.heic'));
            self::assertSame('unrelated-content-B', file_get_contents($workspace . '/b.jpg'));
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Equal bytes take the exact-hash path even without decodable image data.
     * The evidence names that hash and does not invent visual measurements.
     */
    #[Test]
    public function exactHashDecisionContainsHashInsteadOfVisualEvidence(): void
    {
        $workspace = $this->createTempWorkspace();

        try {
            $group = new FileDuplicate();

            foreach (['a.jpg', 'b.jpg'] as $name) {
                $file = new SplFileInfo($workspace . '/' . $name);
                file_put_contents($file->getPathname(), 'identical-synthetic-bytes');
                $group->addFile($file)->addRename(new Rename($file, new SplFileInfo($workspace . '/capture.jpg')));
            }

            $canonical = $group->getRenames()->get(0);
            self::assertInstanceOf(Rename::class, $canonical);
            $group->setTarget($canonical->getTarget());
            /** @var list<MergeDecision> $decisions */
            $decisions = [];
            $result    = $this->createService()->apply($group, $canonical, null, [], static fn (SplFileInfo $file, string $name): string => $file->getPath() . '/' . $name, onDecision: static function (MergeDecision $decision) use (&$decisions): void {
                $decisions[] = $decision;
            });

            self::assertNull($result);
            self::assertCount(1, $decisions);
            self::assertSame(MergeDecisionKind::Exact, $decisions[0]->kind);
            self::assertSame(MergeDecisionReason::ContentHashMatch, $decisions[0]->reason);
            self::assertSame(hash('xxh128', 'identical-synthetic-bytes'), $decisions[0]->contentHash);
            self::assertTrue($decisions[0]->permitsMerge());
            self::assertNull($decisions[0]->similarityScore);
            self::assertNull($decisions[0]->rmse);
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * A failed content read cannot be repaired by positive visual heuristics.
     * Deterministic hash failure avoids permission assumptions under root and
     * proves that uncertain source evidence stays independent.
     */
    #[Test]
    public function hashFailureNeverReachesVisualMerge(): void
    {
        $workspace = $this->createTempWorkspace();

        try {
            $group = new FileDuplicate();

            foreach (['a.jpg', 'b.jpg'] as $name) {
                $file = new SplFileInfo($workspace . '/' . $name);
                $this->writeImage($file->getPathname(), 'red');
                $group->addFile($file)->addRename(new Rename($file, new SplFileInfo($workspace . '/capture.jpg')));
            }

            $canonical = $group->getRenames()->get(0);
            self::assertInstanceOf(Rename::class, $canonical);
            $group->setTarget($canonical->getTarget());
            $hashing = self::createStub(SafeHashCalculatorInterface::class);
            $hashing->method('hashFile')->willReturnCallback(static function (SplFileInfo $file, string $algorithm): string {
                if ($file->getFilename() === 'a.jpg') {
                    throw new HashComputationException('synthetic unavailable content');
                }

                return new SafeHashCalculator()->hashFile($file, $algorithm);
            });
            $visual = self::createMock(PerceptualHashCalculatorInterface::class);
            $visual->expects(self::never())->method('similarityScore');
            $media   = new MediaTypeClassifier();
            $service = new HashSubGroupingService($hashing, new NullProgressReporter(), $media, $visual, new LocalDifferenceAnalyzer(), new ImagickImageLoader($media), new ComparisonWorkLimit(100000));
            /** @var list<MergeDecision> $decisions */
            $decisions = [];
            $result    = $service->apply($group, $canonical, null, [], static fn (SplFileInfo $file, string $name): string => $file->getPath() . '/' . $name, onDecision: static function (MergeDecision $decision) use (&$decisions): void {
                $decisions[] = $decision;
            });

            self::assertIsArray($result);
            self::assertNotSame($result[$workspace . '/a.jpg'], $result[$workspace . '/b.jpg']);
            self::assertCount(2, $decisions);

            foreach ($decisions as $decision) {
                self::assertSame(MergeDecisionKind::Uncertain, $decision->kind);
                self::assertSame(MergeDecisionReason::HashReadFailed, $decision->reason);
                self::assertFalse($decision->permitsMerge());
            }
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * A cached dHash/wHash pair with missing HF evidence is incomplete. A cache
     * hit must not label its fallback score as measured dissimilarity. The
     * service reports uncertainty and retains independent source clusters.
     */
    #[Test]
    public function incompleteCachedSignalsRemainUncertain(): void
    {
        $workspace = $this->createTempWorkspace();

        try {
            $cache = new PerceptualSignalCache($workspace . '/cache/signals.json', new PrivateCacheStorage(new Filesystem()));
            $group = new FileDuplicate();

            foreach (['a.jpg' => '0000000000000000', 'b.jpg' => '0000000000000001'] as $name => $dhash) {
                $file = new SplFileInfo($workspace . '/' . $name);
                file_put_contents($file->getPathname(), 'synthetic-' . $name);
                $cache->set($file, ['dhash' => $dhash, 'whash' => '0000000000000000', 'hf' => null, 'hist' => [1.0]]);
                $group->addFile($file)->addRename(new Rename($file, new SplFileInfo($workspace . '/capture.jpg')));
            }

            $canonical = $group->getRenames()->get(0);
            self::assertInstanceOf(Rename::class, $canonical);
            $group->setTarget($canonical->getTarget());
            $media  = new MediaTypeClassifier();
            $loader = new ImagickImageLoader($media);
            $visual = new PerceptualHashCalculator($loader, new PerceptualHashMath());
            $visual->setSignalCache($cache);
            $service = new HashSubGroupingService(new SafeHashCalculator(), new NullProgressReporter(), $media, $visual, new LocalDifferenceAnalyzer(), $loader, new ComparisonWorkLimit(100000));
            /** @var list<MergeDecision> $decisions */
            $decisions = [];
            $result    = $service->apply($group, $canonical, null, [], static fn (SplFileInfo $file, string $name): string => $file->getPath() . '/' . $name, onDecision: static function (MergeDecision $decision) use (&$decisions): void {
                $decisions[] = $decision;
            });

            self::assertIsArray($result);
            self::assertNotSame($result[$workspace . '/a.jpg'], $result[$workspace . '/b.jpg']);
            self::assertCount(1, $decisions);
            self::assertSame(MergeDecisionKind::Uncertain, $decisions[0]->kind);
            self::assertSame(MergeDecisionReason::SignalUnavailable, $decisions[0]->reason);
            self::assertNull($decisions[0]->similarityScore, 'An incomplete score is not available evidence.');
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Ten distinct unavailable stills generate 45 pair decisions. The active
     * classifier exposes their cause while retaining only one example/count
     * for that reason, rather than a quadratic decision-log history.
     */
    #[Test]
    public function pipelineSummarizesRepeatedReasonsWithoutPairHistory(): void
    {
        $workspace = $this->createTempWorkspace();

        try {
            $group = new AssetGroup('capture');

            for ($index = 0; $index < 10; ++$index) {
                $file = new SplFileInfo($workspace . '/source-' . $index . '.jpg');
                file_put_contents($file->getPathname(), 'invalid-image-' . $index);
                $group->addItem(new AssetItem($file));
            }

            $groups = new AssetGroupCollection();
            $groups->set('capture', $group);
            $media      = new MediaTypeClassifier();
            $reporter   = new NullProgressReporter();
            $visual     = new PerceptualHashCalculator(new ImagickImageLoader($media), new PerceptualHashMath());
            $classifier = new SubgroupClassifier($this->createService(), $media, new OrphanLivePhotoVideoReconciler($media, $visual, $reporter), $reporter);
            $classifier->classify($groups);

            self::assertFalse($group->isClassificationDegraded());
            self::assertCount(1, $group->getDecisionLog());
            self::assertStringContainsString('uncertain: perceptual signals unavailable', $group->getDecisionLog()[0]);
            self::assertStringContainsString('(45 comparison(s))', $group->getDecisionLog()[0]);
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Wires the same real analysis collaborators used by the public service
     * probes, without injecting a similarity verdict or relying on DI caches.
     *
     * @return HashSubGroupingService Real analysis service for synthetic files
     */
    private function createService(): HashSubGroupingService
    {
        $media  = new MediaTypeClassifier();
        $loader = new ImagickImageLoader($media);

        return new HashSubGroupingService(new SafeHashCalculator(), new NullProgressReporter(), $media, new PerceptualHashCalculator($loader, new PerceptualHashMath()), new LocalDifferenceAnalyzer(), $loader, new ComparisonWorkLimit(100000));
    }

    /**
     * Creates a small valid JPEG without any personal metadata.
     *
     * @param string $path  Temporary destination
     * @param string $color Controlled image color
     */
    private function writeImage(string $path, string $color): void
    {
        $image = new Imagick();
        $image->newImage(32, 32, new ImagickPixel($color));
        $image->setImageFormat('jpeg');
        $image->setImageProperty('comment', $path);
        $image->writeImage($path);
        $image->clear();
    }
}
