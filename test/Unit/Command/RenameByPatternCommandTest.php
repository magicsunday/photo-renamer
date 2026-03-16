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
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the RenameByPatternCommand configuration: command name registration
 * and argument/option definitions for regex-based renaming.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(RenameByPatternCommand::class)]
final class RenameByPatternCommandTest extends TestCase
{
    /**
     * Verifies that the command registers under the name "rename:pattern", which
     * is the user-facing CLI alias for regex-based filename transformation.
     */
    #[Test]
    public function configureExposesPatternCommandWithAlias(): void
    {
        $command = new RenameByPatternCommand(
            self::createStub(FileSystemServiceInterface::class),
            self::createStub(DuplicateDetectionServiceInterface::class),
            new SafeRegex(),
        );

        self::assertSame('rename:pattern', $command->getName());
    }
}
