<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Fixtures;

use MagicSunday\Renamer\Service\Pipeline\ExistingSubgroupNamePreserver;
use MagicSunday\Renamer\Service\Pipeline\FlatGroupNameResolver;
use MagicSunday\Renamer\Service\Pipeline\SubgroupNameResolver;
use MagicSunday\Renamer\Service\Pipeline\SubgroupPresenceDetector;
use MagicSunday\Renamer\Service\Pipeline\TargetNameResolver;

/**
 * Creates fully wired TargetNameResolver instances for manual tests.
 *
 * The production container autowires the resolver directly. Manual unit and
 * integration tests still construct the pipeline by hand in a few places, so
 * this fixture keeps those setups explicit without repeating the four local
 * naming collaborators in every test file.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class TargetNameResolverFactory
{
    /**
     * Creates the default semantic naming resolver used across test harnesses.
     */
    public static function create(): TargetNameResolver
    {
        return new TargetNameResolver(
            new FlatGroupNameResolver(),
            new SubgroupNameResolver(),
            new ExistingSubgroupNamePreserver(),
            new SubgroupPresenceDetector(),
        );
    }
}
