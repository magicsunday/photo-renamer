<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Pattern;

final class PatternMatch
{
    public function __construct(
        private readonly string $token,
        private readonly string $placeholder
    ) {
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getPlaceholder(): string
    {
        return $this->placeholder;
    }
}
