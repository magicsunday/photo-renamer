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
 * Defines the role a file plays within an asset group.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
enum ItemRole: string
{
    /**
     * Primary representative of the asset group; determines the base filename.
     */
    case Canonical = 'canonical';

    /**
     * Same logical asset as canonical, receives a numbered suffix.
     */
    case Duplicate = 'duplicate';

    /**
     * Live Photo companion (video for a still canonical, or vice versa).
     */
    case Companion = 'companion';

    /**
     * Role could not be determined unambiguously; treated as duplicate for naming.
     */
    case Ambiguous = 'ambiguous';
}
