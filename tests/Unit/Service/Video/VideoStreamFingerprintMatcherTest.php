<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Video;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Renamer\Helper\NativeToolError;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Pipeline\VideoDuplicateCandidate;
use MagicSunday\Renamer\Model\Pipeline\VideoFingerprintMatch;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Service\ComparisonWorkLimit;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Service\Pipeline\CrossGroupVideoComparisonPlan;
use MagicSunday\Renamer\Service\Pipeline\CrossGroupVideoDuplicateReconciler;
use MagicSunday\Renamer\Service\Pipeline\DurationBucketedVideoCandidate;
use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use MagicSunday\Renamer\Service\Reporting\ProgressReporterInterface;
use MagicSunday\Renamer\Service\Video\StreamHashRecord;
use MagicSunday\Renamer\Service\Video\StreamHashType;
use MagicSunday\Renamer\Service\Video\VideoStreamFingerprint;
use MagicSunday\Renamer\Service\Video\VideoStreamFingerprintMatcher;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function getenv;
use function putenv;
use function sprintf;

use const INF;
use const NAN;

/**
 * Verifies VideoStreamFingerprintMatcher against real ffmpeg-generated videos.
 *
 * The matcher underpins Feature Track A, so the tests focus on the exact policy
 * edges the project cares about: metadata-only rewrites, audio-less duplicates,
 * missing-audio review cases, audio mismatch review cases, and genuine video
 * mismatches.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(VideoStreamFingerprintMatcher::class)]
#[UsesClass(NativeToolError::class)]
#[UsesClass(ComparisonWorkLimit::class)]
#[UsesClass(VideoFingerprintMatch::class)]
#[UsesClass(StreamHashRecord::class)]
#[UsesClass(StreamHashType::class)]
#[UsesClass(VideoStreamFingerprint::class)]
#[UsesClass(TemporalMetadata::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetItem::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(PipelineContext::class)]
#[UsesClass(VideoDuplicateCandidate::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(CrossGroupVideoComparisonPlan::class)]
#[UsesClass(CrossGroupVideoDuplicateReconciler::class)]
#[UsesClass(DurationBucketedVideoCandidate::class)]
#[UsesClass(NullProgressReporter::class)]
final class VideoStreamFingerprintMatcherTest extends TestCase
{
    use WorkspaceTrait;

    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = $this->createTempWorkspace('video-fingerprint-');
    }

    protected function tearDown(): void
    {
        $this->removeWorkspace($this->workspace);
    }

    /**
     * The test deadline must never introduce an unlimited or invalid native
     * process budget; invalid values fail at construction before touching media.
     *
     * @param float $timeout Invalid timeout value
     *
     * @return void
     */
    #[Test]
    #[DataProvider('invalidTimeouts')]
    public function invalidDeadlinesAreRejected(float $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        new VideoStreamFingerprintMatcher(timeout: $timeout);
    }

    /**
     * Supplies non-positive and non-finite values rejected by the deadline contract.
     *
     * @return iterable<string, array{float}> Invalid deadlines
     */
    public static function invalidTimeouts(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'infinite' => [INF];
        yield 'nan' => [NAN];
    }

    /**
     * Real child-process stderr preserves decoder context while removing the
     * named synthetic credential; exit-success without hashes is unavailable too.
     *
     * @return void
     */
    #[Test]
    public function nativeStderrRedactsNamedCredentialAndEmptySuccessIsUnavailable(): void
    {
        $filesystem = new Filesystem();
        $source     = $this->workspace . '/synthetic.mov';
        $tool       = $this->workspace . '/synthetic-tool.sh';
        $filesystem->dumpFile($source, 'synthetic media');
        $filesystem->dumpFile($tool, '#!/bin/sh' . "\n" . 'printf "decoder synthetic-credential-133" >&2' . "\nexit 47\n");
        $filesystem->chmod($tool, 0o700);

        $previous = getenv('RENAMER_TEST_TOKEN');
        putenv('RENAMER_TEST_TOKEN=synthetic-credential-133');

        try {
            $file    = new SplFileInfo($source);
            $failure = new VideoStreamFingerprintMatcher($tool)->match($file, $file);
            self::assertSame('ffmpeg exited with code 47: decoder [redacted]', $failure->reviewReason);
            self::assertFalse($failure->analysisAvailable);
            $filesystem->dumpFile($tool, "#!/bin/sh\nexit 0\n");
            $empty = new VideoStreamFingerprintMatcher($tool)->match($file, $file);
            self::assertFalse($empty->analysisAvailable);
            self::assertFalse($empty->isExactDuplicate());
            self::assertSame('ffmpeg produced no usable video stream fingerprint', $empty->reviewReason);
        } finally {
            putenv($previous === false ? 'RENAMER_TEST_TOKEN' : 'RENAMER_TEST_TOKEN=' . $previous);
        }
    }

    /**
     * Three eligible groups with unavailable stream evidence retain all assets,
     * produce no duplicate candidates and report only one operator diagnostic.
     *
     * @return void
     */
    #[Test]
    public function reconcilerReportsUnavailableEvidenceWithoutMerging(): void
    {
        $groups     = new AssetGroupCollection();
        $filesystem = new Filesystem();

        foreach (['a', 'b', 'c'] as $name) {
            $path = $this->workspace . '/' . $name . '.mov';
            $filesystem->dumpFile($path, 'synthetic media');
            $group = new AssetGroup($name);
            $group->addItem(new AssetItem(new SplFileInfo($path), metadata: new TemporalMetadata(
                new DateTimeImmutable('2024-01-01T10:00:00+00:00'),
                null,
                videoDurationSeconds: 0.5,
            )));
            $groups->set($name, $group);
        }

        $reporter = $this->createMock(ProgressReporterInterface::class);
        $reporter->expects(self::once())->method('error')->with(self::stringContains('ffmpeg'));
        $context    = new PipelineContext($this->workspace);
        $reconciler = new CrossGroupVideoDuplicateReconciler(
            new MediaTypeClassifier(),
            new VideoStreamFingerprintMatcher($this->workspace . '/missing-ffmpeg.sh'),
            $reporter,
            new ComparisonWorkLimit(100000),
        );
        $reconciler->reconcile($groups, $context);
        self::assertCount(3, $groups);
        self::assertSame([], $context->getVideoDuplicateCandidates());

        foreach ($groups as $group) {
            self::assertCount(1, $group->getItems());
            self::assertSame('synthetic media', $filesystem->readFile($group->getItems()[0]->file->getPathname()));
        }
    }

    /**
     * An absent ffmpeg executable supplies no comparison evidence. The caller
     * must receive an unavailable-analysis reason rather than a silent result
     * indistinguishable from two successfully measured different streams.
     *
     * @return void
     */
    #[Test]
    public function missingToolRetainsUnavailableAnalysisReason(): void
    {
        $source = $this->workspace . '/synthetic.mov';
        new Filesystem()->dumpFile($source, 'synthetic media bytes');
        $matcher = new VideoStreamFingerprintMatcher($this->workspace . '/missing-ffmpeg.sh');
        $file    = new SplFileInfo($source);
        $match   = $matcher->match($file, $file);

        self::assertNotNull($match->reviewReason);
        self::assertStringContainsString('ffmpeg', $match->reviewReason);
        self::assertFalse($match->analysisAvailable);
        self::assertFalse($match->isExactDuplicate());
    }

    /**
     * A real child process exits 47 after writing a synthetic decode diagnostic.
     * Preserve both exit status and useful cause in unavailable evidence, while
     * keeping the source unchanged and refusing exact duplicate authority.
     *
     * @return void
     */
    #[Test]
    public function nonzeroToolExitRetainsStatusAndDiagnostic(): void
    {
        $filesystem = new Filesystem();
        $tool       = $this->workspace . '/failed-ffmpeg.sh';
        $source     = $this->workspace . '/synthetic.mov';
        $filesystem->dumpFile($source, 'synthetic media bytes');
        $filesystem->dumpFile($tool, "#!/usr/bin/env sh\nprintf 'decoder rejected synthetic stream' >&2\nexit 47\n");
        $filesystem->chmod($tool, 0o755);

        $file  = new SplFileInfo($source);
        $match = new VideoStreamFingerprintMatcher($tool)->match($file, $file);

        self::assertNotNull($match->reviewReason);
        self::assertStringContainsString('47', $match->reviewReason);
        self::assertStringContainsString('decoder rejected synthetic stream', $match->reviewReason);
        self::assertFalse($match->analysisAvailable);
        self::assertFalse($match->isExactDuplicate());
        self::assertSame('synthetic media bytes', $filesystem->readFile($source));
    }

    /**
     * A real child sleeps beyond a short configured deadline. The returned
     * evidence records the timeout rather than claiming measured inequality;
     * Symfony terminates the child and no source mutation takes place.
     *
     * @return void
     */
    #[Test]
    public function timedOutToolRemainsUnavailable(): void
    {
        $filesystem = new Filesystem();
        $tool       = $this->workspace . '/slow-ffmpeg.sh';
        $source     = $this->workspace . '/synthetic.mov';
        $filesystem->dumpFile($source, 'synthetic media bytes');
        $filesystem->dumpFile($tool, "#!/usr/bin/env sh\nexec sleep 3\n");
        $filesystem->chmod($tool, 0o755);

        $file  = new SplFileInfo($source);
        $match = new VideoStreamFingerprintMatcher($tool, 0.05)->match($file, $file);

        self::assertNotNull($match->reviewReason);
        self::assertStringContainsString('timed out after 0.05 seconds', $match->reviewReason);
        self::assertFalse($match->analysisAvailable);
        self::assertFalse($match->isExactDuplicate());
        self::assertSame('synthetic media bytes', $filesystem->readFile($source));
    }

    /**
     * Verifies that a pure container-metadata remux still counts as an exact
     * duplicate because the video and audio streams remain byte-identical.
     *
     * This is the core motivation for the matcher: whole-file hashes differ after
     * metadata rewrites, but stream hashes must still match.
     */
    #[Test]
    public function matchTreatsMetadataOnlyRewriteAsExactDuplicate(): void
    {
        $matcher   = new VideoStreamFingerprintMatcher();
        $original  = $this->createVideoWithAudio('original.mov', 'blue', '440');
        $rewritten = $this->remuxWithMetadata($original, 'rewritten.mov');

        $match = $matcher->match(new SplFileInfo($original), new SplFileInfo($rewritten));

        self::assertTrue($match->isExactDuplicate());
        self::assertFalse($match->isCandidate());
    }

    /**
     * Verifies that two audio-less videos with identical video streams still qualify
     * as exact duplicates.
     *
     * The policy explicitly allows both-without-audio files to auto-merge when the
     * video stream matches exactly.
     */
    #[Test]
    public function matchTreatsTwoSilentCopiesAsExactDuplicate(): void
    {
        $matcher   = new VideoStreamFingerprintMatcher();
        $original  = $this->createVideoOnly('silent.mov', 'green');
        $rewritten = $this->remuxWithMetadata($original, 'silent-copy.mov');

        $match = $matcher->match(new SplFileInfo($original), new SplFileInfo($rewritten));

        self::assertTrue($match->isExactDuplicate());
        self::assertTrue($match->bothWithoutAudio);
    }

    /**
     * Verifies that a missing-audio mismatch is surfaced as a review candidate
     * instead of an automatic exact duplicate.
     *
     * Feature Track A must stay conservative when only one side carries audio,
     * even if the video stream itself is byte-identical.
     */
    #[Test]
    public function matchFlagsMissingAudioOnOneSideAsCandidate(): void
    {
        $matcher   = new VideoStreamFingerprintMatcher();
        $withAudio = $this->createVideoWithAudio('with-audio.mov', 'yellow', '440');
        $videoOnly = $this->stripAudio($withAudio, 'without-audio.mov');

        $match = $matcher->match(new SplFileInfo($withAudio), new SplFileInfo($videoOnly));

        self::assertFalse($match->isExactDuplicate());
        self::assertTrue($match->isCandidate());
        self::assertSame('video stream identical, audio missing on one side', $match->reviewReason);
    }

    /**
     * Verifies that differing audio streams keep the pair in review-only state
     * even when the underlying video stream is still byte-identical.
     *
     * This protects against silently merging videos whose picture content matches
     * but whose audible content was replaced or re-authored.
     */
    #[Test]
    public function matchFlagsAudioMismatchAsCandidate(): void
    {
        $matcher    = new VideoStreamFingerprintMatcher();
        $withAudioA = $this->createVideoWithAudio('audio-a.mov', 'purple', '440');
        $withAudioB = $this->replaceAudio($withAudioA, 'audio-b.mov', '880');

        $match = $matcher->match(new SplFileInfo($withAudioA), new SplFileInfo($withAudioB));

        self::assertFalse($match->isExactDuplicate());
        self::assertTrue($match->isCandidate());
        self::assertSame('video stream identical, audio differs', $match->reviewReason);
    }

    /**
     * Verifies that different video streams are rejected outright, regardless of
     * any matching container shape or duration.
     *
     * The matcher must not produce review noise when the exact-content prerequisite
     * already fails at the video-stream level.
     */
    #[Test]
    public function matchRejectsDifferentVideoStreams(): void
    {
        $matcher = new VideoStreamFingerprintMatcher();
        $videoA  = $this->createVideoWithAudio('video-a.mov', 'blue', '440');
        $videoB  = $this->createVideoWithAudio('video-b.mov', 'red', '440');

        $match = $matcher->match(new SplFileInfo($videoA), new SplFileInfo($videoB));

        self::assertFalse($match->isExactDuplicate());
        self::assertFalse($match->isCandidate());
        self::assertFalse($match->videoStreamMatched);
        self::assertTrue($match->analysisAvailable);
    }

    /**
     * Compares real MOVs whose primary AV streams are copied unchanged but which
     * carry additional tracks. Neither differing secondary content nor a track
     * present on only one side may be auto-merged. Even identical secondary tracks
     * remain review-only until a complete multi-track comparison is supported.
     * The real reconciler must keep both groups and expose the review reason.
     *
     * @param string $streamSelector Audio or video stream to append from the donor
     * @param bool   $bothMultitrack Whether the other side also has an additional stream
     * @param bool   $sameSecondary  Whether both secondary streams are byte-identical
     */
    #[Test]
    #[DataProvider('multiTrackScenarios')]
    public function additionalAvStreamsRequireReview(string $streamSelector, bool $bothMultitrack, bool $sameSecondary): void
    {
        $base       = $this->createVideoWithAudio('base.mov', 'blue', '440');
        $donorA     = $this->createVideoWithAudio('donor-a.mov', 'red', '880');
        $donorB     = $this->createVideoWithAudio('donor-b.mov', 'green', '1760');
        $left       = $this->appendStream($base, $donorA, 'left.mov', $streamSelector);
        $rightDonor = $sameSecondary ? $donorA : $donorB;
        $right      = $bothMultitrack
            ? $this->appendStream($base, $rightDonor, 'right.mov', $streamSelector)
            : $base;
        $matcher = new VideoStreamFingerprintMatcher();

        foreach ([[$left, $right], [$right, $left]] as [$pathA, $pathB]) {
            $match = $matcher->match(new SplFileInfo($pathA), new SplFileInfo($pathB));
            self::assertTrue($match->videoStreamMatched);
            self::assertTrue($match->hasAdditionalAvStreams);
            self::assertFalse($match->isExactDuplicate());
            self::assertTrue($match->isCandidate());
            self::assertSame('primary video stream identical, additional audio/video streams require review', $match->reviewReason);
        }

        $groups = new AssetGroupCollection();

        foreach ([$left, $right] as $index => $path) {
            $group = new AssetGroup('2024-01-0' . ($index + 1) . '_10-00-00-000');
            $group->addItem(new AssetItem(new SplFileInfo($path), metadata: new TemporalMetadata(
                new DateTimeImmutable('2024-01-01T10:00:00+00:00'),
                null,
                videoDurationSeconds: 0.5,
            )));
            $groups->set($group->groupKey, $group);
        }

        $context    = new PipelineContext($this->workspace);
        $reconciler = new CrossGroupVideoDuplicateReconciler(new MediaTypeClassifier(), $matcher, new NullProgressReporter(), new ComparisonWorkLimit(100000));
        $reconciler->reconcile($groups, $context);
        self::assertCount(2, $groups);

        foreach ($groups as $group) {
            self::assertCount(1, $group->getItems());
        }

        self::assertCount(1, $context->getVideoDuplicateCandidates());
        self::assertStringContainsString('additional audio/video streams', $context->getVideoDuplicateCandidates()[0]->reason);
    }

    /**
     * Supplies supported-primary-layout pairs with unsupported secondary tracks.
     *
     * @return iterable<string, array{string, bool, bool}> Stream selector and secondary-track layout
     */
    public static function multiTrackScenarios(): iterable
    {
        yield 'different second audio' => ['a:0', true, false];
        yield 'extra audio on one side' => ['a:0', false, false];
        yield 'different second video' => ['v:0', true, false];
        yield 'extra video on one side' => ['v:0', false, false];
        yield 'identical extra audio still requires review' => ['a:0', true, true];
    }

    /**
     * Appends one donor track while preserving every original packet via stream-copy.
     *
     * @param string $source   Original single-video/single-audio MOV
     * @param string $donor    MOV supplying the additional track
     * @param string $filename Output filename in the temporary workspace
     * @param string $selector Donor audio/video selector
     *
     * @return string Generated multi-track MOV pathname
     */
    private function appendStream(string $source, string $donor, string $filename, string $selector): string
    {
        $target = $this->workspace . '/' . $filename;
        $this->runProcess(['ffmpeg', '-y', '-i', $source, '-i', $donor, '-map', '0', '-map', '1:' . $selector, '-c', 'copy', $target]);

        return $target;
    }

    /**
     * Creates a tiny MOV with both video and audio streams.
     *
     * @param string $filename  Output filename inside the temporary workspace
     * @param string $color     FFmpeg lavfi color name for the generated video
     * @param string $frequency Audio sine frequency in Hz
     *
     * @return string Absolute path to the generated file
     */
    private function createVideoWithAudio(string $filename, string $color, string $frequency): string
    {
        $path = $this->workspace . '/' . $filename;

        $this->runProcess([
            'ffmpeg',
            '-y',
            '-f',
            'lavfi',
            '-i',
            sprintf('color=%s:s=64x64:d=0.5', $color),
            '-f',
            'lavfi',
            '-i',
            sprintf('sine=frequency=%s:sample_rate=44100:duration=0.5', $frequency),
            '-c:v',
            'libx264',
            '-c:a',
            'aac',
            $path,
        ]);

        return $path;
    }

    /**
     * Creates a tiny MOV with only a video stream.
     *
     * @param string $filename Output filename inside the temporary workspace
     * @param string $color    FFmpeg lavfi color name for the generated video
     *
     * @return string Absolute path to the generated file
     */
    private function createVideoOnly(string $filename, string $color): string
    {
        $path = $this->workspace . '/' . $filename;

        $this->runProcess([
            'ffmpeg',
            '-y',
            '-f',
            'lavfi',
            '-i',
            sprintf('color=%s:s=64x64:d=0.5', $color),
            '-c:v',
            'libx264',
            $path,
        ]);

        return $path;
    }

    /**
     * Rewraps a MOV with different container metadata while preserving streams.
     *
     * @param string $sourcePath Source MOV whose streams should be copied unchanged
     * @param string $filename   Output filename inside the temporary workspace
     *
     * @return string Absolute path to the remuxed file
     */
    private function remuxWithMetadata(string $sourcePath, string $filename): string
    {
        $targetPath = $this->workspace . '/' . $filename;

        $this->runProcess([
            'ffmpeg',
            '-y',
            '-i',
            $sourcePath,
            '-map',
            '0',
            '-c',
            'copy',
            '-metadata',
            'creation_time=2026-04-03T10:15:00Z',
            $targetPath,
        ]);

        return $targetPath;
    }

    /**
     * Drops the audio stream while preserving the original video stream.
     *
     * @param string $sourcePath Source MOV whose video stream should be copied
     * @param string $filename   Output filename inside the temporary workspace
     *
     * @return string Absolute path to the audio-less MOV
     */
    private function stripAudio(string $sourcePath, string $filename): string
    {
        $targetPath = $this->workspace . '/' . $filename;

        $this->runProcess([
            'ffmpeg',
            '-y',
            '-i',
            $sourcePath,
            '-map',
            '0:v:0',
            '-c',
            'copy',
            $targetPath,
        ]);

        return $targetPath;
    }

    /**
     * Replaces the audio stream while preserving the original video stream.
     *
     * @param string $sourcePath Source MOV whose video stream should be reused
     * @param string $filename   Output filename inside the temporary workspace
     * @param string $frequency  Sine frequency for the replacement audio
     *
     * @return string Absolute path to the remuxed MOV with replacement audio
     */
    private function replaceAudio(string $sourcePath, string $filename, string $frequency): string
    {
        $targetPath = $this->workspace . '/' . $filename;

        $this->runProcess([
            'ffmpeg',
            '-y',
            '-i',
            $sourcePath,
            '-f',
            'lavfi',
            '-i',
            sprintf('sine=frequency=%s:sample_rate=44100:duration=0.5', $frequency),
            '-map',
            '0:v:0',
            '-map',
            '1:a:0',
            '-c:v',
            'copy',
            '-c:a',
            'aac',
            $targetPath,
        ]);

        return $targetPath;
    }

    /**
     * Runs a process and fails the test with captured stderr when it does not succeed.
     *
     * @param list<string> $command Command and arguments to execute
     */
    private function runProcess(array $command): void
    {
        $process = new Process($command, $this->workspace);
        $process->setTimeout(30.0);
        $process->run();

        self::assertTrue(
            $process->isSuccessful(),
            $process->getErrorOutput(),
        );
    }
}
