<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Exception;

/**
 * Thrown when the metadata reader library fails to extract EXIF/XMP/QuickTime
 * data from a media file (e.g. corrupted file, unsupported format, I/O error).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class ExifMetadataReadException extends TargetFilenameException
{
}
