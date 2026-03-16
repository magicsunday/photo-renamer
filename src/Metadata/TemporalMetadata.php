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

final readonly class TemporalMetadata
{
    public function __construct(
        private ?DateTimeInterface $captureDateTime,
        private ?string $livePhotoId,
    ) {
    }

    public function getCaptureDateTime(): ?DateTimeInterface
    {
        return $this->captureDateTime;
    }

    public function getLivePhotoId(): ?string
    {
        return $this->livePhotoId;
    }
}
