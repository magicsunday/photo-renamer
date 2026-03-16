<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Exception\FileReadException;
use MagicSunday\Renamer\Service\SafeFileReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(SafeFileReader::class)]
/**
 * Verifies the SafeFileReader, which wraps PHP's file_get_contents() with an
 * error handler that converts warnings into typed FileReadException instances.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class SafeFileReaderTest extends TestCase
{
    /**
     * Verifies that reading an existing file returns its exact contents as a string.
     *
     * This is the happy path: a regular file with readable permissions must
     * be returned verbatim without data corruption or truncation.
     */
    #[Test]
    public function readReturnsFileContentsForExistingFile(): void
    {
        $reader = new SafeFileReader();

        $tempFile = tempnam(sys_get_temp_dir(), 'reader-test-');
        self::assertIsString($tempFile);

        try {
            $expectedContent = "line 1\nline 2\nspecial chars: \xC3\xA4\xC3\xB6\xC3\xBC";
            file_put_contents($tempFile, $expectedContent);

            $actualContent = $reader->read(new SplFileInfo($tempFile));

            self::assertSame($expectedContent, $actualContent);
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * Verifies that reading an empty file returns an empty string instead of
     * throwing an exception, distinguishing "empty file" from "read failure".
     */
    #[Test]
    public function readReturnsEmptyStringForEmptyFile(): void
    {
        $reader = new SafeFileReader();

        $tempFile = tempnam(sys_get_temp_dir(), 'reader-empty-');
        self::assertIsString($tempFile);

        try {
            file_put_contents($tempFile, '');

            $content = $reader->read(new SplFileInfo($tempFile));

            self::assertSame('', $content);
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * Verifies that attempting to read a non-existent file throws a
     * FileReadException with a message identifying the failed path.
     *
     * This is the error path: file_get_contents() emits a warning when the
     * file does not exist, which SafeFileReader must convert into a typed
     * domain exception.
     */
    #[Test]
    public function readThrowsFileReadExceptionForNonExistentFile(): void
    {
        $reader = new SafeFileReader();

        $nonExistentFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'non-existent-' . uniqid('', true) . '.txt';

        $this->expectException(FileReadException::class);
        $this->expectExceptionMessage('Failed to read file');

        $reader->read(new SplFileInfo($nonExistentFile));
    }
}
