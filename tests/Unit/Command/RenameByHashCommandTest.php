<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Command;

use MagicSunday\Renamer\Command\RenameByHashCommand;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\Filesystem\FileCollector;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\ContentHashStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\InheritFilenameStrategy;
use MagicSunday\Renamer\Test\Fixtures\RuntimeExecutorFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Verifies the RenameByHashCommand, which groups files by content hash and
 * identifies true duplicates. Unlike the EXIF command, this command uses
 * InheritFilenameStrategy (filename unchanged) and ContentHashStrategy
 * (group by xxHash128).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversNothing]
final class RenameByHashCommandTest extends TestCase
{
    /**
     * Verifies that the command registers under the name "rename:hash".
     */
    #[Test]
    public function configureExposesHashCommandWithAlias(): void
    {
        $command = new RenameByHashCommand(
            new FileCollector(new SafeRegex()),
            RuntimeExecutorFactory::createLegacy(),
            self::createStub(DuplicateDetectionServiceInterface::class),
            new SafeRegex(),
            new Filesystem(),
            new InheritFilenameStrategy(),
            new ContentHashStrategy(new SafeHashCalculator()),
        );

        self::assertSame('rename:hash', $command->getName());
    }

    /**
     * Verifies that execute() wires the correct strategies (InheritFilenameStrategy
     * for renaming, ContentHashStrategy for grouping) and preserves preview mode
     * through the common command workflow.
     *
     * The mock expectations ensure that groupFilesByDuplicateIdentifier() receives
     * the hash-based strategy instances and that the source directory is normalised.
     */
    #[Test]
    public function executeConfiguresServicesWithHashStrategies(): void
    {
        $sourceDir = sys_get_temp_dir() . '/renamer-test-hash-source-' . uniqid();

        mkdir($sourceDir);

        try {
            /** @var DuplicateDetectionServiceInterface&MockObject $duplicateDetectionService */
            $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

            $duplicateCollection = new FileDuplicateCollection();

            $duplicateDetectionService
                ->expects(self::once())
                ->method('groupFilesByDuplicateIdentifier')
                ->with(
                    self::isInstanceOf(RecursiveIteratorIterator::class),
                    self::callback(static fn ($strategy): bool => $strategy instanceof InheritFilenameStrategy),
                    self::callback(static fn ($strategy): bool => $strategy instanceof ContentHashStrategy),
                    $sourceDir,
                )
                ->willReturn($duplicateCollection);

            $duplicateDetectionService
                ->expects(self::once())
                ->method('createDuplicateFilenames')
                ->with(
                    self::identicalTo($duplicateCollection),
                    $sourceDir,
                    false,
                )
                ->willReturn($duplicateCollection);

            $command = new RenameByHashCommand(
                new FileCollector(new SafeRegex()),
                RuntimeExecutorFactory::createLegacy(),
                $duplicateDetectionService,
                new SafeRegex(),
                new Filesystem(),
                new InheritFilenameStrategy(),
                new ContentHashStrategy(new SafeHashCalculator()),
            );

            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source' => $sourceDir,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
        } finally {
            rmdir($sourceDir);
        }
    }
}
