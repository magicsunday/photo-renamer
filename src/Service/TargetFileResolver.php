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
 * Resolves target file results from rename-strategy filename generation.
 *
 * Both execution paths need the same conversion rules: generated filename
 * becomes a success target, `null` becomes the canonical skip result, and
 * nested metadata exceptions are flattened to one operator-facing message.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class TargetFileResolver
{
    /**
     * @param TargetPathResolver $targetPathResolver Resolves the final absolute target pathname once a filename was generated.
     */
    public function __construct(
        private TargetPathResolver $targetPathResolver = new TargetPathResolver(),
    ) {
    }

    /**
     * Resolves a target file result for one source file and rename strategy.
     *
     * @param string                  $sourceDirectory Absolute source root used as destination base
     * @param SplFileInfo             $sourceFileInfo  Source file that should be renamed
     * @param RenameStrategyInterface $renameStrategy  Strategy responsible for generating the target filename
     *
     * @return TargetFileResult Success target, skipped state, or flattened error result
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
                        ),
                    ),
                );
            }

            return TargetFileResult::skipped('no capture date');
        } catch (TargetFilenameException $exception) {
            $rootCause = $exception;

            while ($rootCause->getPrevious() instanceof Throwable) {
                $rootCause = $rootCause->getPrevious();
            }

            return TargetFileResult::error($rootCause->getMessage());
        }
    }
}
