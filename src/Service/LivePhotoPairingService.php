<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Service\Dto\LivePhotoContentIdentifierTarget;
use MagicSunday\Renamer\Service\Dto\LivePhotoContentIdentifierTargetMap;
use MagicSunday\Renamer\Service\Dto\LivePhotoExistingFilePathnameIndex;
use MagicSunday\Renamer\Service\Dto\LivePhotoPairing;
use MagicSunday\Renamer\Service\Dto\LivePhotoPairingCollection;
use RecursiveIteratorIterator;
use SplFileInfo;

use function is_callable;

/**
 * Service that pairs Apple Live Photo still/video files by content identifier.
 */
class LivePhotoPairingService
{
    /**
     * Finds additional files that belong to existing Live Photo groups.
     *
     * @param RecursiveIteratorIterator        $iterator                 Iterator traversing all source files.
     * @param FileDuplicateCollection          $fileDuplicateCollection  Collection generated during the first pass.
     * @param callable(SplFileInfo): ?string    $contentIdentifierResolver Callback resolving the Live Photo content identifier.
     * @param callable(): void|null             $onFileInspected          Optional callback invoked after each inspected file.
     *
     * @return LivePhotoPairingCollection
     */
    public function pairByContentIdentifier(
        RecursiveIteratorIterator $iterator,
        FileDuplicateCollection $fileDuplicateCollection,
        callable $contentIdentifierResolver,
        ?callable $onFileInspected = null,
    ): LivePhotoPairingCollection {
        $existingFilePathnames = new LivePhotoExistingFilePathnameIndex();
        $contentIdentifierTargets = new LivePhotoContentIdentifierTargetMap();

        foreach ($fileDuplicateCollection->asArray() as $duplicateIdentifier => $fileDuplicate) {
            if (!is_string($duplicateIdentifier)) {
                continue;
            }

            /** @var FileDuplicate $fileDuplicate */
            foreach ($fileDuplicate->getFiles() as $existingFileInfo) {
                $existingFilePathnames->remember($existingFileInfo);

                $contentIdentifier = $contentIdentifierResolver($existingFileInfo);

                if ($contentIdentifier === null || $contentIdentifier === '') {
                    continue;
                }

                $contentIdentifierTargets->remember(
                    $contentIdentifier,
                    $fileDuplicate->getTarget(),
                    $duplicateIdentifier,
                );
            }
        }

        $pairs = LivePhotoPairingCollection::empty();

        foreach ($iterator as $fileInfo) {
            if (!($fileInfo instanceof SplFileInfo)) {
                continue;
            }

            if (is_callable($onFileInspected)) {
                $onFileInspected();
            }

            if ($existingFilePathnames->contains($fileInfo)) {
                continue;
            }

            $contentIdentifier = $contentIdentifierResolver($fileInfo);

            if ($contentIdentifier === null || $contentIdentifier === '') {
                continue;
            }

            if (!$contentIdentifierTargets->has($contentIdentifier)) {
                continue;
            }

            $targetPrototype = $contentIdentifierTargets->get($contentIdentifier);
            $targetFile = $targetPrototype->getTarget();
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
                $contentIdentifier,
            ));
        }

        return $pairs;
    }
}
