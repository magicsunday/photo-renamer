<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Output;

/**
 * Describes how one target diff token relates to the source token stream.
 *
 * The diff highlighter passes these states across several internal methods, so
 * they are modeled as an enum instead of loose string literals.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
enum DiffTokenState: string
{
    /**
     * The token exists unchanged in the source string.
     */
    case Same = 'same';

    /**
     * The token exists but differs only by letter casing.
     */
    case CaseChanged = 'case-changed';

    /**
     * The token is new or materially changed in the target string.
     */
    case Changed = 'changed';
}
