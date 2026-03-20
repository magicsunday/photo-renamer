<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\LivePhoto;

use DateTimeInterface;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use Override;

use function abs;
use function array_filter;
use function array_keys;
use function array_values;
use function asin;
use function cos;
use function count;
use function deg2rad;
use function is_finite;
use function max;
use function min;
use function sin;
use function sqrt;

/**
 * Conservative heuristic for surfacing probable Live Photo still/video pairs
 * with mismatching content identifiers.
 *
 * Matching rules:
 * - exact content-ID pairs are excluded first
 * - stills must expose a still-side Live Photo marker
 * - videos must expose a video-side Live Photo marker and be short-lived
 * - device identity must match
 * - GPS must be present and close
 * - same-second candidates are preferred
 * - ±1 second is allowed only as a fallback tier
 * - only mutually unique 1:1 matches are returned
 *
 * The detector never establishes a pair for renaming purposes. It only marks
 * files that should be shown as `[C]` and skipped for manual review.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LivePhotoConflictDetector implements LivePhotoConflictDetectorInterface
{
    public function __construct(
        private MediaTypeClassifierInterface $mediaTypeClassifier,
        private float $maxVideoDurationSeconds = 3.0,
        private float $maxGpsDistanceMeters = 30.0,
        private int $fallbackSecondWindow = 1,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function detectConflictFiles(array $filesByPath, array $metadataByPath): array
    {
        /** @var list<array{
         *     pathname: string,
         *     captureTimestamp: float,
         *     captureSecond: int,
         *     contentIdentifier: string|null,
         *     deviceKey: string,
         *     latitude: float|null,
         *     longitude: float|null,
         *     videoDurationSeconds: float|null
         * }> $stills */
        $stills = [];

        /** @var list<array{
         *     pathname: string,
         *     captureTimestamp: float,
         *     captureSecond: int,
         *     contentIdentifier: string|null,
         *     deviceKey: string,
         *     latitude: float|null,
         *     longitude: float|null,
         *     videoDurationSeconds: float|null
         * }> $videos */
        $videos = [];

        foreach ($filesByPath as $pathname => $file) {
            $metadata = $metadataByPath[$pathname] ?? null;

            if (!$metadata instanceof TemporalMetadata) {
                continue;
            }

            $captureDateTime = $metadata->getCaptureDateTime();

            if (!$captureDateTime instanceof DateTimeInterface) {
                continue;
            }

            if (!$metadata->hasComparableDeviceIdentity()) {
                continue;
            }

            $asset = [
                'pathname'             => $pathname,
                'captureTimestamp'     => (float) $captureDateTime->format('U.u'),
                'captureSecond'        => $captureDateTime->getTimestamp(),
                'contentIdentifier'    => $metadata->getNormalizedLivePhotoId(),
                'deviceKey'            => $metadata->getNormalizedDeviceKey(),
                'latitude'             => $metadata->getLatitude(),
                'longitude'            => $metadata->getLongitude(),
                'videoDurationSeconds' => $metadata->getVideoDurationSeconds(),
            ];

            if ($this->mediaTypeClassifier->isLivePhotoStill($file)) {
                if ($metadata->hasStillLivePhotoMarker()) {
                    $stills[] = $asset;
                }

                continue;
            }

            if ($metadata->hasVideoLivePhotoMarker()) {
                $videos[] = $asset;
            }
        }

        $exactPairContentIdentifiers = $this->findExactPairContentIdentifiers($stills, $videos);

        $stills = array_values(
            array_filter(
                $stills,
                static fn (array $asset): bool => ($asset['contentIdentifier'] === null)
                    || !isset($exactPairContentIdentifiers[$asset['contentIdentifier']]),
            ),
        );

        $videos = array_values(
            array_filter(
                $videos,
                static fn (array $asset): bool => ($asset['contentIdentifier'] === null)
                    || !isset($exactPairContentIdentifiers[$asset['contentIdentifier']]),
            ),
        );

        [$stillCandidates, $videoCandidates] = $this->buildCandidateMaps($stills, $videos);

        /** @var array<string, true> $conflictFiles */
        $conflictFiles = [];

        foreach ($stillCandidates as $stillIndex => $candidateVideoIndexes) {
            if (count($candidateVideoIndexes) !== 1) {
                continue;
            }

            $videoIndex = $candidateVideoIndexes[0];

            if (($videoCandidates[$videoIndex] ?? []) !== [$stillIndex]) {
                continue;
            }

            $conflictFiles[$stills[$stillIndex]['pathname']] = true;
            $conflictFiles[$videos[$videoIndex]['pathname']] = true;
        }

        return $conflictFiles;
    }

    /**
     * @param list<array{contentIdentifier: string|null}> $stills
     * @param list<array{contentIdentifier: string|null}> $videos
     *
     * @return array<string, true>
     */
    private function findExactPairContentIdentifiers(array $stills, array $videos): array
    {
        /** @var array<string, true> $stillIdentifiers */
        $stillIdentifiers = [];

        /** @var array<string, true> $videoIdentifiers */
        $videoIdentifiers = [];

        foreach ($stills as $asset) {
            if ($asset['contentIdentifier'] !== null) {
                $stillIdentifiers[$asset['contentIdentifier']] = true;
            }
        }

        foreach ($videos as $asset) {
            if ($asset['contentIdentifier'] !== null) {
                $videoIdentifiers[$asset['contentIdentifier']] = true;
            }
        }

        /** @var array<string, true> $pairedIdentifiers */
        $pairedIdentifiers = [];

        foreach ($stillIdentifiers as $contentIdentifier => $_) {
            if (isset($videoIdentifiers[$contentIdentifier])) {
                $pairedIdentifiers[$contentIdentifier] = true;
            }
        }

        return $pairedIdentifiers;
    }

    /**
     * @param list<array{
     *     pathname: string,
     *     captureTimestamp: float,
     *     captureSecond: int,
     *     contentIdentifier: string|null,
     *     deviceKey: string,
     *     latitude: float|null,
     *     longitude: float|null,
     *     videoDurationSeconds: float|null
     * }> $stills
     * @param list<array{
     *     pathname: string,
     *     captureTimestamp: float,
     *     captureSecond: int,
     *     contentIdentifier: string|null,
     *     deviceKey: string,
     *     latitude: float|null,
     *     longitude: float|null,
     *     videoDurationSeconds: float|null
     * }> $videos
     *
     * @return array{array<int, list<int>>, array<int, list<int>>}
     */
    private function buildCandidateMaps(array $stills, array $videos): array
    {
        /** @var array<int, list<int>> $stillCandidates */
        $stillCandidates = [];

        /** @var array<int, list<int>> $videoCandidates */
        $videoCandidates = [];

        /** @var array<int, array{exact: list<int>, fallback: list<int>}> $stillCandidateTiers */
        $stillCandidateTiers = [];

        /** @var array<int, array{exact: list<int>, fallback: list<int>}> $videoCandidateTiers */
        $videoCandidateTiers = [];

        /** @var array<string, array<int, list<int>>> $videoBuckets */
        $videoBuckets = [];

        foreach ($videos as $videoIndex => $video) {
            $videoBuckets[$video['deviceKey']][$video['captureSecond']][] = $videoIndex;
        }

        foreach ($stills as $stillIndex => $still) {
            /** @var array<int, true> $candidateVideoIndexSet */
            $candidateVideoIndexSet = [];

            foreach ($this->candidateSeconds($still['captureSecond']) as $second) {
                foreach ($videoBuckets[$still['deviceKey']][$second] ?? [] as $videoIndex) {
                    $candidateVideoIndexSet[$videoIndex] = true;
                }
            }

            foreach (array_keys($candidateVideoIndexSet) as $videoIndex) {
                $video = $videos[$videoIndex];

                if (!$this->assetsMatchHeuristic($still, $video)) {
                    continue;
                }

                if ($still['captureSecond'] === $video['captureSecond']) {
                    $stillCandidateTiers[$stillIndex]['exact'][] = $videoIndex;
                    $videoCandidateTiers[$videoIndex]['exact'][] = $stillIndex;

                    continue;
                }

                $stillCandidateTiers[$stillIndex]['fallback'][] = $videoIndex;
                $videoCandidateTiers[$videoIndex]['fallback'][] = $stillIndex;
            }
        }

        foreach ($stillCandidateTiers as $stillIndex => $tiers) {
            $stillCandidates[$stillIndex] = $this->choosePreferredCandidates($tiers);
        }

        foreach ($videoCandidateTiers as $videoIndex => $tiers) {
            $videoCandidates[$videoIndex] = $this->choosePreferredCandidates($tiers);
        }

        return [$stillCandidates, $videoCandidates];
    }

    /**
     * @param array{
     *     pathname: string,
     *     captureTimestamp: float,
     *     captureSecond: int,
     *     contentIdentifier: string|null,
     *     deviceKey: string,
     *     latitude: float|null,
     *     longitude: float|null,
     *     videoDurationSeconds: float|null
     * } $still
     * @param array{
     *     pathname: string,
     *     captureTimestamp: float,
     *     captureSecond: int,
     *     contentIdentifier: string|null,
     *     deviceKey: string,
     *     latitude: float|null,
     *     longitude: float|null,
     *     videoDurationSeconds: float|null
     * } $video
     */
    private function assetsMatchHeuristic(array $still, array $video): bool
    {
        if (abs($still['captureSecond'] - $video['captureSecond']) > $this->fallbackSecondWindow) {
            return false;
        }

        if (($video['videoDurationSeconds'] === null) || ($video['videoDurationSeconds'] > $this->maxVideoDurationSeconds)) {
            return false;
        }

        if (($still['contentIdentifier'] === null) || ($video['contentIdentifier'] === null)) {
            return false;
        }

        if ($still['contentIdentifier'] === $video['contentIdentifier']) {
            return false;
        }

        if ($still['deviceKey'] !== $video['deviceKey']) {
            return false;
        }

        $distanceMeters = $this->distanceMeters(
            $still['latitude'],
            $still['longitude'],
            $video['latitude'],
            $video['longitude'],
        );

        return ($distanceMeters !== null) && ($distanceMeters <= $this->maxGpsDistanceMeters);
    }

    /**
     * @param array{exact?: list<int>, fallback?: list<int>} $tiers
     *
     * @return list<int>
     */
    private function choosePreferredCandidates(array $tiers): array
    {
        if (($tiers['exact'] ?? []) !== []) {
            return $tiers['exact'];
        }

        return $tiers['fallback'] ?? [];
    }

    /**
     * @return list<int>
     */
    private function candidateSeconds(int $center): array
    {
        /** @var list<int> $seconds */
        $seconds = [$center];

        for ($offset = 1; $offset <= $this->fallbackSecondWindow; ++$offset) {
            $seconds[] = $center - $offset;
            $seconds[] = $center + $offset;
        }

        return $seconds;
    }

    private function distanceMeters(
        ?float $latitudeA,
        ?float $longitudeA,
        ?float $latitudeB,
        ?float $longitudeB,
    ): ?float {
        if (
            ($latitudeA === null)
            || ($longitudeA === null)
            || ($latitudeB === null)
            || ($longitudeB === null)
        ) {
            return null;
        }

        $earthRadius = 6371000.0;
        $deltaLat    = deg2rad($latitudeB - $latitudeA);
        $deltaLon    = deg2rad($longitudeB - $longitudeA);
        $latA        = deg2rad($latitudeA);
        $latB        = deg2rad($latitudeB);

        $haversine = sin($deltaLat / 2) ** 2
            + (cos($latA) * cos($latB) * sin($deltaLon / 2) ** 2);
        $angle    = 2 * min(1.0, sqrt(max(0.0, $haversine)));
        $distance = 2 * $earthRadius * $this->asinSafe($angle / 2);

        return is_finite($distance) ? $distance : null;
    }

    private function asinSafe(float $value): float
    {
        return asin(min(1.0, max(-1.0, $value)));
    }
}
