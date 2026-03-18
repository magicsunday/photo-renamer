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
 * Represents a file that was skipped during the grouping phase because the
 * rename strategy could not produce a target filename. Captures both the
 * source file and a human-readable reason for diagnostic output.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class SkippedFile
{
    /**
     * @param SplFileInfo $file    The source file that was skipped
     * @param string      $reason  Human-readable explanation (e.g. "no capture date", "audio sample entry vendor must be 0")
     * @param bool        $isError Whether the skip was caused by a metadata read error (true) or simply missing metadata (false)
     */
    public function __construct(
        private SplFileInfo $file,
        private string $reason,
        private bool $isError = false,
    ) {
    }

    /**
     * Returns the source file that was skipped.
     */
    public function getFile(): SplFileInfo
    {
        return $this->file;
    }

    /**
     * Returns the human-readable skip reason.
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Returns whether the skip was caused by a metadata read error.
     */
    public function isError(): bool
    {
        return $this->isError;
    }
}
