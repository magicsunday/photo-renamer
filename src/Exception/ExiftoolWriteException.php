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
 * Reports a failed metadata write with a bounded process diagnostic. Commands
 * catch this per file so one failed external process does not hide batch results.
 */
final class ExiftoolWriteException extends RuntimeException
{
}
