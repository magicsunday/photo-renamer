<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Collection;

use ArrayAccess;
use Countable;
use Iterator;

/**
 * CollectionInterface.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 *
 * @template TKey
 * @template TValue
 *
 * @extends ArrayAccess<TKey, TValue>
 * @extends Iterator<TKey, TValue>
 */
interface CollectionInterface extends ArrayAccess, Countable, Iterator
{
}
