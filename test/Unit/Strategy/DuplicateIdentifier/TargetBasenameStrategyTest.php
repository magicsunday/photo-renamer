<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\DuplicateIdentifier;

use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetBasenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(TargetBasenameStrategy::class)]
class TargetBasenameStrategyTest extends TestCase
{
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
