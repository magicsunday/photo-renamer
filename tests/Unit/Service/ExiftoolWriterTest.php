<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Service\ExiftoolWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for the ExiftoolWriter service. Uses a mock approach since
 * exiftool may not be available in the test environment.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(ExiftoolWriter::class)]
final class ExiftoolWriterTest extends TestCase
{
    /**
     * Verifies that the writeDateTime method accepts three parameters.
     */
    #[Test]
    public function writeDateTimeMethodAcceptsThreeParameters(): void
    {
        $method = new ReflectionMethod(ExiftoolWriter::class, 'writeDateTime');

        self::assertSame(3, $method->getNumberOfParameters());
    }
}
