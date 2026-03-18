<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model;

use MagicSunday\Renamer\Model\SkippedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the value-object contract of SkippedFile, which captures a source
 * file and its skip reason during the grouping phase.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(SkippedFile::class)]
final class SkippedFileTest extends TestCase
{
    /**
     * Verifies that the file and reason passed to the constructor are accessible
     * through the corresponding getters.
     */
    #[Test]
    public function itExposesFileAndReason(): void
    {
        $file   = new SplFileInfo('/tmp/video.mov');
        $reason = 'no capture date';

        $skippedFile = new SkippedFile($file, $reason);

        self::assertSame($file, $skippedFile->getFile());
        self::assertSame($reason, $skippedFile->getReason());
    }
}
