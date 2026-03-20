<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\LivePhoto;

use MagicSunday\Renamer\Metadata\TemporalMetadata;
use SplFileInfo;

/**
 * Detects likely still/video Live Photo pairs whose content identifiers conflict
 * even though the surrounding metadata strongly suggests they belong together.
 *
 * The detector is intentionally conservative: it never pairs assets, it only
 * returns pathnames that should be surfaced as manual review candidates [C].
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface LivePhotoConflictDetectorInterface
{
    /**
     * @param array<string, SplFileInfo>      $filesByPath    All scanned media files keyed by pathname
     * @param array<string, TemporalMetadata> $metadataByPath Extracted temporal metadata keyed by pathname
     *
     * @return array<string, true> Pathnames of files that should be marked as [C]
     */
    public function detectConflictFiles(array $filesByPath, array $metadataByPath): array;
}
