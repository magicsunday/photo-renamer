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
 * Verifies the RenameByDatePatternCommand configuration: command name registration
 * and argument/option definitions.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversNothing]
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
            new FileCollector(new SafeRegex()),
            RuntimeExecutorFactory::createLegacy(),
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
            $command = $this->createCommand();
            $this->configureCommandState($command, $workspace, $sourceFile, isSingleFile: true);

            $this->assertIteratorBasenames($this->invokeCreateFileIterator($command), ['2024-01-01_10-00-00.jpg']);
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
            $command = $this->createCommand();
            $this->configureCommandState($command, $workspace, $workspace, isSingleFile: false);

            $this->assertIteratorBasenames($this->invokeCreateFileIterator($command), ['2024-01-01_10-00-00.jpg']);
        } finally {
            $filesystem->remove($workspace);
        }
    }

    private function createCommand(): RenameByDatePatternCommand
    {
        return new RenameByDatePatternCommand(
            new FileCollector(new SafeRegex()),
            RuntimeExecutorFactory::createLegacy(),
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

    /**
     * Invokes the command's protected scanner and returns its actual filtered files.
     *
     * @param RenameByDatePatternCommand $command Configured command whose source selection is exercised
     *
     * @return RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>> Actual filtered files
     */
    private function invokeCreateFileIterator(RenameByDatePatternCommand $command): RecursiveIteratorIterator
    {
        $method = new ReflectionMethod($command, 'createFileIterator');
        /** @var RecursiveIteratorIterator<RecursiveIterator<string, SplFileInfo>> $iterator */
        $iterator = $method->invoke($command);
        self::assertInstanceOf(RecursiveIteratorIterator::class, $iterator);

        return $iterator;
    }

    private function setProperty(RenameByDatePatternCommand $command, string $property, mixed $value): void
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
