<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model;

/**
 * Immutable configuration for clickable file links in terminal output.
 * Explicit values are resolved at the command boundary. Keeping environment
 * reads out of this model makes rendering deterministic across repeated runs.
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
