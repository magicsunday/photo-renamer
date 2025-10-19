<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Pattern;

use InvalidArgumentException;
use MagicSunday\Renamer\Model\Collection\AbstractCollection;
use RuntimeException;

use function array_map;
use function array_values;
use function preg_match_all;

/**
 * @extends AbstractCollection<int, PatternMatch>
 */
final class PatternMatchSet extends AbstractCollection
{
    public static function fromPattern(string $pattern): self
    {
        $matches = [];
        $result  = preg_match_all('/{(\\w+)}/', $pattern, $matches);

        if ($result === false) {
            throw new RuntimeException('Failed to extract placeholders from the given pattern.');
        }

        $matchSet = new self();

        foreach ($matches[0] as $index => $token) {
            $matchSet->append(new PatternMatch($token, $matches[1][$index] ?? ''));
        }

        return $matchSet;
    }

    public function append(object $value): void
    {
        if (!($value instanceof PatternMatch)) {
            throw new InvalidArgumentException('Value must be an instance of PatternMatch.');
        }

        parent::append($value);
    }

    public function get(int|string $key): ?PatternMatch
    {
        $value = parent::get($key);

        if ($value === null) {
            return null;
        }

        \assert($value instanceof PatternMatch);

        return $value;
    }

    public function set(int|string $key, object $value): void
    {
        if (!($value instanceof PatternMatch)) {
            throw new InvalidArgumentException('Value must be an instance of PatternMatch.');
        }

        parent::set($key, $value);
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
