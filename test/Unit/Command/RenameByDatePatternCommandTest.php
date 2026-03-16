<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Command;

use MagicSunday\Renamer\Command\RenameByDatePatternCommand;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\SafeRegex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RenameByDatePatternCommand::class)]
final class RenameByDatePatternCommandTest extends TestCase
{
    #[Test]
    public function configureExposesPatternDateCommandWithAlias(): void
    {
        $command = new RenameByDatePatternCommand(
            self::createStub(FileSystemServiceInterface::class),
            self::createStub(DuplicateDetectionServiceInterface::class),
            new SafeRegex(),
        );

        self::assertSame('pattern:date', $command->getName());
        self::assertContains('rename:date-pattern', $command->getAliases());
    }
}
