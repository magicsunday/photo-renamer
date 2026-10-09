<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Dedup;

use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Service\Dedup\DuplicateDeletionGuard;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Service\SafeHashCalculatorInterface;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function file_put_contents;
use function rename;
use function symlink;
use function unlink;

/**
 * Exercises deletion evidence with real files, including stale hash caches and
 * deterministic changes during hashing that must never authorize removal.
 */
#[CoversClass(DuplicateDeletionGuard::class)]
#[UsesClass(SafeHashCalculator::class)]
#[UsesClass(HashComputationException::class)]
final class DuplicateDeletionGuardTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Ensures an earlier successful comparison cannot authorize deletion after
     * a same-size edit, even if a prior caller populated the shared hash cache.
     */
    #[Test]
    public function rechecksBytesInsteadOfTrustingCachedHashes(): void
    {
        $workspace = $this->createTempWorkspace();
        $original  = new SplFileInfo($workspace . '/original.jpg');
        $duplicate = new SplFileInfo($workspace . '/duplicate.jpg');
        file_put_contents($original->getPathname(), 'AAAA');
        file_put_contents($duplicate->getPathname(), 'AAAA');

        try {
            $calculator = new SafeHashCalculator();
            $guard      = new DuplicateDeletionGuard($calculator);
            self::assertTrue($guard->permitsDeletion($duplicate, $original));
            $calculator->hashFile($duplicate, 'sha256');
            file_put_contents($duplicate->getPathname(), 'BBBB');
            self::assertFalse($guard->permitsDeletion($duplicate, $original));
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Both paths must remain regular files. Missing originals and source links
     * cannot supply proof, even when a symlink resolves to identical contents.
     *
     * @param bool $symlinked Whether to replace the duplicate with a symlink
     */
    #[Test]
    #[DataProvider('unavailableFileCases')]
    public function rejectsUnavailableOrSymlinkedFiles(bool $symlinked): void
    {
        $workspace = $this->createTempWorkspace();
        $original  = new SplFileInfo($workspace . '/original.jpg');
        $duplicate = new SplFileInfo($workspace . '/duplicate.jpg');
        file_put_contents($original->getPathname(), 'AAAA');
        file_put_contents($duplicate->getPathname(), 'AAAA');

        try {
            if ($symlinked) {
                unlink($duplicate->getPathname());
                symlink($original->getPathname(), $duplicate->getPathname());
            } else {
                unlink($original->getPathname());
            }

            self::assertFalse(new DuplicateDeletionGuard(new SafeHashCalculator())->permitsDeletion($duplicate, $original));
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Supplies missing-original and symlink-source cases without timing assumptions.
     *
     * @return iterable<string, array{bool}> File availability cases
     */
    public static function unavailableFileCases(): iterable
    {
        yield 'missing original' => [false];
        yield 'symlink duplicate' => [true];
    }

    /**
     * Changes one path after the hashes were read. The before/after state check
     * must reject removal, including same-size replacements with another inode.
     *
     * @param bool   $changeOriginal Whether the retained original is changed
     * @param string $mutation       Deterministic change after the final hash read
     */
    #[Test]
    #[DataProvider('concurrentChangeCases')]
    public function rejectsFilesChangedDuringHashing(bool $changeOriginal, string $mutation): void
    {
        $workspace = $this->createTempWorkspace();
        $original  = new SplFileInfo($workspace . '/original.jpg');
        $duplicate = new SplFileInfo($workspace . '/duplicate.jpg');
        file_put_contents($original->getPathname(), 'AAAA');
        file_put_contents($duplicate->getPathname(), 'AAAA');

        try {
            $calculator = new SafeHashCalculator();
            $hashing    = self::createStub(SafeHashCalculatorInterface::class);
            $hashing->method('hashFile')->willReturnCallback(
                static function (SplFileInfo $file, string $algorithm) use ($calculator, $original, $duplicate, $changeOriginal, $mutation, $workspace): string {
                    $hash = $calculator->hashFile($file, $algorithm);

                    if ($file->getPathname() === $original->getPathname()) {
                        $changedPath = $changeOriginal ? $original->getPathname() : $duplicate->getPathname();

                        if ($mutation === 'remove') {
                            unlink($changedPath);
                        } elseif ($mutation === 'replace') {
                            file_put_contents($workspace . '/replacement', 'BBBB');
                            rename($workspace . '/replacement', $changedPath);
                        } else {
                            file_put_contents($changedPath, 'changed size');
                        }
                    }

                    return $hash;
                },
            );

            self::assertFalse(new DuplicateDeletionGuard($hashing)->permitsDeletion($duplicate, $original));
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Covers mutations of both the retained file and the proposed deletion.
     *
     * @return iterable<string, array{bool, string}> Mutation scenarios
     */
    public static function concurrentChangeCases(): iterable
    {
        yield 'original removed' => [true, 'remove'];
        yield 'duplicate removed' => [false, 'remove'];
        yield 'original replaced' => [true, 'replace'];
        yield 'duplicate replaced' => [false, 'replace'];
        yield 'original edited' => [true, 'edit'];
        yield 'duplicate edited' => [false, 'edit'];
    }

    /**
     * A hash failure is insufficient evidence rather than permission to delete.
     * Injection makes this deterministic even when tests run with elevated rights.
     */
    #[Test]
    public function unreadableContentsFailClosed(): void
    {
        $workspace = $this->createTempWorkspace();
        $original  = new SplFileInfo($workspace . '/original.jpg');
        $duplicate = new SplFileInfo($workspace . '/duplicate.jpg');
        file_put_contents($original->getPathname(), 'AAAA');
        file_put_contents($duplicate->getPathname(), 'AAAA');

        try {
            $hashing = self::createStub(SafeHashCalculatorInterface::class);
            $hashing->method('hashFile')->willThrowException(new HashComputationException('Unreadable fixture'));
            self::assertFalse(new DuplicateDeletionGuard($hashing)->permitsDeletion($duplicate, $original));
        } finally {
            $this->removeWorkspace($workspace);
        }
    }
}
