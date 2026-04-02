<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Service\DuplicateSuffixAssigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;

use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies suffix assignment rules extracted from the legacy duplicate pipeline.
 *
 * The assigner must preserve the legacy idempotency behavior: canonicals keep
 * the clean basename when possible, duplicates receive sequential
 * `-duplicate-NNN` targets, and already-correct suffixed names remain stable on
 * re-runs.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(DuplicateSuffixAssigner::class)]
final class DuplicateSuffixAssignerTest extends TestCase
{
    /**
     * Verifies that a canonical source already at its target pathname is left
     * untouched and does not consume a duplicate counter slot.
     */
    #[Test]
    public function resolveCanonicalTargetReturnsIdempotentTargetUnchanged(): void
    {
        $assigner = new DuplicateSuffixAssigner();
        $path     = DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'photo.jpg';
        $source   = new SplFileInfo($path);
        $target   = new SplFileInfo($path);
        $counter  = 1;

        $result = $assigner->resolveCanonicalTarget(
            $source,
            $target,
            $counter,
            [],
            static fn (): bool => false,
            static fn (): SplFileInfo => throw new RuntimeException('must not create duplicate candidate'),
        );

        self::assertSame($path, $result->getPathname());
        self::assertSame(1, $counter);
    }

    /**
     * Verifies that a non-first duplicate with additional renames always gets a
     * `-duplicate-001` target even when the original target path is otherwise free.
     */
    #[Test]
    public function createDuplicateTargetFileInfoAddsSuffixForNonFirstDuplicate(): void
    {
        $assigner = new DuplicateSuffixAssigner();
        $source   = new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'copy.jpg');
        $target   = new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2024-01-01.jpg');
        $counter  = 1;

        $result = $assigner->createDuplicateTargetFileInfo(
            $source,
            $target,
            $counter,
            false,
            true,
            false,
            [],
            static fn (): bool => false,
            static fn (SplFileInfo $candidateSource, SplFileInfo $candidateTarget, string $basename, int $duplicateCount): SplFileInfo => new SplFileInfo(
                $candidateTarget->getPath() . DIRECTORY_SEPARATOR . sprintf(
                    '%s' . Constants::DUPLICATE_IDENTIFIER . '%03d.%s',
                    $basename,
                    $duplicateCount,
                    $candidateTarget->getExtension(),
                ),
            ),
        );

        self::assertStringContainsString(Constants::DUPLICATE_IDENTIFIER . '001', $result->getFilename());
        self::assertSame(2, $counter);
    }

    /**
     * Verifies that forced suffix generation stops once a generated duplicate
     * candidate already equals the source pathname, preserving re-run idempotency.
     */
    #[Test]
    public function getNewUniqueDuplicateTargetFileInfoReturnsSourceWhenGeneratedCandidateMatchesIt(): void
    {
        $assigner = new DuplicateSuffixAssigner();
        $source   = new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2024-01-01-duplicate-001.jpg');
        $target   = new SplFileInfo(DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '2024-01-01.jpg');
        $counter  = 1;

        $result = $assigner->getNewUniqueDuplicateTargetFileInfo(
            $source,
            $target,
            '2024-01-01',
            $counter,
            true,
            [],
            static fn (): bool => false,
            static fn (): SplFileInfo => $source,
        );

        self::assertSame($source->getPathname(), $result->getPathname());
        self::assertSame(2, $counter);
    }
}
