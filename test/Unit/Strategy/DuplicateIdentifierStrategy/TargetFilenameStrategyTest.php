<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\DuplicateIdentifierStrategy;

use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\TargetFilenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(TargetFilenameStrategy::class)]
class TargetFilenameStrategyTest extends TestCase
{
    private TargetFilenameStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new TargetFilenameStrategy();
    }

    #[Test]
    public function generateIdentifierReturnsTargetFilename(): void
    {
        $result = $this->strategy
            ->generateIdentifier(
                new SplFileInfo('/path/to/file/source.txt'),
                new SplFileInfo('/path/to/file/target.txt')
            );

        self::assertSame(
            'target.txt',
            $result
        );
    }
}
