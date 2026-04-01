<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Pipeline;

use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\PipelineContext;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\DuplicateIdentifierStrategyInterface;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use RecursiveIterator;
use RecursiveIteratorIterator;

/**
 * Contract for building capture groups from a file iterator.
 *
 * Steps 1-3 of the pipeline: collect files, extract metadata, group into
 * capture groups. Populates PipelineContext with quality flags and disk index.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface CaptureGroupBuilderInterface
{
    /**
     * Collects files from the iterator, extracts metadata, and groups them into
     * capture groups keyed by duplicate identifier. Populates the given context
     * with quality flags (fallback dates, ambiguous timezones) and disk index.
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner>    $iterator                    Iterator yielding candidate files
     * @param RenameStrategyInterface              $renameStrategy              Strategy to compute target filenames
     * @param DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy Strategy to generate grouping keys
     * @param PipelineContext                      $context                     Mutable state bag for pipeline phases
     *
     * @return AssetGroupCollection Collection of capture groups
     */
    public function build(
        RecursiveIteratorIterator $iterator,
        RenameStrategyInterface $renameStrategy,
        DuplicateIdentifierStrategyInterface $duplicateIdentifierStrategy,
        PipelineContext $context,
    ): AssetGroupCollection;
}
