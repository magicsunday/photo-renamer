<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Pattern;

final class PatternExpression
{
    public function __construct(
        private readonly string $template,
        private readonly string $regex
    ) {
    }

    public static function fromTemplate(string $template, DatePlaceholderExpressionMap $expressionMap): self
    {
        $regex = $expressionMap->replacePlaceholders($template);

        return new self($template, $regex);
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    public function getRegex(): string
    {
        return $this->regex;
    }
}
