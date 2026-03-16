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

        foreach ($matches[0] as $index => $token) {
            $matchSet->append(new PatternMatch($token, $matches[1][$index] ?? ''));
        }

        return $matchSet;
    }

    /**
     * @param PatternMatch $value
     */
    #[Override]
    public function append(object $value): void
    {
        parent::append($value);
    }

    /**
     * @param int $key
     */
    #[Override]
    public function get(int|string $key): ?PatternMatch
    {
        return parent::get((int) $key);
    }

    /**
     * @param int          $key
     * @param PatternMatch $value
     */
    #[Override]
    public function set(int|string $key, object $value): void
    {
        parent::set((int) $key, $value);
    }

    /**
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

    /**
     * @return string[]
     */
    public function tokens(): array
    {
        return array_values(
            array_map(
                static fn (PatternMatch $match): string => $match->getToken(),
                $this->asArray()
            )
        );
    }
}
