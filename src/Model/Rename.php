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
    public function __construct(private readonly SplFileInfo $source, private SplFileInfo $target)
    {
    }

    public function getSource(): SplFileInfo
    {
        return $this->source;
    }

    public function getTarget(): SplFileInfo
    {
        return $this->target;
    }

    public function setTarget(SplFileInfo $target): Rename
    {
        $this->target = $target;

        return $this;
    }
}
