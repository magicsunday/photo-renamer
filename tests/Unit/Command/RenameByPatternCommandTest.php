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
use MagicSunday\Renamer\Service\Filesystem\FileCollector;
use MagicSunday\Renamer\Strategy\DuplicateIdentifier\TargetPathnameStrategy;
use MagicSunday\Renamer\Test\Fixtures\RuntimeExecutorFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
 * Verifies the RenameByPatternCommand configuration: command name registration
 * and argument/option definitions for regex-based renaming.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversNothing]
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
            new FileCollector(new SafeRegex()),
            RuntimeExecutorFactory::createLegacy(),
            self::createStub(DuplicateDetectionServiceInterface::class),
            new SafeRegex(),
            new Filesystem(),
            new TargetPathnameStrategy(),
        );

        self::assertSame('rename:pattern', $command->getName());
    }

    /**
     * Verifies that single-file mode delegates to the exact base-command file
     * selection instead of scanning all files that match the pattern in the
     * parent directory.
     */
    #[Test]
    public function createFileIteratorUsesExactSingleFileSelection(): void
    {
        $filesystem = new Filesystem();
        $workspace  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'renamer-pattern-single-' . uniqid();
        $sourceFile = $workspace . DIRECTORY_SEPARATOR . 'Photo.jpg';

        mkdir($workspace);
        file_put_contents($sourceFile, 'selected');
        file_put_contents($workspace . DIRECTORY_SEPARATOR . 'photo.jpg', 'other');

        try {
            $command = $this->createCommand();
            $this->configureCommandState($command, $workspace, $sourceFile, isSingleFile: true);

            $this->assertIteratorBasenames($this->invokeCreateFileIterator($command), ['Photo.jpg']);
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * Verifies that directory mode keeps using the configured pattern as the
     * recursive file filter.
     */
    #[Test]
    public function createFileIteratorKeepsPatternFilterForDirectories(): void
    {
        $filesystem = new Filesystem();
        $workspace  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'renamer-pattern-dir-' . uniqid();

        mkdir($workspace);
        file_put_contents($workspace . DIRECTORY_SEPARATOR . 'match.txt', 'match');
        file_put_contents($workspace . DIRECTORY_SEPARATOR . 'skip.jpg', 'skip');

        try {
            $command = $this->createCommand();
            $this->configureCommandState($command, $workspace, $workspace, isSingleFile: false);
            $this->setProperty($command, 'pattern', '/\.txt$/');

            $this->assertIteratorBasenames($this->invokeCreateFileIterator($command), ['match.txt']);
        } finally {
            $filesystem->remove($workspace);
        }
    }

    private function createCommand(): RenameByPatternCommand
    {
        return new RenameByPatternCommand(
            new FileCollector(new SafeRegex()),
            RuntimeExecutorFactory::createLegacy(),
            self::createStub(DuplicateDetectionServiceInterface::class),
            new SafeRegex(),
            new Filesystem(),
            new TargetPathnameStrategy(),
        );
    }

    private function configureCommandState(
        RenameByPatternCommand $command,
        string $sourceDirectory,
        string $source,
        bool $isSingleFile,
    ): void {
        $this->setProperty($command, 'sourceDirectory', $sourceDirectory);
        $this->setProperty($command, 'isSingleFile', $isSingleFile);
        $this->setProperty($command, 'input', new ArrayInput(['source' => $source], $command->getDefinition()));
        $this->setProperty($command, 'pattern', '/\.jpg$/');
    }

    /**
     * Invokes the command's protected scanner and returns its actual filtered files.
     *
     * @param RenameByPatternCommand $command Configured command whose source selection is exercised
     *
     * @return RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>> Actual filtered files
     */
    private function invokeCreateFileIterator(RenameByPatternCommand $command): RecursiveIteratorIterator
    {
        $method = new ReflectionMethod($command, 'createFileIterator');
        /** @var RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>> $iterator */
        $iterator = $method->invoke($command);
        self::assertInstanceOf(RecursiveIteratorIterator::class, $iterator);

        return $iterator;
    }

    private function setProperty(RenameByPatternCommand $command, string $property, mixed $value): void
    {
        $reflectionProperty = new ReflectionProperty($command, $property);
        $reflectionProperty->setValue($command, $value);
    }

    /**
     * @param RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>> $iterator
     * @param list<string>                                                      $expectedBasenames
     */
    private function assertIteratorBasenames(RecursiveIteratorIterator $iterator, array $expectedBasenames): bool
    {
        $basenames = [];

        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            $basenames[] = basename($fileInfo->getPathname());
        }

        self::assertSame($expectedBasenames, $basenames);

        return true;
    }
}
