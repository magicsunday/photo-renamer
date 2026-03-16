<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Exception;

use RuntimeException;

/**
 * Thrown when a preg_* function fails due to an invalid pattern, PCRE engine
 * error or internal regex compilation problem. Wraps the PHP warning message
 * into a typed exception for structured error handling.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class RegexExecutionException extends RuntimeException
{
}
