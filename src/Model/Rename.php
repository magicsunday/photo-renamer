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
 * Represents a single planned rename operation, mapping one source file to its
 * computed target path. The target may be updated during the pipeline when the
 * safe-rename logic resolves naming collisions.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class Rename
{
    /**
     * @param SplFileInfo $source Original file on disk (immutable throughout the pipeline)
     * @param SplFileInfo $target Computed destination path (may be replaced by collision resolution)
     */
    public function __construct(private readonly SplFileInfo $source, private SplFileInfo $target)
    {
    }

    /**
     * Returns the original source file that will be renamed or copied.
     */
    public function getSource(): SplFileInfo
    {
        return $this->source;
    }

    /**
     * Returns the currently assigned target path for this rename operation.
     */
    public function getTarget(): SplFileInfo
    {
        return $this->target;
    }

    /**
     * Replaces the target path, typically when the file system service detects
     * a naming collision and assigns a new duplicate-suffixed filename.
     *
     * @param SplFileInfo $target New target path to use
     *
     * @return self Fluent interface
     */
    public function setTarget(SplFileInfo $target): self
    {
        $this->target = $target;

        return $this;
    }
}
