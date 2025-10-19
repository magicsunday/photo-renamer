<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\DuplicateIdentifierStrategy;

use MagicSunday\Renamer\Strategy\DuplicateIdentifierStrategy\LivePhotoContentIdentifierStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\ExifDateFilenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(LivePhotoContentIdentifierStrategy::class)]
class LivePhotoContentIdentifierStrategyTest extends TestCase
{
    #[Test]
    public function itUsesContentIdentifierWhenPresent(): void
    {
        /** @var ExifDateFilenameStrategy&MockObject $renameStrategy */
        $renameStrategy = $this->createMock(ExifDateFilenameStrategy::class);
        $renameStrategy
            ->expects(self::once())
            ->method('getLivePhotoContentIdentifier')
            ->willReturn('1234-uuid');

        $strategy = new LivePhotoContentIdentifierStrategy($renameStrategy);

        $identifier = $strategy->generateIdentifier(
            new SplFileInfo('/tmp/source.jpg'),
            new SplFileInfo('/tmp/target.jpg'),
        );

        self::assertSame('live-photo:1234-uuid', $identifier);
    }

    #[Test]
    public function itFallsBackToTargetFilenameWhenIdentifierMissing(): void
    {
        /** @var ExifDateFilenameStrategy&MockObject $renameStrategy */
        $renameStrategy = $this->createMock(ExifDateFilenameStrategy::class);
        $renameStrategy
            ->expects(self::once())
            ->method('getLivePhotoContentIdentifier')
            ->willReturn(null);

        $strategy = new LivePhotoContentIdentifierStrategy($renameStrategy);

        $identifier = $strategy->generateIdentifier(
            new SplFileInfo('/tmp/source.mov'),
            new SplFileInfo('/tmp/target.mov'),
        );

        self::assertSame('target.mov', $identifier);
    }
}
