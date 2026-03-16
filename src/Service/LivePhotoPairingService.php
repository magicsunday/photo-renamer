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
use MagicSunday\Renamer\Service\Dto\LivePhotoBasenameTargetMap;
use MagicSunday\Renamer\Service\Dto\LivePhotoContentIdentifierTarget;
use MagicSunday\Renamer\Service\Dto\LivePhotoContentIdentifierTargetMap;
use MagicSunday\Renamer\Service\Dto\LivePhotoExistingFilePathnameIndex;
use MagicSunday\Renamer\Service\Dto\LivePhotoPairing;
use MagicSunday\Renamer\Service\Dto\LivePhotoPairingCollection;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function is_callable;
use function strtolower;
use function trim;

/**
 * Service that pairs Apple Live Photo still/video files by content identifier.
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
     *
     * @return LivePhotoPairingCollection
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

                $contentIdentifier = $contentIdentifierResolver($existingFileInfo);

                $normalizedContentIdentifier = $this->normalizeContentIdentifier($contentIdentifier);

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

            $contentIdentifier           = $contentIdentifierResolver($fileInfo);
            $normalizedContentIdentifier = $this->normalizeContentIdentifier($contentIdentifier);

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
            $targetBasename = $targetFile->getBasename('.' . $targetFile->getExtension());

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

    private function normalizeContentIdentifier(?string $contentIdentifier): ?string
    {
        if ($contentIdentifier === null) {
            return null;
        }

        $normalized = strtolower(trim($contentIdentifier));

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }
}
