<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Command;

use MagicSunday\Renamer\Command\RenameByPatternCommand;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\SafeRegex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RenameByPatternCommand::class)]
final class RenameByPatternCommandTest extends TestCase
{
    #[Test]
    public function configureExposesPatternCommandWithAlias(): void
    {
        $command = new RenameByPatternCommand(
            $this->createMock(FileSystemServiceInterface::class),
            $this->createMock(DuplicateDetectionServiceInterface::class),
            new SafeRegex(),
        );

        self::assertSame('pattern', $command->getName());
        self::assertContains('rename:pattern', $command->getAliases());
    }
}
