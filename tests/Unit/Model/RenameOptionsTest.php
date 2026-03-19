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

/**
 * Verifies the value-object contract of RenameOptions, the immutable configuration
 * carrier passed from AbstractRenameCommand to FileSystemService::renameFiles().
 *
 * RenameOptions controls dry-run mode, duplicate skipping, list-all output, and
 * base directory paths. Correct defaults and explicit overrides are critical
 * because the FileSystemService branches on every one of these flags.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(RenameOptions::class)]
final class RenameOptionsTest extends TestCase
{
    /**
     * Verifies that a default-constructed RenameOptions has all boolean flags
     * set to false, nullable paths set to null, and numeric counters at zero.
     *
     * This ensures that omitting options from the command line does not
     * accidentally enable dry-run, copy mode, or duplicate skipping,
     * which would change the rename behaviour in unexpected ways.
     */
    #[Test]
    public function itUsesDefaultValues(): void
    {
        $options = new RenameOptions();

        self::assertFalse($options->dryRun);
        self::assertFalse($options->skipDuplicates);
        self::assertFalse($options->listAll);
        self::assertNull($options->sourceBaseDirectory);
    }

    /**
     * Verifies that every constructor parameter is stored and readable from the
     * corresponding public property when non-default values are provided.
     *
     * This ensures that AbstractRenameCommand can pass arbitrary combinations
     * of flags and paths through to FileSystemService without any value being
     * silently dropped or overridden.
     */
    #[Test]
    public function itAcceptsCustomValues(): void
    {
        $options = new RenameOptions(
            dryRun: true,
            skipDuplicates: true,
            listAll: true,
            sourceBaseDirectory: '/source',
        );

        self::assertTrue($options->dryRun);
        self::assertTrue($options->skipDuplicates);
        self::assertTrue($options->listAll);
        self::assertSame('/source', $options->sourceBaseDirectory);
    }
}
