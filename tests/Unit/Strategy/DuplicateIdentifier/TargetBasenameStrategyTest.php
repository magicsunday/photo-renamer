<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\DuplicateIdentifier;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies that TargetBasenameStrategy produces the extension-stripped target
 * basename as the duplicate-group identifier.
 *
 * This strategy is the default grouping key for EXIF date renaming: all files
 * whose target filename (minus extension) resolves to the same date string
 * land in one group. Correct identifier generation is essential for unified
 * grouping where HEIC, MOV, and JPG files with the same capture timestamp
 * share one FileDuplicate entry, enabling hash sub-grouping and Live Photo
 * companion detection within the group.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(TargetBasenameStrategy::class)]
#[UsesClass(FileHelper::class)]
final class TargetBasenameStrategyTest extends TestCase
{
    /**
     * Verifies that the identifier equals the target filename with its extension
     * removed when the target contains a date-based name.
     *
     * The source file is irrelevant for this strategy; only the computed target
     * matters. A correct identifier like "2025-01-01_00-02-20-016" ensures all
     * files with the same EXIF-derived timestamp are grouped together regardless
     * of their original source names or file extensions.
     */
    #[Test]
    public function itAlwaysReturnsTargetBasename(): void
    {
        $strategy = new TargetBasenameStrategy();

        $identifier = $strategy->generateIdentifier(
            new SplFileInfo('/tmp/source.jpg'),
            new SplFileInfo('/tmp/2025-01-01_00-02-20-016.jpg'),
        );

        self::assertSame('2025-01-01_00-02-20-016', $identifier);
    }

    /**
     * Verifies that the extension is always stripped, producing just the stem
     * of the target filename as the identifier.
     *
     * Without extension stripping, files with different extensions but the same
     * base name (e.g. "target.mov" and "target.heic") would land in separate
     * groups, breaking Live Photo pairing and hash sub-grouping.
     */
    #[Test]
    public function itStripsExtensionFromTargetBasename(): void
    {
        $strategy = new TargetBasenameStrategy();

        $identifier = $strategy->generateIdentifier(
            new SplFileInfo('/tmp/source.mov'),
            new SplFileInfo('/tmp/target.mov'),
        );

        self::assertSame('target', $identifier);
    }
}
