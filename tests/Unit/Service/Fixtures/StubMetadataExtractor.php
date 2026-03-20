<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Fixtures;

use MagicSunday\Renamer\Metadata\MetadataExtractorInterface;
use MagicSunday\Renamer\Metadata\TemporalMetadata;
use Override;
use SplFileInfo;
use Throwable;

use function array_key_exists;

/**
 * In-memory stub of MetadataExtractorInterface for unit and integration tests.
 *
 * Allows pre-programming per-path responses: a TemporalMetadata for the happy path,
 * a Throwable for simulating extraction failures, or null (default) for files with
 * no metadata. This avoids the need for real image/video files with embedded EXIF
 * data in the majority of test scenarios.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class StubMetadataExtractor implements MetadataExtractorInterface
{
    /**
     * @var array<string, TemporalMetadata|Throwable|null>
     */
    private array $responses = [];

    public function withResponse(string $path, TemporalMetadata|Throwable|null $response): void
    {
        $this->responses[$path] = $response;
    }

    #[Override]
    public function extractTemporalMetadata(SplFileInfo $file): ?TemporalMetadata
    {
        $path = $file->getPathname();

        if (!array_key_exists($path, $this->responses)) {
            return null;
        }

        $response = $this->responses[$path];

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}
