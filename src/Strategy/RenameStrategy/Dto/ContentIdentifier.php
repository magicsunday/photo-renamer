<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

use function strtolower;
use function trim;

final class ContentIdentifier
{
    private readonly string $value;

    public function __construct(string $value)
    {
        $canonicalValue = trim($value);

        $this->value = strtolower($canonicalValue);
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
