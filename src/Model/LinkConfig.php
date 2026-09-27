<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model;

use function getenv;
use function is_string;

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
    /**
     * @param string|null $root     Local root path for link resolution (e.g. '/Volumes/photo')
     * @param string|null $base     Base URL for links (e.g. 'http://localhost:8080')
     * @param string|null $protocol Protocol for the links (e.g. 'vscode', 'file')
     */
    public function __construct(
        public ?string $root,
        public ?string $base,
        public ?string $protocol,
    ) {
    }

    /**
     * Creates a LinkConfig instance from environment variables.
     *
     * Reads FILE_LINK_ROOT, FILE_LINK_BASE, and FILE_LINK_PROTOCOL. Returns
     * a configuration with null fields if these variables are not defined.
     *
     * @return self The constructed configuration.
     */
    public static function fromEnv(): self
    {
        return new self(
            self::env('FILE_LINK_ROOT'),
            self::env('FILE_LINK_BASE'),
            self::env('FILE_LINK_PROTOCOL'),
        );
    }

    /**
     * Reads one environment variable, treating an unset or empty value as absent.
     *
     * Read here rather than through FileHelper::env(): the model layer sits below
     * the helpers (PathHelper depends on this class), so it must not call back up
     * into them.
     *
     * @param string $name The name of the environment variable to read.
     *
     * @return string|null The value, or null when it is unset or empty.
     */
    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && ($value !== '') ? $value : null;
    }

    /**
     * Returns whether clickable links are enabled.
     *
     * Links are considered enabled only when both the root path and
     * the base URL are properly configured.
     *
     * @return bool True if links are enabled.
     */
    public function isEnabled(): bool
    {
        return ($this->root !== null) && ($this->base !== null);
    }
}
