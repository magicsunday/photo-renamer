<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Dedup;

use MagicSunday\Renamer\Service\Dedup\QuarantineTargetGuard;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Exercises boundary changes on real temporary paths, including dangling links
 * that file_exists alone misses and siblings with a shared textual prefix.
 */
#[CoversClass(QuarantineTargetGuard::class)]
final class QuarantineTargetGuardTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * A final link is unsafe regardless of whether its destination exists or
     * remains inside the root. Renaming onto it must never be authorized.
     *
     * @param bool $dangling Whether the target link points to an absent file
     */
    #[Test]
    #[DataProvider('linkStates')]
    public function rejectsFinalLinksIncludingDanglingOnes(bool $dangling): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $filesystem->mkdir($workspace . '/_duplicates');

        $destination = $workspace . '/existing.jpg';

        if (!$dangling) {
            $filesystem->dumpFile($destination, 'retained');
        }

        $filesystem->symlink($destination, $workspace . '/_duplicates/photo.jpg');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/symbolic link/');
            new QuarantineTargetGuard()->assertSafeTarget($workspace, $workspace . '/_duplicates/photo.jpg');
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * @return iterable<string, array{bool}> Existing and dangling link states
     */
    public static function linkStates(): iterable
    {
        yield 'existing internal file' => [false];
        yield 'dangling file' => [true];
    }

    /**
     * A sibling directory whose name starts with the source name is outside the
     * boundary, preventing a naive string-prefix check from accepting it.
     */
    #[Test]
    public function rejectsSiblingWithSameTextualPrefix(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $filesystem->mkdir([$workspace . '/source', $workspace . '/source-other']);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/outside/');
            new QuarantineTargetGuard()->assertSafeTarget($workspace . '/source', $workspace . '/source-other/photo.jpg');
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * A source root replaced by a link after planning no longer defines the same
     * canonical boundary and must be rejected before inspecting its target.
     */
    #[Test]
    public function rejectsSourceRootReplacedWithLink(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $filesystem->mkdir($workspace . '/outside');
        $filesystem->symlink($workspace . '/outside', $workspace . '/source');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/unchanged canonical source/');
            new QuarantineTargetGuard()->assertSafeTarget($workspace . '/source', $workspace . '/source/_duplicates/photo.jpg');
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * A regular file in a parent position cannot be treated as a directory, and
     * a directory in the final filename position cannot become a move target.
     *
     * @param bool $fileAsParent Whether a file occupies the parent or a directory the leaf
     */
    #[Test]
    #[DataProvider('invalidComponentTypes')]
    public function rejectsWrongComponentTypes(bool $fileAsParent): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();

        if ($fileAsParent) {
            $filesystem->dumpFile($workspace . '/_duplicates', 'regular file');
        } else {
            $filesystem->mkdir($workspace . '/_duplicates/photo.jpg');
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/directory/');
            new QuarantineTargetGuard()->assertSafeTarget($workspace, $workspace . '/_duplicates/photo.jpg');
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * @return iterable<string, array{bool}> Invalid parent and leaf component types
     */
    public static function invalidComponentTypes(): iterable
    {
        yield 'regular parent' => [true];
        yield 'directory leaf' => [false];
    }
}
