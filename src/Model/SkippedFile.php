<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model;

use SplFileInfo;

/**
 * Represents a file that was excluded from the renaming process.
 *
 * This value object captures the source file along with the specific reason
 * why it was skipped (e.g., missing metadata, read errors, or user-defined
 * filters). It is used to provide detailed diagnostic feedback to the user
 * at the end of a run.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class SkippedFile
{
    /**
     * @param SplFileInfo $file    The source file that was skipped.
     * @param string      $reason  A human-readable explanation of why the file
     *                             was skipped (e.g., "no capture date found").
     * @param bool        $isError True if the skip was caused by an actual
     *                             processing error (e.g., corrupted file);
     *                             false if it was a normal skip (e.g., missing
     *                             optional metadata).
     */
    public function __construct(
        private SplFileInfo $file,
        private string $reason,
        private bool $isError = false,
    ) {
    }

    /**
     * Returns the file that was skipped.
     *
     * @return SplFileInfo The skipped file information.
     */
    public function getFile(): SplFileInfo
    {
        return $this->file;
    }

    /**
     * Returns the human-readable explanation for skipping the file.
     *
     * @return string The skip reason.
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Indicates whether the skip was due to an error.
     *
     * @return bool True if an error occurred during processing, false otherwise.
     */
    public function isError(): bool
    {
        return $this->isError;
    }
}
