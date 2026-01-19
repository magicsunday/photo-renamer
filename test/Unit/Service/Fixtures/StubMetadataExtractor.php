<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Fixtures;

use MagicSunday\Renamer\Service\Dto\TemporalMetadata;
use MagicSunday\Renamer\Service\MetadataExtractor;
use SplFileInfo;
use Throwable;

use function array_key_exists;

final class StubMetadataExtractor extends MetadataExtractor
{
    /**
     * @var array<string, TemporalMetadata|Throwable|null>
     */
    private array $responses = [];

    public function __construct()
    {
    }

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
