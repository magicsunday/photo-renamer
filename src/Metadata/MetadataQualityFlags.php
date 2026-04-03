<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Metadata;

/**
 * Carries the secondary metadata-quality flags that remain actionable once the
 * primary reliability decision has already been evaluated.
 *
 * This DTO intentionally stays tiny and explicit. It replaces array-based flag
 * contracts so callers can rely on named accessors instead of fragile string
 * keys when annotating pipeline and legacy flows.
 */
final readonly class MetadataQualityFlags
{
    /**
     * @param bool $hasFallbackDate      Whether the file still depends on a fallback date source.
     * @param bool $hasAmbiguousTimezone Whether the file still has unresolved timezone ambiguity.
     */
    public function __construct(
        private bool $hasFallbackDate,
        private bool $hasAmbiguousTimezone,
    ) {
    }

    /**
     * Returns whether the file still carries an actionable fallback-date issue.
     *
     * @return bool True when the fallback date should still be surfaced to callers.
     */
    public function hasFallbackDate(): bool
    {
        return $this->hasFallbackDate;
    }

    /**
     * Returns whether the file still carries an actionable timezone ambiguity.
     *
     * @return bool True when the timezone ambiguity should still be surfaced to callers.
     */
    public function hasAmbiguousTimezone(): bool
    {
        return $this->hasAmbiguousTimezone;
    }
}
