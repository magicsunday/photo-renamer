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
use MagicSunday\Renamer\Service\ValidationResult;

/**
 * Immutable result object from the AssetGroup pipeline run.
 * Contains the processed groups, pipeline context, and validation result.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class ExifRenamePipelineResult
{
    /**
     * @param AssetGroupCollection $groups           Fully processed asset groups with proposed names
     * @param PipelineContext      $context          Mutable state bag accumulated during all pipeline phases
     * @param ValidationResult     $validationResult Result of rename plan validation (duplicates, conflicts, swaps)
     */
    public function __construct(
        public AssetGroupCollection $groups,
        public PipelineContext $context,
        public ValidationResult $validationResult,
    ) {
    }
}
