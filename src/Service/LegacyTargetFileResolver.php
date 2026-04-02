<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Model\TargetFileResult;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use SplFileInfo;
use Throwable;

/**
 * Resolves target file information for the legacy duplicate pipeline.
 *
 * This worker bridges rename-strategy filename generation with the legacy path
 * resolver and normalizes strategy failures into `TargetFileResult` values. It
 * keeps `DuplicateDetectionService` free from the low-level details of turning
 * `null` into a skipped result and unwrapping nested metadata exceptions into a
 * single operator-facing error message.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class LegacyTargetFileResolver
{
    /**
     * @param LegacyTargetPathResolver $targetPathResolver Resolves the final absolute target pathname once a filename was generated.
     */
    public function __construct(private LegacyTargetPathResolver $targetPathResolver)
    {
    }

    /**
     * Resolves a target file result for one source file and legacy rename strategy.
     *
     * @param string                  $sourceDirectory Absolute source root used as legacy destination base.
     * @param SplFileInfo             $sourceFileInfo  Source file that should be renamed.
     * @param RenameStrategyInterface $renameStrategy  Strategy responsible for generating the target filename.
     */
    public function resolve(
        string $sourceDirectory,
        SplFileInfo $sourceFileInfo,
        RenameStrategyInterface $renameStrategy,
    ): TargetFileResult {
        try {
            $targetFilename = $renameStrategy->generateFilename($sourceFileInfo);

            if ($targetFilename !== null) {
                return TargetFileResult::success(
                    new SplFileInfo(
                        $this->targetPathResolver->resolve(
                            $sourceDirectory,
                            $sourceFileInfo,
                            $targetFilename,
                        )
                    )
                );
            }

            return TargetFileResult::skipped('no capture date');
        } catch (TargetFilenameException $exception) {
            // Extract the root cause message from the exception chain to avoid
            // double-wrapped "Unable to read..." prefixes in the output.
            $rootCause = $exception;

            while ($rootCause->getPrevious() instanceof Throwable) {
                $rootCause = $rootCause->getPrevious();
            }

            return TargetFileResult::error($rootCause->getMessage());
        }
    }
}
