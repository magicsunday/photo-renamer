<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Service\LegacyTargetPathResolver;
use MagicSunday\Renamer\Service\TargetPathResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies absolute target path resolution for the legacy duplicate pipeline.
 *
 * The resolver must preserve nested relative directories beneath the source
 * root, keep absolute source roots stable, and reject any generated filename
 * that tries to encode additional path segments.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(LegacyTargetPathResolver::class)]
#[UsesClass(TargetPathResolver::class)]
final class LegacyTargetPathResolverTest extends TestCase
{
    /**
     * Verifies that nested relative directories are preserved beneath the source
     * root when a target pathname is generated.
     */
    #[Test]
    public function resolveRetainsNestedDirectoriesWithDuplicateNames(): void
    {
        $resolver   = new LegacyTargetPathResolver(new TargetPathResolver());
        $sourceRoot = DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'Fotos';
        $source     = new SplFileInfo(
            $sourceRoot
            . DIRECTORY_SEPARATOR . '2024'
            . DIRECTORY_SEPARATOR . '2024-01-01'
            . DIRECTORY_SEPARATOR . '2024-01-01'
            . DIRECTORY_SEPARATOR . 'IMG_0001.jpg',
        );

        $result = $resolver->resolve($sourceRoot, $source, '2024-01-01_12-00-00-000.jpg');

        self::assertSame(
            $sourceRoot
            . DIRECTORY_SEPARATOR . '2024'
            . DIRECTORY_SEPARATOR . '2024-01-01'
            . DIRECTORY_SEPARATOR . '2024-01-01'
            . DIRECTORY_SEPARATOR . '2024-01-01_12-00-00-000.jpg',
            $result,
        );
    }

    /**
     * Verifies that source files already living under an absolute nested source
     * root keep their relative depth when the target pathname is built.
     */
    #[Test]
    public function resolvePreservesRelativeDepthForAbsoluteDirectories(): void
    {
        $resolver   = new LegacyTargetPathResolver(new TargetPathResolver());
        $sourceRoot = DIRECTORY_SEPARATOR . 'volume1' . DIRECTORY_SEPARATOR . 'Fotos';
        $source     = new SplFileInfo(
            $sourceRoot
            . DIRECTORY_SEPARATOR . '2019'
            . DIRECTORY_SEPARATOR . 'Zoo'
            . DIRECTORY_SEPARATOR . 'IMG_0001.heic',
        );

        $result = $resolver->resolve($sourceRoot, $source, '2019-09-28_16-57-59-738.heic');

        self::assertSame(
            $sourceRoot
            . DIRECTORY_SEPARATOR . '2019'
            . DIRECTORY_SEPARATOR . 'Zoo'
            . DIRECTORY_SEPARATOR . '2019-09-28_16-57-59-738.heic',
            $result,
        );
    }

    /**
     * Verifies that generated filenames may not contain directory separators.
     *
     * This protects the legacy path builder from accepting directory traversal or
     * accidental subdirectory fragments as part of the generated filename.
     */
    #[Test]
    public function resolveThrowsOnDirectorySeparatorInFilename(): void
    {
        $resolver   = new LegacyTargetPathResolver(new TargetPathResolver());
        $sourceRoot = DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'Fotos';
        $source     = new SplFileInfo($sourceRoot . DIRECTORY_SEPARATOR . 'IMG_0001.jpg');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('must not contain directory separators');

        $resolver->resolve($sourceRoot, $source, 'evil/subdir.jpg');
    }
}
