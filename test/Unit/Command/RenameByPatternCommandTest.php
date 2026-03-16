<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

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
            self::createStub(FileSystemServiceInterface::class),
            self::createStub(DuplicateDetectionServiceInterface::class),
            new SafeRegex(),
        );

        self::assertSame('pattern', $command->getName());
        self::assertContains('rename:pattern', $command->getAliases());
    }
}
