<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model;

use MagicSunday\Renamer\Helper\FileHelper;

/**
 * Immutable configuration for clickable file links in terminal output.
 * Resolved from FILE_LINK_ROOT, FILE_LINK_BASE and FILE_LINK_PROTOCOL env vars.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LinkConfig
{
    public function __construct(
        public ?string $root,
        public ?string $base,
        public ?string $protocol,
    ) {
    }

    /**
     * Creates a LinkConfig from environment variables.
     * Returns a config with null fields when the env vars are not set.
     */
    public static function fromEnv(): self
    {
        return new self(
            FileHelper::env('FILE_LINK_ROOT'),
            FileHelper::env('FILE_LINK_BASE'),
            FileHelper::env('FILE_LINK_PROTOCOL'),
        );
    }

    /**
     * Returns whether clickable links are enabled (both root and base configured).
     */
    public function isEnabled(): bool
    {
        return ($this->root !== null) && ($this->base !== null);
    }
}
