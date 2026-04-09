<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Video;

use MagicSunday\Renamer\Model\Pipeline\VideoFingerprintMatch;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

use function array_key_exists;
use function count;
use function explode;
use function filemtime;
use function filesize;
use function hash;
use function is_numeric;
use function preg_match;
use function sprintf;
use function trim;

/**
 * Hashes video and audio streams via ffmpeg's streamhash muxer.
 *
 * Unlike whole-file hashing, stream-level hashing ignores container metadata
 * rewrites and additional non-A/V data tracks. That makes it suitable for the
 * exact-content duplicate checks required by Feature Track A while still keeping
 * the duplicate policy conservative around missing or mismatched audio.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class VideoStreamFingerprintMatcher implements VideoStreamFingerprintMatcherInterface
{
    /**
     * In-memory fingerprint cache keyed by pathname, mtime, and size.
     *
     * @var array<string, VideoStreamFingerprint>
     */
    private array $fingerprintCache = [];

    /**
     * @param string $ffmpegBinary Path to the ffmpeg binary providing the streamhash muxer.
     */
    public function __construct(
        private readonly string $ffmpegBinary = 'ffmpeg',
    ) {
    }

    /**
     * Compares two videos by hashing their primary video stream and optional audio stream.
     *
     * Non-A/V streams are deliberately ignored so container-only noise does not block
     * a valid exact-content match. The returned DTO leaves the final merge policy to
     * the calling reconciler.
     *
     * @param SplFileInfo $fileA First video file to compare
     * @param SplFileInfo $fileB Second video file to compare
     *
     * @return VideoFingerprintMatch Structured evidence for duplicate policy decisions
     */
    public function match(SplFileInfo $fileA, SplFileInfo $fileB): VideoFingerprintMatch
    {
        $fingerprintA = $this->fingerprint($fileA);
        $fingerprintB = $this->fingerprint($fileB);

        $videoStreamMatched = ($fingerprintA->videoHash !== null)
            && ($fingerprintA->videoHash === $fingerprintB->videoHash);

        if (!$videoStreamMatched) {
            return new VideoFingerprintMatch(false, false, false, false, false);
        }

        if (!$fingerprintA->hasAudio && !$fingerprintB->hasAudio) {
            return new VideoFingerprintMatch(true, false, true, false, false);
        }

        if ($fingerprintA->hasAudio xor $fingerprintB->hasAudio) {
            return new VideoFingerprintMatch(
                true,
                false,
                false,
                true,
                false,
                'video stream identical, audio missing on one side',
            );
        }

        $audioStreamMatched = ($fingerprintA->audioHash !== null)
            && ($fingerprintA->audioHash === $fingerprintB->audioHash);

        if ($audioStreamMatched) {
            return new VideoFingerprintMatch(true, true, false, false, false);
        }

        return new VideoFingerprintMatch(
            true,
            false,
            false,
            false,
            true,
            'video stream identical, audio differs',
        );
    }

    /**
     * Returns the cached fingerprint set for a file, computing it once per file state.
     *
     * @param SplFileInfo $file Video file whose streams should be hashed
     *
     * @return VideoStreamFingerprint Primary video/audio hashes
     */
    private function fingerprint(SplFileInfo $file): VideoStreamFingerprint
    {
        $cacheKey = $this->cacheKey($file);

        if (array_key_exists($cacheKey, $this->fingerprintCache)) {
            return $this->fingerprintCache[$cacheKey];
        }

        $process = new Process([
            $this->ffmpegBinary,
            '-v',
            'error',
            '-i',
            $file->getPathname(),
            '-map',
            '0',
            '-c',
            'copy',
            '-f',
            'streamhash',
            '-hash',
            'sha256',
            '-',
        ]);
        $process->setTimeout(20.0);

        try {
            $process->run();
        } catch (Throwable) {
            return $this->fingerprintCache[$cacheKey] = new VideoStreamFingerprint(null, null, false);
        }

        if (!$process->isSuccessful()) {
            return $this->fingerprintCache[$cacheKey] = new VideoStreamFingerprint(null, null, false);
        }

        $videoHash = null;
        $audioHash = null;

        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if ($line === '') {
                continue;
            }

            $parsedLine = $this->parseStreamhashLine($line);

            if (!$parsedLine instanceof StreamHashRecord) {
                continue;
            }

            if (($parsedLine->type === StreamHashType::Video) && ($videoHash === null)) {
                $videoHash = $parsedLine->hash;
            }

            if (($parsedLine->type === StreamHashType::Audio) && ($audioHash === null)) {
                $audioHash = $parsedLine->hash;
            }
        }

        return $this->fingerprintCache[$cacheKey] = new VideoStreamFingerprint(
            $videoHash,
            $audioHash,
            $audioHash !== null,
        );
    }

    /**
     * Parses one streamhash output line of the form "0,v,SHA256=<hash>".
     *
     * @param string $line Raw line emitted by ffmpeg's streamhash muxer
     *
     * @return StreamHashRecord|null Parsed stream type and hash, or null when the line is unusable
     */
    private function parseStreamhashLine(string $line): ?StreamHashRecord
    {
        $parts = explode(',', $line);

        if (count($parts) !== 3) {
            return null;
        }

        if (!is_numeric($parts[0])) {
            return null;
        }

        if (preg_match('/^SHA256=(.+)$/', $parts[2], $matches) !== 1) {
            return null;
        }

        $type = StreamHashType::tryFrom($parts[1]);

        if ($type === null) {
            return null;
        }

        return new StreamHashRecord($type, $matches[1]);
    }

    /**
     * Builds a cache key from pathname plus current file state.
     *
     * The matcher must invalidate cached hashes when the file changes. Using
     * pathname, mtime, and size provides a cheap and sufficiently stable cache
     * key for this in-process optimization.
     *
     * @param SplFileInfo $file Video file to fingerprint
     *
     * @return string Cache key representing the current on-disk file state
     */
    private function cacheKey(SplFileInfo $file): string
    {
        $path  = $file->getPathname();
        $size  = filesize($path);
        $mtime = filemtime($path);

        return hash('sha256', sprintf('%s|%s|%s', $path, is_numeric($mtime) ? (string) $mtime : '0', is_numeric($size) ? (string) $size : '0'));
    }
}
