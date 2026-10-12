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
 * Checks actual release/no-dev entrypoints without running PHP, Composer or
 * SPC fixtures. Missing lock input must stop before any installer invocation;
 * executable markers distinguish this boundary from a later build failure.
 */
#[CoversNothing]
final class LockedBuildInputTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * With valid minimal source/bootstrap markers but no composer.lock, both
     * entrypoints previously attempted fresh resolution. Keep all external
     * tools inert and assert failure occurs before their marker is created.
     *
     * @param string $entrypoint Actual source script exercised by the fixture
     *
     * @return void
     */
    #[Test]
    #[DataProvider('installingEntrypoints')]
    public function missingLockStopsBeforeAnyInstaller(string $entrypoint): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $tool       = <<<'SH'
            #!/usr/bin/env bash
            printf 'called' > "${LOCK_FIXTURE_STATE}/invoked"
            exit 74
            SH;

        try {
            $filesystem->copy(dirname(__DIR__, 2) . '/scripts/' . $entrypoint, $workspace . '/scripts/' . $entrypoint);
            $filesystem->copy(dirname(__DIR__, 2) . '/scripts/check-lock.sh', $workspace . '/scripts/check-lock.sh');
            $filesystem->mkdir([$workspace . '/src', $workspace . '/config', $workspace . '/state', $workspace . '/temporary']);
            $filesystem->dumpFile($workspace . '/composer.json', "{}\n");
            $filesystem->dumpFile($workspace . '/version', "1.0.0\n");
            $filesystem->dumpFile($workspace . '/.build/spc/buildroot/bin/micro.sfx', 'synthetic complete bootstrap marker');

            foreach (['php', 'composer'] as $name) {
                $filesystem->dumpFile($workspace . '/.build/bin/' . $name, $tool);
                $filesystem->chmod($workspace . '/.build/bin/' . $name, 0o755);
            }

            $process = new Process(['bash', $workspace . '/scripts/' . $entrypoint], $workspace, [
                'LOCK_FIXTURE_STATE' => $workspace . '/state',
                'PHP_BIN'            => $workspace . '/.build/bin/php',
                'COMPOSER_BIN'       => $workspace . '/.build/bin/composer',
                'TMPDIR'             => $workspace . '/temporary',
                'GITHUB_TOKEN'       => false,
                'GH_TOKEN'           => false,
                'COMPOSER_AUTH'      => false,
            ]);
            $process->setTimeout(15);
            $process->run();
            self::assertNotSame(0, $process->getExitCode());
            self::assertFileDoesNotExist($workspace . '/state/invoked');
            self::assertStringContainsString('composer.lock', $process->getOutput() . $process->getErrorOutput());
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * @return iterable<string, array{string}> Build and no-dev resolution boundaries
     */
    public static function installingEntrypoints(): iterable
    {
        yield 'binary artifact' => ['build'];
        yield 'no-dev artifact' => ['check-no-dev-runtime'];
    }
}
