<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use function array_keys;

/**
 * Represents the set of detected Live Photo companion pathnames.
 *
 * The pipeline used to pass companion detections around as `array<string, true>`
 * maps. This value object keeps the set semantics explicit at the service
 * boundary while still offering cheap pathname lookups and merges.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class CompanionPathSet
{
    /**
     * @var array<string, true>
     */
    private array $paths = [];

    /**
     * Adds a companion pathname to the set.
     *
     * @param string $pathname Absolute companion pathname
     */
    public function add(string $pathname): void
    {
        $this->paths[$pathname] = true;
    }

    /**
     * Merges another detected companion set into this one.
     *
     * @param CompanionPathSet $other Additional companion paths to include
     */
    public function merge(self $other): void
    {
        foreach ($other->toPathList() as $pathname) {
            $this->add($pathname);
        }
    }

    /**
     * Returns whether the set contains the given pathname.
     *
     * @param string $pathname Absolute companion pathname
     *
     * @return bool True when the path is part of the detected companion set
     */
    public function contains(string $pathname): bool
    {
        return isset($this->paths[$pathname]);
    }

    /**
     * Returns whether the set is empty.
     *
     * @return bool True when no companion paths were detected
     */
    public function isEmpty(): bool
    {
        return $this->paths === [];
    }

    /**
     * Returns the detected companion pathnames as a flat list.
     *
     * @return list<string> Absolute companion pathnames
     */
    public function toPathList(): array
    {
        /** @var list<string> $paths */
        $paths = array_keys($this->paths);

        return $paths;
    }
}
