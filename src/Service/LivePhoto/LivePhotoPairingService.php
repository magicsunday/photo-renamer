<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\LivePhoto;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function is_callable;

/**
 * Discovers companion files (typically MOV videos) that belong to existing Live Photo
 * groups but were not captured during the initial grouping pass. Matches companions
 * by Apple content identifier first, falling back to basename matching when the content
 * identifier is unavailable (e.g. for non-Apple cameras or stripped metadata).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class LivePhotoPairingService
{
    /**
     * Finds additional files that belong to existing Live Photo groups.
     *
     * @template TInner of RecursiveIterator
     *
     * @param RecursiveIteratorIterator<TInner> $iterator                  iterator traversing all source files
     * @param FileDuplicateCollection           $fileDuplicateCollection   collection generated during the first pass
     * @param callable(SplFileInfo): ?string    $contentIdentifierResolver callback resolving the Live Photo content identifier
     * @param callable(): void|null             $onFileInspected           optional callback invoked after each inspected file
     */
    public function pairByContentIdentifier(
        RecursiveIteratorIterator $iterator,
        FileDuplicateCollection $fileDuplicateCollection,
        callable $contentIdentifierResolver,
        ?callable $onFileInspected = null,
        bool $matchByContentIdentifier = true,
    ): LivePhotoPairingCollection {
        $existingFilePathnames    = new LivePhotoExistingFilePathnameIndex();
        $contentIdentifierTargets = new LivePhotoContentIdentifierTargetMap();
        $basenameTargets          = new LivePhotoBasenameTargetMap();

        foreach ($fileDuplicateCollection->asArray() as $duplicateIdentifier => $fileDuplicate) {
            foreach ($fileDuplicate->getFiles() as $existingFileInfo) {
                $existingFilePathnames->remember($existingFileInfo);

                $normalizedContentIdentifier = $contentIdentifierResolver($existingFileInfo);

                if ($matchByContentIdentifier && $normalizedContentIdentifier !== null) {
                    $contentIdentifierTargets->remember(
                        $normalizedContentIdentifier,
                        $fileDuplicate->getTarget(),
                        $duplicateIdentifier,
                    );
                }

                $basenameTargets->remember(
                    $existingFileInfo,
                    $fileDuplicate->getTarget(),
                    $duplicateIdentifier,
                );
            }
        }

        $pairs = LivePhotoPairingCollection::empty();

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            if (is_callable($onFileInspected)) {
                $onFileInspected();
            }

            if ($existingFilePathnames->contains($fileInfo)) {
                continue;
            }

            $normalizedContentIdentifier = $contentIdentifierResolver($fileInfo);

            $targetPrototype   = null;
            $pairingIdentifier = $normalizedContentIdentifier;

            if (
                $matchByContentIdentifier
                && $normalizedContentIdentifier !== null
                && $contentIdentifierTargets->has($normalizedContentIdentifier)
            ) {
                $targetPrototype = $contentIdentifierTargets->get($normalizedContentIdentifier);
            }

            if (!$targetPrototype instanceof LivePhotoContentIdentifierTarget) {
                $basenameTarget = $basenameTargets->match($fileInfo);

                if ($basenameTarget instanceof LivePhotoContentIdentifierTarget) {
                    $targetPrototype = $basenameTarget;
                    $basenameKey     = $basenameTargets->getBasenameKey($fileInfo);

                    if ($basenameKey !== null) {
                        $pairingIdentifier = 'basename:' . $basenameKey;
                    }
                }
            }

            if (!$targetPrototype instanceof LivePhotoContentIdentifierTarget) {
                continue;
            }

            $targetFile     = $targetPrototype->getTarget();
            $targetBasename = FileHelper::basenameWithoutExtension($targetFile);

            $targetFileInfo = new SplFileInfo(
                $fileInfo->getPath()
                . DIRECTORY_SEPARATOR
                . $targetBasename
                . '.'
                . $fileInfo->getExtension(),
            );

            $duplicateIdentifier = $targetPrototype->getDuplicateIdentifier();

            $pairs->add(new LivePhotoPairing(
                $fileInfo,
                $targetFileInfo,
                $duplicateIdentifier,
                $pairingIdentifier ?? $targetPrototype->getDuplicateIdentifier(),
            ));
        }

        return $pairs;
    }
}
