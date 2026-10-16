<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration;

use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function dirname;

use const PHP_BINARY;

/**
 * Proves that a child can return zero yet still invalidate command acceptance.
 */
#[CoversNothing]
final class CommandContractDiagnosticsTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * @param string $diagnostic PHP engine diagnostic emitted by the controlled child
     * @param string $stream     stdout or stderr boundary
     */
    #[Test]
    #[DataProvider('runtimeDiagnostics')]
    public function zeroExitWithRuntimeDiagnosticFailsAcceptance(string $diagnostic, string $stream): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $project    = dirname(__DIR__, 2);
        $entry      = $workspace . '/diagnostic.sh';
        $filesystem->dumpFile($entry, <<<'SH'
            #!/bin/sh
            if [ "$CONTRACT_PROBE_STREAM" = stderr ]; then
                printf '%s\n' "$CONTRACT_PROBE_DIAGNOSTIC" >&2
            else
                printf '%s\n' "$CONTRACT_PROBE_DIAGNOSTIC"
            fi
            exit 0
            SH);
        $filesystem->chmod($entry, 0o700);

        try {
            $process = new Process([
                PHP_BINARY,
                $project . '/scripts/check-command-contracts.php',
                $project . '/.build/vendor/autoload.php',
                $project . '/tests/Fixtures/Images',
                $entry,
            ], $workspace, ['CONTRACT_PROBE_DIAGNOSTIC' => $diagnostic, 'CONTRACT_PROBE_STREAM' => $stream]);
            $process->setTimeout(20);
            $process->run();
            self::assertNotSame(0, $process->getExitCode());
            self::assertStringContainsString('PHP runtime diagnostic', $process->getOutput() . $process->getErrorOutput());
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * @return iterable<string, array{string, string}> Runtime diagnostic partitions
     */
    public static function runtimeDiagnostics(): iterable
    {
        yield 'warning on stderr' => ['PHP Warning: synthetic warning', 'stderr'];
        yield 'notice on stdout' => ['Notice: synthetic notice', 'stdout'];
        yield 'deprecation on stderr' => ['Deprecated: synthetic deprecation', 'stderr'];
    }
}
