<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use RecursiveIteratorIterator;

/**
 * Interface for duplicate detection operations.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface DuplicateDetectionServiceInterface
{
    /**
     * Creates a collection of duplicates. Files with the same unique identifier are grouped together.
     *
     * @param RecursiveIteratorIterator $iterator
     * @param callable                  $targetFilenameProcessorCallable
     * @param callable                  $uniqueDuplicateIdentifierCallable
     *
     * @return FileDuplicateCollection
     */
    public function groupFilesByDuplicateIdentifier(
        RecursiveIteratorIterator $iterator,
        callable $targetFilenameProcessorCallable,
        callable $uniqueDuplicateIdentifierCallable,
    ): FileDuplicateCollection;
}
