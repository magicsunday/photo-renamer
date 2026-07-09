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
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\FileSystemServiceInterface;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetPathnameStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use ReflectionProperty;
use SplFileInfo;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Filesystem\Filesystem;

use function basename;
use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies the RenameByDatePatternCommand configuration: command name registration
 * and argument/option definitions.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(RenameByDatePatternCommand::class)]
final class RenameByDatePatternCommandTest extends TestCase
{
    /**
     * Verifies that the command registers under the name "rename:date", which is
     * the user-facing CLI alias for date-pattern-based renaming.
     */
    #[Test]
    public function configureExposesPatternDateCommandWithAlias(): void
    {
        $command = new RenameByDatePatternCommand(
            self::createStub(FileSystemServiceInterface::class),
            self::createStub(DuplicateDetectionServiceInterface::class),
            new SafeRegex(),
            new Filesystem(),
            new TargetPathnameStrategy(),
        );

        self::assertSame('rename:date', $command->getName());
    }

    /**
     * Verifies that single-file mode delegates to the exact base-command file
     * selection instead of scanning all date-pattern matches in the parent
     * directory.
     */
    #[Test]
    public function createFileIteratorUsesExactSingleFileSelection(): void
    {
        $filesystem = new Filesystem();
        $workspace  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'renamer-date-single-' . uniqid();
        $sourceFile = $workspace . DIRECTORY_SEPARATOR . '2024-01-01_10-00-00.jpg';

        mkdir($workspace);
        file_put_contents($sourceFile, 'selected');
        file_put_contents($workspace . DIRECTORY_SEPARATOR . '2024-01-02_10-00-00.jpg', 'other');

        try {
            $fileSystemService = $this->createMock(FileSystemServiceInterface::class);
            $fileSystemService
                ->expects(self::once())
                ->method('createFileIterator')
                ->with(
                    $workspace,
                    self::callback(static fn (RecursiveIterator $iterator): bool => self::assertIteratorBasenames($iterator, ['2024-01-01_10-00-00.jpg'])),
                )
                ->willReturn($this->emptyIterator());

            $command = $this->createCommand($fileSystemService);
            $this->configureCommandState($command, $workspace, $sourceFile, isSingleFile: true);

            $this->invokeCreateFileIterator($command);
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * Verifies that directory mode keeps applying the initialized date-pattern
     * regex as recursive file filter.
     */
    #[Test]
    public function createFileIteratorKeepsDatePatternFilterForDirectories(): void
    {
        $filesystem = new Filesystem();
        $workspace  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'renamer-date-dir-' . uniqid();

        mkdir($workspace);
        file_put_contents($workspace . DIRECTORY_SEPARATOR . '2024-01-01_10-00-00.jpg', 'match');
        file_put_contents($workspace . DIRECTORY_SEPARATOR . 'IMG_0001.jpg', 'skip');

        try {
            $fileSystemService = $this->createMock(FileSystemServiceInterface::class);
            $fileSystemService
                ->expects(self::once())
                ->method('createFileIterator')
                ->with(
                    $workspace,
                    self::callback(static fn (RecursiveIterator $iterator): bool => self::assertIteratorBasenames($iterator, ['2024-01-01_10-00-00.jpg'])),
                )
                ->willReturn($this->emptyIterator());

            $command = $this->createCommand($fileSystemService);
            $this->configureCommandState($command, $workspace, $workspace, isSingleFile: false);

            $this->invokeCreateFileIterator($command);
        } finally {
            $filesystem->remove($workspace);
        }
    }

    private function createCommand(?FileSystemServiceInterface $fileSystemService = null): RenameByDatePatternCommand
    {
        return new RenameByDatePatternCommand(
            $fileSystemService ?? self::createStub(FileSystemServiceInterface::class),
            self::createStub(DuplicateDetectionServiceInterface::class),
            new SafeRegex(),
            new Filesystem(),
            new TargetPathnameStrategy(),
        );
    }

    private function configureCommandState(
        RenameByDatePatternCommand $command,
        string $sourceDirectory,
        string $source,
        bool $isSingleFile,
    ): void {
        $this->setProperty($command, 'sourceDirectory', $sourceDirectory);
        $this->setProperty($command, 'isSingleFile', $isSingleFile);
        $this->setProperty($command, 'input', new ArrayInput(['source' => $source], $command->getDefinition()));
        $this->setProperty($command, 'patternRegex', '/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.jpg$/');
    }

    private function invokeCreateFileIterator(RenameByDatePatternCommand $command): void
    {
        $method = new ReflectionMethod($command, 'createFileIterator');
        $method->invoke($command);
    }

    private function setProperty(RenameByDatePatternCommand $command, string $property, mixed $value): void
    {
        $reflectionProperty = new ReflectionProperty($command, $property);
        $reflectionProperty->setValue($command, $value);
    }

    /**
     * @return RecursiveIteratorIterator<RecursiveArrayIterator<int, mixed>>
     */
    private function emptyIterator(): RecursiveIteratorIterator
    {
        /** @var array<int, mixed> $empty */
        $empty = [];

        return new RecursiveIteratorIterator(new RecursiveArrayIterator($empty));
    }

    /**
     * @param RecursiveIterator<string, SplFileInfo> $iterator
     * @param list<string>                           $expectedBasenames
     */
    private static function assertIteratorBasenames(RecursiveIterator $iterator, array $expectedBasenames): bool
    {
        $basenames = [];

        foreach (new RecursiveIteratorIterator($iterator) as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            $basenames[] = basename($fileInfo->getPathname());
        }

        self::assertSame($expectedBasenames, $basenames);

        return true;
    }
}
