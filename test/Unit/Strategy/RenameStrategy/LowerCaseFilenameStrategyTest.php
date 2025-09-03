<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\RenameStrategy;

use MagicSunday\Renamer\Strategy\RenameStrategy\LowerCaseFilenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(LowerCaseFilenameStrategy::class)]
class LowerCaseFilenameStrategyTest extends TestCase
{
    private LowerCaseFilenameStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new LowerCaseFilenameStrategy();
    }

    #[Test]
    public function itGeneratesLowercaseFilename(): void
    {
        $file   = new SplFileInfo('OriginalFileName.TXT');
        $result = $this->strategy->generateFilename($file);

        self::assertSame(
            'originalfilename.txt',
            $result
        );
    }
}