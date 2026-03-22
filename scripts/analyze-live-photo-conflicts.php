<?php

declare(strict_types=1);

use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;

require_once __DIR__ . '/../.build/vendor/autoload.php';

final readonly class AssetRecord
{
    public function __construct(
        public string $pathname,
        public string $type,
        public ?float $captureTimestamp,
        public ?int $captureSecond,
        public ?float $videoDurationSeconds,
        public ?string $contentIdentifier,
        public bool $hasStillMarker,
        public bool $hasVideoMarker,
        public ?string $make,
        public ?string $model,
        public ?string $software,
        public ?float $latitude,
        public ?float $longitude,
    ) {
    }

    public function deviceKey(): string
    {
        return normalizeString($this->make) . '|' . normalizeString($this->model) . '|' . normalizeString($this->software);
    }
}

final readonly class ConflictPair
{
    public function __construct(
        public AssetRecord $still,
        public AssetRecord $video,
        public float $timeDifferenceSeconds,
        public ?float $distanceMeters,
    ) {
    }
}

/**
 * @return array{root: string, windowSeconds: float, gpsMeters: float, limit: int}
 */
function parseArguments(array $argv): array
{
    $root          = '/fotos';
    $windowSeconds = 1.0;
    $gpsMeters     = 30.0;
    $videoMax      = 3.0;
    $limit         = 20;

    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--window-seconds=')) {
            $windowSeconds = max(0.0, (float) substr($argument, 17));

            continue;
        }

        if (str_starts_with($argument, '--gps-meters=')) {
            $gpsMeters = max(0.0, (float) substr($argument, 13));

            continue;
        }

        if (str_starts_with($argument, '--limit=')) {
            $limit = max(0, (int) substr($argument, 8));

            continue;
        }

        if (str_starts_with($argument, '--video-max-seconds=')) {
            $videoMax = max(0.0, (float) substr($argument, 20));

            continue;
        }

        if (!str_starts_with($argument, '--')) {
            $root = $argument;
        }
    }

    $resolvedRoot = realpath($root);

    if (($resolvedRoot === false) || !is_dir($resolvedRoot)) {
        throw new RuntimeException(sprintf('Directory not found: %s', $root));
    }

    return [
        'root'          => $resolvedRoot,
        'windowSeconds' => $windowSeconds,
        'gpsMeters'     => $gpsMeters,
        'videoMax'      => $videoMax,
        'limit'         => $limit,
    ];
}

function main(array $argv): int
{
    $options        = parseArguments($argv);
    $metadataReader = MetadataReader::createDefault();

    $supportedExtensions = ['jpg', 'jpeg', 'heic', 'mov', 'mp4'];
    $scannedFiles        = 0;
    $readErrors          = 0;

    /** @var list<AssetRecord> $stills */
    $stills = [];

    /** @var list<AssetRecord> $videos */
    $videos = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($options['root'], FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }

        $extension = strtolower($fileInfo->getExtension());

        if (!in_array($extension, $supportedExtensions, true)) {
            continue;
        }

        ++$scannedFiles;

        if (($scannedFiles % 500) === 0) {
            fwrite(STDERR, sprintf("Scanned %d files\r", $scannedFiles));
        }

        try {
            $asset = buildAssetRecord($fileInfo, $metadataReader);
        } catch (Throwable) {
            ++$readErrors;

            continue;
        }

        if (!$asset instanceof AssetRecord) {
            continue;
        }

        if ($asset->type === 'still') {
            $stills[] = $asset;

            continue;
        }

        $videos[] = $asset;
    }

    fwrite(STDERR, sprintf("Scanned %d files\n", $scannedFiles));

    $candidateStills = array_values(
        array_filter(
            $stills,
            static fn (AssetRecord $asset): bool => $asset->hasStillMarker && ($asset->captureTimestamp !== null),
        ),
    );

    $candidateVideos = array_values(
        array_filter(
            $videos,
            static fn (AssetRecord $asset): bool => $asset->hasVideoMarker && ($asset->captureTimestamp !== null),
        ),
    );

    $exactPairContentIdentifiers = findExactPairContentIdentifiers($candidateStills, $candidateVideos);

    $candidateStills = array_values(
        array_filter(
            $candidateStills,
            static fn (AssetRecord $asset): bool => ($asset->contentIdentifier === null)
                || !isset($exactPairContentIdentifiers[$asset->contentIdentifier]),
        ),
    );

    $candidateVideos = array_values(
        array_filter(
            $candidateVideos,
            static fn (AssetRecord $asset): bool => ($asset->contentIdentifier === null)
                || !isset($exactPairContentIdentifiers[$asset->contentIdentifier]),
        ),
    );

    [$stillCandidates, $videoCandidates] = buildCandidateMaps(
        $candidateStills,
        $candidateVideos,
        $options['windowSeconds'],
        $options['gpsMeters'],
        $options['videoMax'],
    );

    $conflictPairs = resolveUniqueConflictPairs($candidateStills, $candidateVideos, $stillCandidates, $videoCandidates);

    printSummary(
        $options['root'],
        $scannedFiles,
        $readErrors,
        $candidateStills,
        $candidateVideos,
        $conflictPairs,
        $options['limit'],
    );

    return 0;
}

/**
 * @param list<AssetRecord> $stills
 * @param list<AssetRecord> $videos
 *
 * @return array<string, true>
 */
function findExactPairContentIdentifiers(array $stills, array $videos): array
{
    /** @var array<string, true> $stillIdentifiers */
    $stillIdentifiers = [];

    /** @var array<string, true> $videoIdentifiers */
    $videoIdentifiers = [];

    foreach ($stills as $asset) {
        if ($asset->contentIdentifier !== null) {
            $stillIdentifiers[$asset->contentIdentifier] = true;
        }
    }

    foreach ($videos as $asset) {
        if ($asset->contentIdentifier !== null) {
            $videoIdentifiers[$asset->contentIdentifier] = true;
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

function buildAssetRecord(SplFileInfo $file, MetadataReader $metadataReader): ?AssetRecord
{
    $metadata = $metadataReader->read($file->getPathname());

    if (!$metadata instanceof Metadata) {
        return null;
    }

    $structured = $metadata->structured();
    $extension  = strtolower($file->getExtension());

    $type = in_array($extension, ['mov', 'mp4'], true) ? 'video' : 'still';

    $contentIdentifier = extractContentIdentifier($metadata);
    $stillMarker       = ($contentIdentifier !== null)
        || ($structured->makerNotesApple?->livePhoto?->index !== null);
    $videoMarker       = ($contentIdentifier !== null)
        || hasQuickTimeLivePhotoMarker($metadata);

    return new AssetRecord(
        pathname: $file->getPathname(),
        type: $type,
        captureTimestamp: extractCaptureTimestamp($structured),
        captureSecond: extractCaptureSecond($structured),
        videoDurationSeconds: extractVideoDurationSeconds($metadata),
        contentIdentifier: $contentIdentifier,
        hasStillMarker: $stillMarker,
        hasVideoMarker: $videoMarker,
        make: normalizeNullable($structured->hardware->camera?->make),
        model: normalizeNullable($structured->hardware->camera?->model),
        software: normalizeNullable($structured->hardware->device?->software),
        latitude: $structured->locationTime->gps?->position?->latitudeSigned,
        longitude: $structured->locationTime->gps?->position?->longitudeSigned,
    );
}

function extractContentIdentifier(Metadata $metadata): ?string
{
    $contentIdentifier = $metadata->structured()->makerNotesApple?->identity?->contentIdentifier
        ?? $metadata->quickTime?->contentIdentifier();

    return normalizeNullable($contentIdentifier);
}

function extractCaptureTimestamp(object $structured): ?float
{
    $temporal = $structured->locationTime->temporal;
    $dateTime = $temporal->original
        ?? $temporal->create
        ?? $structured->locationTime->capture->dateTime;

    if (!$dateTime instanceof DateTimeInterface) {
        return null;
    }

    return (float) $dateTime->format('U.u');
}

function extractCaptureSecond(object $structured): ?int
{
    $temporal = $structured->locationTime->temporal;
    $dateTime = $temporal->original
        ?? $temporal->create
        ?? $structured->locationTime->capture->dateTime;

    if (!$dateTime instanceof DateTimeInterface) {
        return null;
    }

    return $dateTime->getTimestamp();
}

function hasQuickTimeLivePhotoMarker(Metadata $metadata): bool
{
    if (!$metadata->quickTime instanceof QuickTimeMeta) {
        return false;
    }

    return ($metadata->quickTime->boolValue(QuickTimeMeta::STILL_IMAGE_TIME_KEY) ?? false)
        || ($metadata->quickTime->boolValue(QuickTimeMeta::HAS_LIVE_PHOTO_INFO_KEY) ?? false);
}

function extractVideoDurationSeconds(Metadata $metadata): ?float
{
    $duration = $metadata->quickTime?->floatValue('com.apple.quicktime.duration');

    if ($duration === null) {
        return null;
    }

    $timeScale = $metadata->quickTime?->intValue('TimeScale');

    if (($timeScale !== null) && ($timeScale > 0) && ($duration > $timeScale)) {
        return $duration / $timeScale;
    }

    return $duration;
}

/**
 * @param list<AssetRecord> $stills
 * @param list<AssetRecord> $videos
 *
 * @return array{array<int, list<int>>, array<int, list<int>>}
 */
function buildCandidateMaps(array $stills, array $videos, float $windowSeconds, float $gpsMeters, float $videoMax): array
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

    /** @var array<string, array<int, list<int>>> $stillBuckets */
    $stillBuckets = [];

    foreach ($videos as $videoIndex => $video) {
        if ($video->captureTimestamp === null) {
            continue;
        }

        $second = $video->captureSecond;

        if ($second === null) {
            continue;
        }

        $videoBuckets[$video->deviceKey()][$second][] = $videoIndex;
    }

    foreach ($stills as $stillIndex => $still) {
        if ($still->captureTimestamp === null) {
            continue;
        }

        $second = $still->captureSecond;

        if ($second === null) {
            continue;
        }

        $stillBuckets[$still->deviceKey()][$second][] = $stillIndex;
    }

    foreach ($stills as $stillIndex => $still) {
        if ($still->captureTimestamp === null) {
            continue;
        }

        $deviceKey = $still->deviceKey();
        $center    = $still->captureSecond;

        if ($center === null) {
            continue;
        }

        /** @var array<int, true> $candidateVideoIndexSet */
        $candidateVideoIndexSet = [];

        foreach ([$center, $center - 1, $center + 1] as $second) {
            foreach ($videoBuckets[$deviceKey][$second] ?? [] as $videoIndex) {
                $candidateVideoIndexSet[$videoIndex] = true;
            }
        }

        foreach (array_keys($candidateVideoIndexSet) as $videoIndex) {
            $video = $videos[$videoIndex];

            if (!assetsMatchHeuristic($still, $video, $windowSeconds, $gpsMeters, $videoMax)) {
                continue;
            }

            if ($still->captureSecond === $video->captureSecond) {
                $stillCandidateTiers[$stillIndex]['exact'][] = $videoIndex;
                $videoCandidateTiers[$videoIndex]['exact'][] = $stillIndex;

                continue;
            }

            $stillCandidateTiers[$stillIndex]['fallback'][] = $videoIndex;
            $videoCandidateTiers[$videoIndex]['fallback'][] = $stillIndex;
        }
    }

    foreach ($stillCandidateTiers as $stillIndex => $tiers) {
        $stillCandidates[$stillIndex] = choosePreferredCandidates($tiers);
    }

    foreach ($videoCandidateTiers as $videoIndex => $tiers) {
        $videoCandidates[$videoIndex] = choosePreferredCandidates($tiers);
    }

    return [$stillCandidates, $videoCandidates];
}

function assetsMatchHeuristic(AssetRecord $still, AssetRecord $video, float $windowSeconds, float $gpsMeters, float $videoMax): bool
{
    if (($still->captureTimestamp === null) || ($video->captureTimestamp === null)) {
        return false;
    }

    if (($still->captureSecond === null) || ($video->captureSecond === null)) {
        return false;
    }

    if (abs($still->captureSecond - $video->captureSecond) > (int) ceil($windowSeconds)) {
        return false;
    }

    if (!$still->hasStillMarker || !$video->hasVideoMarker) {
        return false;
    }

    if (($video->videoDurationSeconds === null) || ($video->videoDurationSeconds > $videoMax)) {
        return false;
    }

    if (($still->contentIdentifier === null) || ($video->contentIdentifier === null)) {
        return false;
    }

    if ($still->contentIdentifier === $video->contentIdentifier) {
        return false;
    }

    if ($still->deviceKey() !== $video->deviceKey()) {
        return false;
    }

    $distanceMeters = distanceMeters($still->latitude, $still->longitude, $video->latitude, $video->longitude);

    if ($distanceMeters === null) {
        return false;
    }

    return $distanceMeters <= $gpsMeters;
}

/**
 * @param array{exact?: list<int>, fallback?: list<int>} $tiers
 *
 * @return list<int>
 */
function choosePreferredCandidates(array $tiers): array
{
    $exact = $tiers['exact'] ?? [];

    if ($exact !== []) {
        return $exact;
    }

    return $tiers['fallback'] ?? [];
}

/**
 * @param list<AssetRecord>      $stills
 * @param list<AssetRecord>      $videos
 * @param array<int, list<int>>  $stillCandidates
 * @param array<int, list<int>>  $videoCandidates
 *
 * @return list<ConflictPair>
 */
function resolveUniqueConflictPairs(array $stills, array $videos, array $stillCandidates, array $videoCandidates): array
{
    /** @var list<ConflictPair> $pairs */
    $pairs = [];

    foreach ($stillCandidates as $stillIndex => $videoIndexes) {
        if (count($videoIndexes) !== 1) {
            continue;
        }

        $videoIndex = $videoIndexes[0];

        if (!array_key_exists($videoIndex, $videoCandidates) || (count($videoCandidates[$videoIndex]) !== 1)) {
            continue;
        }

        if ($videoCandidates[$videoIndex][0] !== $stillIndex) {
            continue;
        }

        $still    = $stills[$stillIndex];
        $video    = $videos[$videoIndex];
        $distance = distanceMeters($still->latitude, $still->longitude, $video->latitude, $video->longitude);

        $pairs[] = new ConflictPair(
            still: $still,
            video: $video,
            timeDifferenceSeconds: abs($still->captureTimestamp - $video->captureTimestamp),
            distanceMeters: $distance,
        );
    }

    return $pairs;
}

/**
 * @param list<AssetRecord>   $candidateStills
 * @param list<AssetRecord>   $candidateVideos
 * @param list<ConflictPair>  $conflictPairs
 */
function printSummary(
    string $root,
    int $scannedFiles,
    int $readErrors,
    array $candidateStills,
    array $candidateVideos,
    array $conflictPairs,
    int $limit,
): void {
    printf("Root: %s\n", $root);
    printf("Scanned media files: %d\n", $scannedFiles);
    printf("Metadata read errors: %d\n", $readErrors);
    printf("Candidate still assets: %d\n", count($candidateStills));
    printf("Candidate video assets: %d\n", count($candidateVideos));
    printf("Unique 1:1 conflict pairs: %d\n", count($conflictPairs));

    if ($conflictPairs === []) {
        return;
    }

    printf("\nExamples (up to %d):\n", $limit);

    foreach (array_slice($conflictPairs, 0, $limit) as $index => $pair) {
        printf(
            "\n%d.\n  still: %s\n  video: %s\n  still content id: %s\n  video content id: %s\n  time delta: %.3f s\n  gps delta: %s m\n",
            $index + 1,
            $pair->still->pathname,
            $pair->video->pathname,
            $pair->still->contentIdentifier ?? '-',
            $pair->video->contentIdentifier ?? '-',
            $pair->timeDifferenceSeconds,
            $pair->distanceMeters !== null ? number_format($pair->distanceMeters, 2, '.', '') : '-',
        );
    }
}

function normalizeNullable(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $trimmed = trim($value);

    return $trimmed !== '' ? $trimmed : null;
}

function normalizeString(?string $value): string
{
    return strtolower(trim($value ?? ''));
}

function distanceMeters(?float $latA, ?float $lonA, ?float $latB, ?float $lonB): ?float
{
    if (($latA === null) || ($lonA === null) || ($latB === null) || ($lonB === null)) {
        return null;
    }

    $earthRadiusMeters = 6371000.0;
    $latARadians       = deg2rad($latA);
    $latBRadians       = deg2rad($latB);
    $deltaLat          = deg2rad($latB - $latA);
    $deltaLon          = deg2rad($lonB - $lonA);

    $a = sin($deltaLat / 2.0) ** 2
        + cos($latARadians) * cos($latBRadians) * (sin($deltaLon / 2.0) ** 2);
    $c = 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));

    return $earthRadiusMeters * $c;
}

exit(main($argv));
