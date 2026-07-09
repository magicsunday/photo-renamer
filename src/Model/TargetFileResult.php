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
 * Result of attempting to generate a target file path from a rename strategy.
 * Either carries a successful target file, or a skip reason with an error flag.
 * Replaces implicit side-effect communication via mutable state.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class TargetFileResult
{
    private function __construct(
        private ?SplFileInfo $targetFile,
        private ?string $skipReason,
        private bool $isError,
    ) {
    }

    /**
     * Creates a successful result carrying the computed target file.
     *
     * @param SplFileInfo $targetFile The computed target file path
     */
    public static function success(SplFileInfo $targetFile): self
    {
        return new self($targetFile, null, false);
    }

    /**
     * Creates a skipped result for files lacking usable metadata.
     *
     * @param string $reason Human-readable skip reason (e.g. "no capture date")
     */
    public static function skipped(string $reason): self
    {
        return new self(null, $reason, false);
    }

    /**
     * Creates an error result for files that caused a metadata read failure.
     *
     * @param string $reason Human-readable error description
     */
    public static function error(string $reason): self
    {
        return new self(null, $reason, true);
    }

    /**
     * @return SplFileInfo|null The computed target file, or null when skipped/errored.
     */
    public function getTargetFile(): ?SplFileInfo
    {
        return $this->targetFile;
    }

    /**
     * @return string|null The human-readable skip reason, or null on success.
     */
    public function getSkipReason(): ?string
    {
        return $this->skipReason;
    }

    /**
     * @return bool Whether the file was skipped (no target produced).
     */
    public function isSkipped(): bool
    {
        return !$this->targetFile instanceof SplFileInfo;
    }

    /**
     * @return bool Whether the skip was caused by a metadata read error.
     */
    public function isError(): bool
    {
        return $this->isError;
    }
}
