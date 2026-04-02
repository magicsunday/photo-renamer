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
final class FileDuplicateCollection extends AbstractCollection
{
    /**
     * Stores a FileDuplicate group at the specified key.
     * The key is typically an identifier (hash or path) that defines
     * the "duplicateness" of the items in the group.
     *
     * @param int|string    $key   The duplicate identifier (cast to string).
     * @param FileDuplicate $value The FileDuplicate group to store.
     */
    #[Override]
    public function set(int|string $key, object $value): void
    {
        parent::set((string) $key, $value);
    }

    /**
     * Retrieves a FileDuplicate group by its identifier.
     *
     * @param int|string $key The identifier to look up (cast to string).
     *
     * @return FileDuplicate|null The FileDuplicate group if found, or null otherwise.
     */
    #[Override]
    public function get(int|string $key): ?FileDuplicate
    {
        return parent::get((string) $key);
    }
}
