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
 * Base exception for errors that prevent generating a valid target filename.
 * Caught by the pipeline to skip the affected file and continue processing.
 * Subclassed by ExifMetadataReadException for metadata-specific failures.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class TargetFilenameException extends RuntimeException
{
}
