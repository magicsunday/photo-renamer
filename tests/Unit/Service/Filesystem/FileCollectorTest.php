<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Filesystem;

use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\Filesystem\FileCollector;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIterator;
use SplFileInfo;

use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

#[CoversClass(FileCollector::class)]
#[UsesClass(RecursiveRegexFileFilterIterator::class)]
/**
 * Verifies the dedicated filesystem collector extracted from FileSystemService.
 *
 * The collector owns directory traversal and flat file collection so those
 * responsibilities can be tested without going through the broader filesystem facade.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class FileCollectorTest extends TestCase
{
    use WorkspaceTrait;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('photo-renamer-collector-', true);
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeWorkspace($this->workspace);

        parent::tearDown();
    }

    /**
     * Verifies that collectFiles() returns all regular files from nested directories
     * while ignoring directory entries themselves.
     */
    #[Test]
    public function collectFilesReturnsRegularFilesFromNestedDirectories(): void
    {
        $collector = new FileCollector(new SafeRegex());

        $nestedDirectory = $this->workspace . DIRECTORY_SEPARATOR . 'nested';
        mkdir($nestedDirectory);

        $fileA = $this->workspace . DIRECTORY_SEPARATOR . 'a.jpg';
        $fileB = $nestedDirectory . DIRECTORY_SEPARATOR . 'b.mov';

        file_put_contents($fileA, 'a');
        file_put_contents($fileB, 'b');

        $files = $collector->collectFiles($this->workspace);

        $pathnames = [];

        foreach ($files as $file) {
            $pathnames[] = $file->getPathname();
        }

        self::assertCount(2, $files);
        self::assertContains($fileA, $pathnames);
        self::assertContains($fileB, $pathnames);
    }

    /**
     * Verifies that createFileIterator() respects an injected recursive iterator
     * instead of always creating a directory-based iterator internally.
     */
    #[Test]
    public function createFileIteratorUsesProvidedRecursiveIterator(): void
    {
        $collector = new FileCollector(new SafeRegex());

        /** @var RecursiveIterator<string, SplFileInfo> $recursiveIterator */
        $recursiveIterator = new RecursiveArrayIterator([
            new SplFileInfo($this->workspace . DIRECTORY_SEPARATOR . 'provided.jpg'),
        ]);

        $iterator = $collector->createFileIterator(
            $this->workspace,
            $recursiveIterator,
        );

        self::assertSame($recursiveIterator, $iterator->getInnerIterator());
    }
}
