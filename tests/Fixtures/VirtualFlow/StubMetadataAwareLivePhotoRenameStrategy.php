<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Fixtures\VirtualFlow;

use MagicSunday\Renamer\Metadata\TemporalMetadata;
use MagicSunday\Renamer\Strategy\RenameStrategy\LivePhotoAwareRenameStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\MetadataAwareRenameStrategyInterface;
use Override;
use SplFileInfo;

use function array_key_exists;
use function strtolower;

/**
 * In-memory rename strategy for virtual `rename:exif` flow tests.
 *
 * The real EXIF strategy couples filename generation, metadata inspection,
 * fallback detection, timezone ambiguity, and Live Photo identifiers. Virtual
 * flow tests need the same semantic surface without requiring real files or
 * embedded metadata. This stub keeps those signals programmable per pathname so
 * the pipeline can run end-to-end against deterministic fixtures.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class StubMetadataAwareLivePhotoRenameStrategy implements MetadataAwareRenameStrategyInterface, LivePhotoAwareRenameStrategyInterface
{
    /**
     * @var array<string, string|null>
     */
    private array $filenames = [];

    /**
     * @var array<string, TemporalMetadata|null>
     */
    private array $metadata = [];

    /**
     * @var array<string, bool>
     */
    private array $reliability = [];

    /**
     * Programs the generated filename, metadata payload, and reliability flag for one virtual file.
     *
     * The filename may be null to emulate the "no capture date" skip path. The
     * metadata payload is optional because some pipeline decisions only care about
     * the generated basename while others inspect fallback/timezone/content-ID data.
     *
     * @param string                $pathname            Absolute virtual pathname used as lookup key
     * @param string|null           $generatedFilename   Generated filename including extension, or null to force a skip
     * @param TemporalMetadata|null $temporalMetadata    Temporal metadata payload visible to the pipeline
     * @param bool                  $hasReliableDateTime Precomputed reliability flag returned by hasReliableDateTime()
     *
     * @return self Fluent instance for fixture setup
     */
    public function withFile(
        string $pathname,
        ?string $generatedFilename,
        ?TemporalMetadata $temporalMetadata,
        bool $hasReliableDateTime = true,
    ): self {
        $this->filenames[$pathname]   = $generatedFilename;
        $this->metadata[$pathname]    = $temporalMetadata;
        $this->reliability[$pathname] = $hasReliableDateTime;

        return $this;
    }

    /**
     * Returns the pre-programmed filename for the file or null to trigger the skip path.
     *
     * @param SplFileInfo $splFileInfo Virtual source file
     *
     * @return string|null Generated filename or null when the test wants a skipped file
     */
    #[Override]
    public function generateFilename(SplFileInfo $splFileInfo): ?string
    {
        return $this->filenames[$splFileInfo->getPathname()] ?? null;
    }

    /**
     * Returns whether the file uses the DateTime fallback flag exposed by its metadata payload.
     *
     * @param SplFileInfo $splFileInfo Virtual source file
     *
     * @return bool True when the virtual metadata marks the file as fallback-dated
     */
    #[Override]
    public function isFallbackDateTime(SplFileInfo $splFileInfo): bool
    {
        return $this->getTemporalMetadata($splFileInfo)?->isFallbackDateTime() ?? false;
    }

    /**
     * Returns whether the file has an ambiguous timezone according to its metadata payload.
     *
     * @param SplFileInfo $splFileInfo Virtual source file
     *
     * @return bool True when the virtual metadata marks the file as timezone-ambiguous
     */
    #[Override]
    public function isAmbiguousTimezone(SplFileInfo $splFileInfo): bool
    {
        return $this->getTemporalMetadata($splFileInfo)?->isAmbiguousTimezone() ?? false;
    }

    /**
     * Returns the pre-programmed reliability verdict for the file.
     *
     * Virtual flow tests sometimes need a reliability outcome that is simpler to
     * express directly than to derive indirectly from every metadata flag.
     *
     * @param SplFileInfo $splFileInfo Virtual source file
     *
     * @return bool True when the capture date should be treated as reliable
     */
    #[Override]
    public function hasReliableDateTime(SplFileInfo $splFileInfo): bool
    {
        return $this->reliability[$splFileInfo->getPathname()] ?? false;
    }

    /**
     * Returns the full temporal metadata payload for the file.
     *
     * @param SplFileInfo $splFileInfo Virtual source file
     *
     * @return TemporalMetadata|null Pre-programmed metadata payload
     */
    #[Override]
    public function getTemporalMetadata(SplFileInfo $splFileInfo): ?TemporalMetadata
    {
        $pathname = $splFileInfo->getPathname();

        if (!array_key_exists($pathname, $this->metadata)) {
            return null;
        }

        return $this->metadata[$pathname];
    }

    /**
     * Returns the normalized Live Photo content identifier visible to the pipeline.
     *
     * The production EXIF strategy normalizes the identifier before exposing it.
     * The virtual strategy mirrors that behavior so tests exercise the same
     * grouping and companion-detection semantics.
     *
     * @param SplFileInfo $splFileInfo Virtual source file
     *
     * @return string|null Lowercased content identifier, or null when absent
     */
    #[Override]
    public function getLivePhotoContentIdentifier(SplFileInfo $splFileInfo): ?string
    {
        $contentIdentifier = $this->getTemporalMetadata($splFileInfo)?->getLivePhotoId();

        return $contentIdentifier !== null ? strtolower($contentIdentifier) : null;
    }
}
