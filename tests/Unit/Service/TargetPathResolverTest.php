<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Service\TargetPathResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies shared target-path resolution across both execution paths.
 *
 * The resolver must preserve relative directory depth beneath the configured
 * source root while rejecting generated filenames that try to smuggle in their
 * own directory separators.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(TargetPathResolver::class)]
final class TargetPathResolverTest extends TestCase
{
    /**
     * Verifies that a nested source file keeps its relative directory structure
     * when the resolver swaps only the generated filename.
     */
    #[Test]
    public function resolvePreservesRelativeDirectories(): void
    {
        $resolver   = new TargetPathResolver();
        $sourceRoot = DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'Fotos';
        $source     = new SplFileInfo(
            $sourceRoot
            . DIRECTORY_SEPARATOR . '2019'
            . DIRECTORY_SEPARATOR . 'Zoo'
            . DIRECTORY_SEPARATOR . 'IMG_0001.jpg',
        );

        $targetPath = $resolver->resolve(
            $sourceRoot,
            $source,
            '2019-09-28_16-57-59-738.jpg',
        );

        self::assertSame(
            $sourceRoot
            . DIRECTORY_SEPARATOR . '2019'
            . DIRECTORY_SEPARATOR . 'Zoo'
            . DIRECTORY_SEPARATOR . '2019-09-28_16-57-59-738.jpg',
            $targetPath,
        );
    }

    /**
     * Verifies that generated filenames containing subdirectories are rejected.
     *
     * The project must only swap filenames, never let a strategy redirect files
     * into arbitrary nested paths.
     */
    #[Test]
    public function resolveRejectsDirectorySeparatorsInFilename(): void
    {
        $resolver = new TargetPathResolver();
        $source   = new SplFileInfo(
            DIRECTORY_SEPARATOR . 'tmp'
            . DIRECTORY_SEPARATOR . 'Fotos'
            . DIRECTORY_SEPARATOR . 'IMG_0001.jpg',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Target filename "nested/2019-09-28.jpg" must not contain directory separators');

        $resolver->resolve(
            DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'Fotos',
            $source,
            'nested/2019-09-28.jpg',
        );
    }

    /**
     * Verifies that generated filenames cannot collapse to directory aliases or
     * empty names. These values can be produced by user-supplied regex
     * replacements and must fail before any filesystem mutation is attempted.
     */
    #[Test]
    #[DataProvider('invalidTargetFilenameProvider')]
    public function resolveRejectsInvalidTargetFilename(string $targetFilename): void
    {
        $resolver = new TargetPathResolver();
        $source   = new SplFileInfo(
            DIRECTORY_SEPARATOR . 'tmp'
            . DIRECTORY_SEPARATOR . 'Fotos'
            . DIRECTORY_SEPARATOR . 'IMG_0001.jpg',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Target filename "%s" must be a valid filename', $targetFilename));

        $resolver->resolve(
            DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'Fotos',
            $source,
            $targetFilename,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTargetFilenameProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'current directory alias' => ['.'];
        yield 'parent directory alias' => ['..'];
    }
}
