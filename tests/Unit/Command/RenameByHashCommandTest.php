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
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\Collection\AbstractCollection;
use MagicSunday\Renamer\Model\Collection\FileDuplicateCollection;
use MagicSunday\Renamer\Model\OutputEntryTag;
use MagicSunday\Renamer\Model\RenameOptions;
use MagicSunday\Renamer\Model\RenameResult;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\ContentHashStrategy;
use MagicSunday\Renamer\Strategy\RenameStrategy\InheritFilenameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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
#[CoversClass(RenameByHashCommand::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(AbstractCollection::class)]
#[UsesClass(RenameOptions::class)]
#[UsesClass(RenameResult::class)]
#[UsesClass(OutputEntryTag::class)]
#[UsesClass(ContentHashStrategy::class)]
final class RenameByHashCommandTest extends TestCase
{
    /**
     * Verifies that the command registers under the name "rename:hash".
     */
    #[Test]
    public function configureExposesHashCommandWithAlias(): void
    {
        $command = new RenameByHashCommand(
            self::createStub(FileSystemServiceInterface::class),
            self::createStub(DuplicateDetectionServiceInterface::class),
            new SafeRegex(),
            new SafeHashCalculator(),
        );

        self::assertSame('rename:hash', $command->getName());
    }

    /**
     * Verifies that execute() wires the correct strategies (InheritFilenameStrategy
     * for renaming, ContentHashStrategy for grouping) and propagates --dry-run
     * to RenameOptions.
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
            /** @var FileSystemServiceInterface&MockObject $fileSystemService */
            $fileSystemService = $this->createMock(FileSystemServiceInterface::class);

            /** @var DuplicateDetectionServiceInterface&MockObject $duplicateDetectionService */
            $duplicateDetectionService = $this->createMock(DuplicateDetectionServiceInterface::class);

            $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator([]));

            $fileSystemService
                ->expects(self::once())
                ->method('createFileIterator')
                ->with($sourceDir)
                ->willReturn($iterator);

            $duplicateCollection = new FileDuplicateCollection();

            $duplicateDetectionService
                ->expects(self::once())
                ->method('groupFilesByDuplicateIdentifier')
                ->with(
                    self::identicalTo($iterator),
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

            $fileSystemService
                ->expects(self::once())
                ->method('renameFiles')
                ->with(
                    self::identicalTo($duplicateCollection),
                    self::callback(static function (RenameOptions $options): bool {
                        self::assertTrue($options->dryRun);
                        self::assertFalse($options->listAll);

                        return true;
                    }),
                );

            $command = new RenameByHashCommand($fileSystemService, $duplicateDetectionService, new SafeRegex(), new SafeHashCalculator());

            $tester   = new CommandTester($command);
            $exitCode = $tester->execute([
                'source'    => $sourceDir,
                '--dry-run' => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
        } finally {
            rmdir($sourceDir);
        }
    }
}
