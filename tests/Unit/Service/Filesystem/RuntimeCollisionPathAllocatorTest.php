<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Filesystem;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Service\Filesystem\RuntimeCollisionPathAllocator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function sprintf;

#[CoversClass(RuntimeCollisionPathAllocator::class)]
#[UsesClass(FileHelper::class)]
/**
 * Verifies the runtime collision path allocator used by filesystem execution.
 *
 * These tests cover the duplicate-suffix fallback policy directly, without
 * relying on reflection against FileSystemService internals.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class RuntimeCollisionPathAllocatorTest extends TestCase
{
    /**
     * Verifies that the allocator throws when all possible duplicate suffixes
     * are already occupied.
     */
    #[Test]
    public function findAvailableDuplicatePathThrowsWhenMaxSuffixExceeded(): void
    {
        $allocator  = new RuntimeCollisionPathAllocator();
        $targetPath = '/tmp/dir/photo.jpg';

        /** @var array<string, true> $occupiedPaths */
        $occupiedPaths = [];

        for ($i = 1; $i <= 999; ++$i) {
            $occupiedPaths[sprintf('/tmp/dir/photo%s%03d.jpg', Constants::DUPLICATE_IDENTIFIER, $i)] = true;
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Exceeded 999 attempts finding available target for "photo"');

        $allocator->findAvailableDuplicatePath($targetPath, $occupiedPaths);
    }

    /**
     * Verifies that an existing duplicate suffix is stripped before a new
     * fallback suffix is appended.
     */
    #[Test]
    public function findAvailableDuplicatePathStripsDuplicateSuffixBeforeGeneratingNew(): void
    {
        $allocator  = new RuntimeCollisionPathAllocator();
        $targetPath = '/tmp/dir/photo' . Constants::DUPLICATE_IDENTIFIER . '003.jpg';

        /** @var array<string, true> $occupiedPaths */
        $occupiedPaths = [
            $targetPath => true,
        ];

        $result = $allocator->findAvailableDuplicatePath($targetPath, $occupiedPaths);

        self::assertSame(
            '/tmp/dir/photo' . Constants::DUPLICATE_IDENTIFIER . '001.jpg',
            $result,
        );
        self::assertDoesNotMatchRegularExpression(
            '/-duplicate-\d+-duplicate-/',
            $result,
            'Must not produce nested duplicate suffixes',
        );
    }

    /**
     * Verifies that occupied fallback suffixes are skipped until a free target
     * path is found.
     */
    #[Test]
    public function findAvailableDuplicatePathSkipsOccupiedSuffixesAfterStripping(): void
    {
        $allocator  = new RuntimeCollisionPathAllocator();
        $targetPath = '/tmp/dir/photo' . Constants::DUPLICATE_IDENTIFIER . '005.jpg';

        /** @var array<string, true> $occupiedPaths */
        $occupiedPaths = [
            $targetPath                                                    => true,
            '/tmp/dir/photo' . Constants::DUPLICATE_IDENTIFIER . '001.jpg' => true,
            '/tmp/dir/photo' . Constants::DUPLICATE_IDENTIFIER . '002.jpg' => true,
        ];

        $result = $allocator->findAvailableDuplicatePath($targetPath, $occupiedPaths);

        self::assertSame(
            '/tmp/dir/photo' . Constants::DUPLICATE_IDENTIFIER . '003.jpg',
            $result,
        );
    }

    /**
     * Verifies that extensionless targets do not receive a trailing dot when a
     * runtime duplicate fallback is allocated.
     */
    #[Test]
    public function findAvailableDuplicatePathOmitsTrailingDotForExtensionlessTargets(): void
    {
        $allocator  = new RuntimeCollisionPathAllocator();
        $targetPath = '/tmp/dir/photo';

        /** @var array<string, true> $occupiedPaths */
        $occupiedPaths = [
            $targetPath => true,
        ];

        self::assertSame(
            '/tmp/dir/photo' . Constants::DUPLICATE_IDENTIFIER . '001',
            $allocator->findAvailableDuplicatePath($targetPath, $occupiedPaths),
        );
    }
}
