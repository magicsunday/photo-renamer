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
use function posix_getegid;
use function posix_geteuid;
use function str_replace;

/**
 * Runs the real binary-init wrapper in an isolated synthetic checkout with
 * stub Docker/auth executables. No user configuration, tokens, SPC downloads
 * or actual container daemon are accessed. Preserve existing configuration on
 * successful reuse and failures, and initialize missing configuration clearly.
 */
#[CoversNothing]
final class BinaryInitConfigurationTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Existing values, comments and whitespace must survive all init outcomes.
     * A completed synthetic SPC tree exercises the real init script's early
     * return; controlled Docker/auth failures also preserve every original byte.
     *
     * @param string $scenario     Controlled subprocess outcome
     * @param int    $expectedExit Expected shell status
     *
     * @return void
     */
    #[Test]
    #[DataProvider('existingConfigurationOutcomes')]
    public function existingConfigurationSurvivesInit(string $scenario, int $expectedExit): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $contents   = "# Synthetic configuration, preserve comments and whitespace\nUSERID=43210\nGROUPID=43211\nTIMEZONE=UTC\nMAX_DATE_DRIFT=3\nMERGE_THRESHOLD=0.02\nCACHE_DIR=synthetic-cache\nFILE_LINK_BASE=synthetic media path\n# final retained comment\n";

        try {
            $this->prepareCheckout($workspace, $filesystem);
            $filesystem->dumpFile($workspace . '/.env', $contents);

            if ($scenario === 'already-initialized') {
                foreach (['.build/spc/buildroot/bin/micro.sfx', '.build/bin/php', '.build/bin/composer'] as $marker) {
                    $filesystem->dumpFile($workspace . '/' . $marker, 'synthetic initialized marker');
                }
            }

            $process = $this->runInit($workspace, $scenario);
            self::assertSame($expectedExit, $process->getExitCode());
            self::assertSame($contents, $filesystem->readFile($workspace . '/.env'));

            if ($scenario === 'already-initialized') {
                self::assertStringContainsString('SPC build environment already exists', $process->getOutput());
                self::assertSame(posix_geteuid() . ':' . posix_getegid(), $filesystem->readFile($workspace . '/observed-builder-ids'));
            }
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * @return iterable<string, array{string, int}> Reuse, controlled Docker failure and controlled missing authentication
     */
    public static function existingConfigurationOutcomes(): iterable
    {
        yield 'already initialized builder' => ['already-initialized', 0];
        yield 'Docker failure' => ['docker-failure', 33];
        yield 'missing authentication' => ['auth-failure', 1];
    }

    /**
     * Missing configuration must retain every documented default rather than
     * creating a two-key file. Only initial UID/GID defaults are replaced with
     * the invoking user's numeric IDs; no actual compiler download is started.
     *
     * @return void
     */
    #[Test]
    public function missingConfigurationUsesTemplateAndInvokingIds(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();

        try {
            $this->prepareCheckout($workspace, $filesystem);
            $process = $this->runInit($workspace, 'fresh');
            self::assertSame(0, $process->getExitCode());
            $template = $filesystem->readFile($workspace . '/.env.dist');
            $expected = str_replace(['USERID=1000', 'GROUPID=1000'], ['USERID=' . posix_geteuid(), 'GROUPID=' . posix_getegid()], $template);
            self::assertSame($expected, $filesystem->readFile($workspace . '/.env'));
            self::assertSame(posix_geteuid() . ':' . posix_getegid(), $filesystem->readFile($workspace . '/observed-builder-ids'));
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * Copies only public scripts/defaults into a disposable checkout and installs
     * stub executables. Docker delegates to the real init only when synthetic
     * completed markers guarantee its download-free early-return path.
     *
     * @param string     $workspace  Isolated synthetic project root
     * @param Filesystem $filesystem Test filesystem collaborator
     *
     * @return void
     */
    private function prepareCheckout(string $workspace, Filesystem $filesystem): void
    {
        $project = dirname(__DIR__, 2);
        $filesystem->copy($project . '/scripts/init-with-docker', $workspace . '/scripts/init-with-docker');
        $filesystem->copy($project . '/scripts/init', $workspace . '/scripts/init');
        $filesystem->copy($project . '/.env.dist', $workspace . '/.env.dist');
        $filesystem->dumpFile($workspace . '/bin/docker', <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            if [[ "${TEST_INIT_SCENARIO}" == docker-failure ]]; then exit 33; fi
            printf '%s:%s' "${USERID:-missing}" "${GROUPID:-missing}" > observed-builder-ids
            if [[ "${TEST_INIT_SCENARIO}" == already-initialized && "${!#}" == ./scripts/init ]]; then
                exec bash ./scripts/init
            fi
            SH);
        $filesystem->dumpFile($workspace . '/bin/gh', "#!/usr/bin/env bash\nexit 1\n");
        $filesystem->chmod([$workspace . '/bin/docker', $workspace . '/bin/gh'], 0o755);
    }

    /**
     * Starts the actual shell wrapper with a fake token or failing fake auth,
     * never querying host credentials. All script effects stay in the workspace.
     *
     * @param string $workspace Isolated checkout root
     * @param string $scenario  Controlled stub behavior
     *
     * @return Process Completed process with observed status and safe synthetic output
     */
    private function runInit(string $workspace, string $scenario): Process
    {
        $process = new Process(['bash', $workspace . '/scripts/init-with-docker'], $workspace, [
            'PATH'               => $workspace . '/bin:/usr/bin:/bin',
            'TEST_INIT_SCENARIO' => $scenario,
            'GITHUB_TOKEN'       => $scenario === 'auth-failure' ? false : 'synthetic-test-token',
        ]);
        $process->setTimeout(10);
        $process->run();

        return $process;
    }
}
