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

/**
 * Executes the actual quality Make recipes with an inert container-command
 * boundary. This tests shell/Make status propagation without nesting Docker or
 * running the expensive tools; PHP source coverage remains checked separately.
 */
#[CoversNothing]
final class QualityTargetExitStatusTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * A failing coverage or mutation command must fail its enclosing Make target,
     * while a successful command must retain success. Record the actual recipe
     * arguments to distinguish tool execution from a failed fixture setup.
     *
     * @param string $target       Actual quality target selected from Make/ci.mk
     * @param string $composerTask Expected Composer task passed to the tool boundary
     * @param int    $toolExitCode Synthetic tool result propagated through the recipe
     * @param int    $makeExitCode Expected Make status, which maps recipe failures to two
     *
     * @return void
     */
    #[Test]
    #[DataProvider('qualityResults')]
    public function targetPropagatesToolResult(string $target, string $composerTask, int $toolExitCode, int $makeExitCode): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();

        try {
            $filesystem->dumpFile($workspace . '/Makefile', 'include ' . dirname(__DIR__, 2) . "/Make/ci.mk\nCOMPOSE_BUILD := bash tool.sh\n.logo:\n\t@:\n");
            $filesystem->dumpFile($workspace . '/tool.sh', <<<'SH'
                #!/usr/bin/env bash
                set -euo pipefail
                printf '%s\n' "$@" > arguments
                exit "${QUALITY_TOOL_EXIT_CODE}"
                SH);
            $process = new Process(['make', '--no-print-directory', $target], $workspace, [
                'QUALITY_TOOL_EXIT_CODE' => (string) $toolExitCode,
                'MAKEFLAGS'              => false,
                'MFLAGS'                 => false,
                'MAKELEVEL'              => false,
                'GITHUB_TOKEN'           => false,
                'GH_TOKEN'               => false,
                'COMPOSER_AUTH'          => false,
            ]);
            $process->setTimeout(10);
            $process->run();

            self::assertFileExists($workspace . '/arguments', $process->getErrorOutput());
            self::assertSame("composer\n" . $composerTask . "\n", $filesystem->readFile($workspace . '/arguments'));
            self::assertSame($makeExitCode, $process->getExitCode(), $process->getErrorOutput());
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * Supplies positive controls alongside both error regressions, so a target
     * that always fails cannot satisfy the contract.
     *
     * @return iterable<string, array{string, string, int, int}> Target and tool/Make statuses
     */
    public static function qualityResults(): iterable
    {
        yield 'coverage error' => ['coverage', 'ci:test:php:unit:coverage', 53, 2];
        yield 'coverage success' => ['coverage', 'ci:test:php:unit:coverage', 0, 0];
        yield 'mutation error' => ['mutation', 'ci:test:php:mutation', 53, 2];
        yield 'mutation success' => ['mutation', 'ci:test:php:mutation', 0, 0];
    }
}
