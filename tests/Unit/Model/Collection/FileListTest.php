<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model\Collection;

use MagicSunday\Renamer\Model\Collection\FileList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the typed-list contract of FileList, which stores SplFileInfo instances
 * representing the source files belonging to a single duplicate group.
 *
 * FileList is used inside FileDuplicate to track which physical files map to the
 * same target basename. Correct append/get behaviour is critical for the grouping
 * and rename-assignment stages of the pipeline.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(FileList::class)]
final class FileListTest extends TestCase
{
    /**
     * Verifies that a SplFileInfo appended to the list can be retrieved by its
     * zero-based index and that asArray() returns the complete contents.
     *
     * This is the core storage guarantee. The duplicate detection service appends
     * source files as they are encountered during iteration and later reads them
     * back in order to build rename entries.
     */
    #[Test]
    public function itStoresSplFileInfoInstances(): void
    {
        $list = new FileList();
        $file = new SplFileInfo(__FILE__);

        $list->append($file);

        self::assertSame([$file], $list->asArray());
        self::assertSame($file, $list->get(0));
    }
}
