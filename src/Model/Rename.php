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
 * Represents a single planned rename operation.
 *
 * This model maps a source file to its computed target path. While the source
 * remains immutable throughout the renaming pipeline, the target path may
 * be updated by components like the collision resolver to ensure a unique
 * filename in the destination directory.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class Rename
{
    /**
     * @param SplFileInfo $source The original file on disk. This path is
     *                            considered immutable for the duration of
     *                            the planning phase.
     * @param SplFileInfo $target The currently computed destination path.
     *                            This may be replaced or modified by the
     *                            collision resolution logic.
     */
    public function __construct(private readonly SplFileInfo $source, private SplFileInfo $target)
    {
    }

    /**
     * Returns the original source file for this rename operation.
     *
     * @return SplFileInfo The source file information.
     */
    public function getSource(): SplFileInfo
    {
        return $this->source;
    }

    /**
     * Returns the currently assigned target path for this rename operation.
     *
     * @return SplFileInfo The target file information.
     */
    public function getTarget(): SplFileInfo
    {
        return $this->target;
    }

    /**
     * Replaces the target path for this rename operation.
     *
     * This is typically called by the collision resolution logic when a
     * naming conflict is detected in the target directory, requiring a
     * modified filename (e.g., with a numeric suffix).
     *
     * @param SplFileInfo $target The new target path to be assigned.
     *
     * @return Rename Fluent interface for method chaining.
     */
    public function setTarget(SplFileInfo $target): self
    {
        $this->target = $target;

        return $this;
    }
}
