<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model\Collection;

use MagicSunday\Renamer\Model\FileDuplicate;
use Override;

/**
 * String-keyed collection of FileDuplicate groups. Keys are the duplicate identifiers
 * produced by a DuplicateIdentifierStrategy (e.g. target basename or content hash),
 * mapping each identifier to its FileDuplicate group of source files.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 *
 * @extends AbstractCollection<string, FileDuplicate>
 */
class FileDuplicateCollection extends AbstractCollection
{
    #[Override]
    public function append(object $value): void
    {
        parent::append($value);
    }

    #[Override]
    public function set(int|string $key, object $value): void
    {
        parent::set((string) $key, $value);
    }

    #[Override]
    public function get(int|string $key): ?FileDuplicate
    {
        return parent::get((string) $key);
    }
}
