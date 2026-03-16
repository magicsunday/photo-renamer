<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\DatePattern;

/**
 * Pairs a user-supplied date pattern template with its compiled PCRE regex.
 * The template preserves the original placeholder syntax for display purposes,
 * while the regex is used for actual filename matching.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class PatternExpression
{
    /**
     * @param string $template Original template string with {placeholder} tokens
     * @param string $regex    Compiled PCRE regex derived from the template
     */
    public function __construct(
        private string $template,
        private string $regex,
    ) {
    }

    /**
     * Compiles a template string into a PatternExpression by replacing all
     * recognized date placeholders with their regex capture groups.
     *
     * @param string                      $template      Template with {Y}, {m}, {d}, etc. tokens
     * @param DatePlaceholderExpressionMap $expressionMap Placeholder-to-regex mapping
     *
     * @return self Compiled expression
     */
    public static function fromTemplate(string $template, DatePlaceholderExpressionMap $expressionMap): self
    {
        $regex = $expressionMap->replacePlaceholders($template);

        return new self($template, $regex);
    }

    /**
     * Returns the original template string with {placeholder} tokens intact.
     */
    public function getTemplate(): string
    {
        return $this->template;
    }

    /**
     * Returns the PCRE regex compiled from the template by replacing placeholders
     * with their corresponding capture groups.
     */
    public function getRegex(): string
    {
        return $this->regex;
    }
}
