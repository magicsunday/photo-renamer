<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\LivePhoto;

use DateTimeImmutable;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Service\LivePhoto\LivePhotoConflictDetector;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(LivePhotoConflictDetector::class)]
final class LivePhotoConflictDetectorTest extends TestCase
{
    #[Test]
    public function itIgnoresAlreadyPairedContentIdentifiersAndAvoidsCrossedMatches(): void
    {
        $detector = new LivePhotoConflictDetector(new MediaTypeClassifier());

        $files = [
            '/tmp/2024-09-10_22-05-39-439.jpg' => new SplFileInfo('/tmp/2024-09-10_22-05-39-439.jpg'),
            '/tmp/2024-09-10_22-05-39-439.mov' => new SplFileInfo('/tmp/2024-09-10_22-05-39-439.mov'),
            '/tmp/2024-09-10_22-05-40-515.jpg' => new SplFileInfo('/tmp/2024-09-10_22-05-40-515.jpg'),
            '/tmp/2024-09-10_22-05-40-515.mov' => new SplFileInfo('/tmp/2024-09-10_22-05-40-515.mov'),
        ];

        $metadata = [
            '/tmp/2024-09-10_22-05-39-439.jpg' => $this->createStillMetadata('2024-09-10T22:05:39.439+02:00', 'CID-A', 51.79375, 10.60537),
            '/tmp/2024-09-10_22-05-39-439.mov' => $this->createVideoMetadata('2024-09-10T22:05:39.000+02:00', 'CID-A', 51.79376, 10.60538),
            '/tmp/2024-09-10_22-05-40-515.jpg' => $this->createStillMetadata('2024-09-10T22:05:40.515+02:00', 'CID-B', 51.79380, 10.60540),
            '/tmp/2024-09-10_22-05-40-515.mov' => $this->createVideoMetadata('2024-09-10T22:05:40.000+02:00', 'CID-B', 51.79381, 10.60541),
        ];

        self::assertSame([], $detector->detectConflictFiles($files, $metadata));
    }

    #[Test]
    public function itMarksSameSecondConflictsAsCandidates(): void
    {
        $detector = new LivePhotoConflictDetector(new MediaTypeClassifier());

        $files = [
            '/tmp/2020-08-19_12-48-33-945.jpg' => new SplFileInfo('/tmp/2020-08-19_12-48-33-945.jpg'),
            '/tmp/2020-08-19_12-48-33-945.mov' => new SplFileInfo('/tmp/2020-08-19_12-48-33-945.mov'),
        ];

        $metadata = [
            '/tmp/2020-08-19_12-48-33-945.jpg' => $this->createStillMetadata('2020-08-19T12:48:33.945+02:00', 'PHOTO-ID', 51.79375, 10.60537),
            '/tmp/2020-08-19_12-48-33-945.mov' => $this->createVideoMetadata('2020-08-19T12:48:33.000+02:00', 'VIDEO-ID', 51.79376, 10.60538),
        ];

        self::assertSame(
            [
                '/tmp/2020-08-19_12-48-33-945.jpg' => true,
                '/tmp/2020-08-19_12-48-33-945.mov' => true,
            ],
            $detector->detectConflictFiles($files, $metadata),
        );
    }

    #[Test]
    public function itPrefersSameSecondMatchesOverFallbackMatches(): void
    {
        $detector = new LivePhotoConflictDetector(new MediaTypeClassifier());

        $files = [
            '/tmp/2020-08-19_12-48-33-945.jpg' => new SplFileInfo('/tmp/2020-08-19_12-48-33-945.jpg'),
            '/tmp/2020-08-19_12-48-33-945.mov' => new SplFileInfo('/tmp/2020-08-19_12-48-33-945.mov'),
            '/tmp/2020-08-19_12-48-34-618.mov' => new SplFileInfo('/tmp/2020-08-19_12-48-34-618.mov'),
        ];

        $metadata = [
            '/tmp/2020-08-19_12-48-33-945.jpg' => $this->createStillMetadata('2020-08-19T12:48:33.945+02:00', 'PHOTO-ID', 51.79375, 10.60537),
            '/tmp/2020-08-19_12-48-33-945.mov' => $this->createVideoMetadata('2020-08-19T12:48:33.000+02:00', 'VIDEO-ID-A', 51.79376, 10.60538),
            '/tmp/2020-08-19_12-48-34-618.mov' => $this->createVideoMetadata('2020-08-19T12:48:34.000+02:00', 'VIDEO-ID-B', 51.79376, 10.60538),
        ];

        self::assertSame(
            [
                '/tmp/2020-08-19_12-48-33-945.jpg' => true,
                '/tmp/2020-08-19_12-48-33-945.mov' => true,
            ],
            $detector->detectConflictFiles($files, $metadata),
        );
    }

    #[Test]
    public function itAllowsAdjacentSecondMatchesAsFallbackWhenUnique(): void
    {
        $detector = new LivePhotoConflictDetector(new MediaTypeClassifier());

        $files = [
            '/tmp/2020-08-21_09-40-26-221.jpg' => new SplFileInfo('/tmp/2020-08-21_09-40-26-221.jpg'),
            '/tmp/2020-08-21_09-40-26-221.mov' => new SplFileInfo('/tmp/2020-08-21_09-40-26-221.mov'),
        ];

        $metadata = [
            '/tmp/2020-08-21_09-40-26-221.jpg' => $this->createStillMetadata('2020-08-21T09:40:26.221+02:00', 'PHOTO-ID', 51.79375, 10.60537),
            '/tmp/2020-08-21_09-40-26-221.mov' => $this->createVideoMetadata('2020-08-21T09:40:25.000+02:00', 'VIDEO-ID', 51.79376, 10.60538),
        ];

        self::assertSame(
            [
                '/tmp/2020-08-21_09-40-26-221.jpg' => true,
                '/tmp/2020-08-21_09-40-26-221.mov' => true,
            ],
            $detector->detectConflictFiles($files, $metadata),
        );
    }

    private function createStillMetadata(
        string $captureDateTime,
        string $contentIdentifier,
        float $latitude,
        float $longitude,
    ): TemporalMetadata {
        return new TemporalMetadata(
            new DateTimeImmutable($captureDateTime),
            $contentIdentifier,
            false,
            false,
            8192,
            'Apple',
            'iPhone 8',
            '13.6.1',
            $latitude,
            $longitude,
        );
    }

    private function createVideoMetadata(
        string $captureDateTime,
        string $contentIdentifier,
        float $latitude,
        float $longitude,
    ): TemporalMetadata {
        return new TemporalMetadata(
            new DateTimeImmutable($captureDateTime),
            $contentIdentifier,
            false,
            false,
            null,
            'Apple',
            'iPhone 8',
            '13.6.1',
            $latitude,
            $longitude,
            2.6,
            true,
        );
    }
}
