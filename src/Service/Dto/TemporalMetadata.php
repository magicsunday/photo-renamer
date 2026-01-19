<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dto;

use DateTimeInterface;

final class TemporalMetadata
{
    public function __construct(
        private readonly ?DateTimeInterface $captureDateTime,
        private readonly ?string $livePhotoId,
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
