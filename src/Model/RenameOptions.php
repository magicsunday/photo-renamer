<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Model;

/**
 * Value object encapsulating options for file rename operations.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class RenameOptions
{
    public function __construct(
        public bool $dryRun = false,
        public bool $skipDuplicates = false,
        public bool $copyFiles = false,
        public bool $listAll = false,
        public ?string $sourceBaseDirectory = null,
        public ?string $targetBaseDirectory = null,
        public ?int $scannedFiles = null,
        public int $namingCollisions = 0,
    ) {
    }
}
