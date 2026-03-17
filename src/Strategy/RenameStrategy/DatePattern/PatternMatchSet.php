<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\DatePattern;

use MagicSunday\Renamer\Model\Collection\AbstractCollection;
use Override;
use RuntimeException;

use function array_map;
use function array_values;
use function preg_match_all;

/**
 * Ordered collection of PatternMatch instances extracted from a date pattern template.
 * Provides convenience methods to retrieve all placeholder names (for date format
 * reconstruction) or all tokens (for string replacement).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 *
 * @extends AbstractCollection<int, PatternMatch>
 */
final class PatternMatchSet extends AbstractCollection
{
    /**
     * Creates a set of pattern matches for every placeholder token in the given pattern string.
     *
     * @param string $pattern Pattern string containing placeholder tokens (e.g. `{placeholder}`)
     *
     * @return self Collection populated with {@see PatternMatch} instances for each discovered token
     */
    public static function fromPattern(string $pattern): self
    {
        $matches = [];
        $result  = preg_match_all('/\{(\w+)\}/', $pattern, $matches);

        if ($result === false) {
            throw new RuntimeException('Failed to extract placeholders from the given pattern.');
        }

        $matchSet = new self();

        foreach ($matches[1] as $placeholder) {
            $matchSet->append(new PatternMatch($placeholder));
        }

        return $matchSet;
    }

    #[Override]
    public function append(object $value): void
    {
        parent::append($value);
    }

    #[Override]
    public function get(int|string $key): ?PatternMatch
    {
        return parent::get((int) $key);
    }

    #[Override]
    public function set(int|string $key, object $value): void
    {
        parent::set((int) $key, $value);
    }

    /**
     * Returns all bare placeholder names (e.g. ["Y", "m", "d"]) in the order
     * they appear in the original pattern. Used to map regex capture groups
     * back to their date format characters.
     *
     * @return string[]
     */
    public function placeholders(): array
    {
        return array_values(
            array_map(
                static fn (PatternMatch $match): string => $match->getPlaceholder(),
                $this->asArray()
            )
        );
    }
}
