<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;

use function array_map;
use function explode;
use function is_string;
use function trim;

/**
 * Resolves the configured canonical format priority list from environment
 * variables with a stable project-default fallback.
 *
 * This policy is shared by commands that need deterministic format preference
 * decisions without pulling in the full rename pipeline.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class FormatPriorityResolver
{
    /**
     * Returns the configured format priority in descending preference order.
     *
     * The environment variable `CANONICAL_FORMAT_PRIORITY` overrides the built-in
     * defaults. Values are returned as provided so callers can apply their own
     * normalization strategy.
     *
     * @return list<string> The configured extension priority list.
     */
    public static function resolve(): array
    {
        $envValue = FileHelper::env('CANONICAL_FORMAT_PRIORITY');

        if (is_string($envValue) && ($envValue !== '')) {
            return array_map(trim(...), explode(',', $envValue));
        }

        return Constants::DEFAULT_FORMAT_PRIORITY;
    }
}
