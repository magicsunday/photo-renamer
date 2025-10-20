<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\FileDuplicate;
use MagicSunday\Renamer\Service\Dto\LivePhotoPairing;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_key_exists;
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
     * @return list<LivePhotoPairing>
     */
    public function pairByContentIdentifier(
        RecursiveIteratorIterator $iterator,
        FileDuplicateCollection $fileDuplicateCollection,
        callable $contentIdentifierResolver,
        ?callable $onFileInspected = null,
    ): array {
        $existingFilePathnames = [];
        $contentIdentifierMap = [];

        /** @var FileDuplicate $fileDuplicate */
        foreach ($fileDuplicateCollection as $fileDuplicate) {
            foreach ($fileDuplicate->getFiles() as $existingFileInfo) {
                $existingFilePathnames[$existingFileInfo->getPathname()] = true;

                $contentIdentifier = $contentIdentifierResolver($existingFileInfo);

                if ($contentIdentifier === null || $contentIdentifier === '') {
                    continue;
                }

                if (array_key_exists($contentIdentifier, $contentIdentifierMap)) {
                    continue;
                }

                $contentIdentifierMap[$contentIdentifier] = $fileDuplicate->getTarget();
            }
        }

        $pairs = [];

        foreach ($iterator as $fileInfo) {
            if (!($fileInfo instanceof SplFileInfo)) {
                continue;
            }

            if (is_callable($onFileInspected)) {
                $onFileInspected();
            }

            if (isset($existingFilePathnames[$fileInfo->getPathname()])) {
                continue;
            }

            $contentIdentifier = $contentIdentifierResolver($fileInfo);

            if ($contentIdentifier === null || $contentIdentifier === '') {
                continue;
            }

            if (!array_key_exists($contentIdentifier, $contentIdentifierMap)) {
                continue;
            }

            $targetPrototype = $contentIdentifierMap[$contentIdentifier];
            $targetBasename = $targetPrototype->getBasename('.' . $targetPrototype->getExtension());

            $targetFileInfo = new SplFileInfo(
                $fileInfo->getPath()
                . DIRECTORY_SEPARATOR
                . $targetBasename
                . '.'
                . $fileInfo->getExtension(),
            );

            $duplicateIdentifier = $targetBasename . '.' . $fileInfo->getExtension();

            $pairs[] = new LivePhotoPairing(
                $fileInfo,
                $targetFileInfo,
                $duplicateIdentifier,
                $contentIdentifier,
            );
        }

        return $pairs;
    }
}
