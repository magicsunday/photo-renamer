<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Metadata;

use DateTimeInterface;

/**
 * Immutable result of capture-timestamp extraction from structured metadata.
 *
 * MetadataExtractor resolves three closely related facts in one pass: the
 * chosen capture timestamp, whether it comes from fallback metadata, and
 * whether the timestamp remains timezone-ambiguous. Keeping those values in a
 * dedicated DTO makes the local metadata contract explicit without widening it
 * into a broader cross-module abstraction.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class CaptureTimestampExtraction
{
    /**
     * @param DateTimeInterface|null $captureDateTime     Resolved capture timestamp, if any
     * @param bool                   $isFallback          Whether the timestamp came from fallback metadata fields
     * @param bool                   $isAmbiguousTimezone Whether the resolved timestamp still lacks trustworthy timezone information
     */
    public function __construct(
        public ?DateTimeInterface $captureDateTime,
        public bool $isFallback,
        public bool $isAmbiguousTimezone,
    ) {
    }
}
