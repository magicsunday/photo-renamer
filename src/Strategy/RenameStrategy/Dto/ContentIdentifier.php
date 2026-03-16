<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

use function strtolower;
use function trim;

final readonly class ContentIdentifier
{
    private string $value;

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
