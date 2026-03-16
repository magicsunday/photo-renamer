<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Model;

use MagicSunday\Renamer\Model\RenameOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RenameOptions::class)]
final class RenameOptionsTest extends TestCase
{
    #[Test]
    public function itUsesDefaultValues(): void
    {
        $options = new RenameOptions();

        self::assertFalse($options->dryRun);
        self::assertFalse($options->skipDuplicates);
        self::assertFalse($options->copyFiles);
        self::assertFalse($options->listAll);
        self::assertNull($options->sourceBaseDirectory);
        self::assertNull($options->targetBaseDirectory);
        self::assertNull($options->scannedFiles);
        self::assertSame(0, $options->namingCollisions);
    }

    #[Test]
    public function itAcceptsCustomValues(): void
    {
        $options = new RenameOptions(
            dryRun: true,
            skipDuplicates: true,
            copyFiles: true,
            listAll: true,
            sourceBaseDirectory: '/source',
            targetBaseDirectory: '/target',
            scannedFiles: 42,
            namingCollisions: 3,
        );

        self::assertTrue($options->dryRun);
        self::assertTrue($options->skipDuplicates);
        self::assertTrue($options->copyFiles);
        self::assertTrue($options->listAll);
        self::assertSame('/source', $options->sourceBaseDirectory);
        self::assertSame('/target', $options->targetBaseDirectory);
        self::assertSame(42, $options->scannedFiles);
        self::assertSame(3, $options->namingCollisions);
    }
}
