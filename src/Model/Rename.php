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
 * The object holding info about the file renaming.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class Rename
{
    /**
     * @var SplFileInfo
     */
    private readonly SplFileInfo $source;

    /**
     * @var SplFileInfo
     */
    private SplFileInfo $target;

    /**
     * @param SplFileInfo $source
     * @param SplFileInfo $target
     */
    public function __construct(SplFileInfo $source, SplFileInfo $target)
    {
        $this->source = $source;
        $this->target = $target;
    }

    /**
     * @return SplFileInfo
     */
    public function getSource(): SplFileInfo
    {
        return $this->source;
    }

    /**
     * @return SplFileInfo
     */
    public function getTarget(): SplFileInfo
    {
        return $this->target;
    }

    /**
     * @param SplFileInfo $target
     *
     * @return Rename
     */
    public function setTarget(SplFileInfo $target): Rename
    {
        $this->target = $target;

        return $this;
    }
}
