<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Video;

use MagicSunday\Renamer\Model\Pipeline\VideoFingerprintMatch;
use MagicSunday\Renamer\Service\Video\StreamHashRecord;
use MagicSunday\Renamer\Service\Video\StreamHashType;
use MagicSunday\Renamer\Service\Video\VideoStreamFingerprint;
use MagicSunday\Renamer\Service\Video\VideoStreamFingerprintMatcher;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Process\Process;

use function sprintf;

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
#[UsesClass(VideoFingerprintMatch::class)]
#[UsesClass(StreamHashRecord::class)]
#[UsesClass(StreamHashType::class)]
#[UsesClass(VideoStreamFingerprint::class)]
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
