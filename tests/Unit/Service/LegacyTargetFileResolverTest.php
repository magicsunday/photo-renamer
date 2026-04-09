<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Service\LegacyTargetFileResolver;
use MagicSunday\Renamer\Service\TargetFileResolver;
use MagicSunday\Renamer\Service\TargetPathResolver;
use MagicSunday\Renamer\Strategy\RenameStrategy\RenameStrategyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies target-file resolution for the legacy duplicate pipeline.
 *
 * The resolver must convert three strategy outcomes into stable result objects:
 * a generated filename becomes a success target, `null` becomes a skipped result,
 * and wrapped `TargetFilenameException`s surface only the root-cause message.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(LegacyTargetFileResolver::class)]
final class LegacyTargetFileResolverTest extends TestCase
{
    /**
     * Verifies that a generated filename is converted into a successful target
     * path beneath the legacy source root.
     */
    #[Test]
    public function resolveReturnsSuccessForGeneratedFilename(): void
    {
        $resolver   = new LegacyTargetFileResolver(new TargetFileResolver(new TargetPathResolver()));
        $sourceRoot = DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'Fotos';
        $source     = new SplFileInfo(
            $sourceRoot
            . DIRECTORY_SEPARATOR . '2019'
            . DIRECTORY_SEPARATOR . 'Zoo'
            . DIRECTORY_SEPARATOR . 'IMG_0001.jpg',
        );
        $strategy = $this->createMock(RenameStrategyInterface::class);
        $strategy
            ->expects(self::once())
            ->method('generateFilename')
            ->with($source)
            ->willReturn('2019-09-28_16-57-59-738.jpg');

        $result = $resolver->resolve($sourceRoot, $source, $strategy);

        self::assertFalse($result->isSkipped());
        self::assertFalse($result->isError());
        $targetFile = $result->getTargetFile();

        self::assertInstanceOf(SplFileInfo::class, $targetFile);
        self::assertSame(
            $sourceRoot
            . DIRECTORY_SEPARATOR . '2019'
            . DIRECTORY_SEPARATOR . 'Zoo'
            . DIRECTORY_SEPARATOR . '2019-09-28_16-57-59-738.jpg',
            $targetFile->getPathname(),
        );
    }

    /**
     * Verifies that a `null` filename from the strategy becomes the legacy
     * skipped state with the canonical `no capture date` reason.
     */
    #[Test]
    public function resolveReturnsSkippedWhenStrategyReturnsNull(): void
    {
        $resolver   = new LegacyTargetFileResolver(new TargetFileResolver(new TargetPathResolver()));
        $sourceRoot = DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'Fotos';
        $source     = new SplFileInfo($sourceRoot . DIRECTORY_SEPARATOR . 'IMG_0002.mov');
        $strategy   = $this->createMock(RenameStrategyInterface::class);
        $strategy
            ->expects(self::once())
            ->method('generateFilename')
            ->with($source)
            ->willReturn(null);

        $result = $resolver->resolve($sourceRoot, $source, $strategy);

        self::assertTrue($result->isSkipped());
        self::assertFalse($result->isError());
        self::assertSame('no capture date', $result->getSkipReason());
    }

    /**
     * Verifies that nested metadata exceptions are unwrapped to the innermost
     * root-cause message before the error result is returned.
     */
    #[Test]
    public function resolveReturnsErrorWithRootCauseMessage(): void
    {
        $resolver   = new LegacyTargetFileResolver(new TargetFileResolver(new TargetPathResolver()));
        $sourceRoot = DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'Fotos';
        $source     = new SplFileInfo($sourceRoot . DIRECTORY_SEPARATOR . 'IMG_0003.heic');
        $strategy   = $this->createMock(RenameStrategyInterface::class);
        $strategy
            ->expects(self::once())
            ->method('generateFilename')
            ->with($source)
            ->willThrowException(
                new TargetFilenameException(
                    'outer',
                    previous: new TargetFilenameException(
                        'middle',
                        previous: new RuntimeException('root-cause boom'),
                    ),
                ),
            );

        $result = $resolver->resolve($sourceRoot, $source, $strategy);

        self::assertTrue($result->isSkipped());
        self::assertTrue($result->isError());
        self::assertSame('root-cause boom', $result->getSkipReason());
    }
}
