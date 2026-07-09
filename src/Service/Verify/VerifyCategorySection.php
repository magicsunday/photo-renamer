<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Verify;

/**
 * Carries one verify report section from the formatter to the command boundary.
 *
 * The verify command needs a stable contract for label, files, and whether the
 * section contains multi-line detail entries. This DTO replaces the former
 * anonymous shape array at the service-to-command boundary.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class VerifyCategorySection
{
    /**
     * @param string       $label  Operator-facing section label
     * @param list<string> $files  Sorted file/detail lines rendered inside the section
     * @param bool         $detail Whether entries are multi-line detail blocks
     */
    public function __construct(
        public string $label,
        public array $files,
        public bool $detail,
    ) {
    }
}
