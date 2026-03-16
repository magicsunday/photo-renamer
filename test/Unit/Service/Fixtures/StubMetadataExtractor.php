<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Fixtures;

use MagicSunday\Renamer\Service\Dto\TemporalMetadata;
use MagicSunday\Renamer\Service\MetadataExtractorInterface;
use SplFileInfo;
use Throwable;

use function array_key_exists;

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
