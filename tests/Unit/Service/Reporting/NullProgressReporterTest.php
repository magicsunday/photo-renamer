<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Reporting;

use MagicSunday\Renamer\Service\Reporting\NullProgressReporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that NullProgressReporter satisfies the reporting contract while
 * remaining completely silent.
 *
 * Virtual-flow and unit tests need a reporter that can be passed through the
 * same service graph as production code without allocating console objects or
 * changing behavior. This test locks that no-op contract in place.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(NullProgressReporter::class)]
final class NullProgressReporterTest extends TestCase
{
    /**
     * Ensures every reporting method can be called safely without side effects
     * or special setup.
     *
     * This is the core requirement for virtual-flow tests that need to exercise
     * large service graphs without coupling themselves to console output.
     */
    #[Test]
    public function itAcceptsAllReportingCallsAsNoOps(): void
    {
        $this->expectNotToPerformAssertions();

        $reporter = new NullProgressReporter();

        $reporter->section('phase');
        $reporter->startProgress(3);
        $reporter->advance();
        $reporter->finish();
        $reporter->text('message');
        $reporter->debug('debug');
        $reporter->error('problem');
    }
}
