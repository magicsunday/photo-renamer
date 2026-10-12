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
use function hash;
use function in_array;
use function posix_getegid;
use function posix_geteuid;
use function preg_replace;
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
     * A verified synthetic SPC tree exercises the real reuse gate; changed
     * build inputs or damaged output must fail instead of trusting marker-only
     * reuse. Controlled Docker/auth failures also preserve every original byte.
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

            if (in_array($scenario, ['already-initialized', 'corrupt-bootstrap', 'stale-bootstrap'], true)) {
                foreach (['.build/spc/buildroot/bin/micro.sfx', '.build/spc/buildroot/bin/php', '.build/bin/php'] as $marker) {
                    $filesystem->dumpFile($workspace . '/' . $marker, 'synthetic initialized marker');
                }

                $receipt = new Process(['bash', $workspace . '/scripts/bootstrap-state.sh', 'record'], $workspace);
                $receipt->mustRun();

                if ($scenario === 'corrupt-bootstrap') {
                    $filesystem->dumpFile($workspace . '/.build/spc/buildroot/bin/micro.sfx', 'corrupted synthetic output');
                } elseif ($scenario === 'stale-bootstrap') {
                    $filesystem->appendToFile($workspace . '/config/build-inputs.env', "# changed source input\n");
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
        yield 'damaged compiler output' => ['corrupt-bootstrap', 1];
        yield 'changed compiler input' => ['stale-bootstrap', 1];
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
        $filesystem->copy($project . '/scripts/ensure-spc.sh', $workspace . '/scripts/ensure-spc.sh');
        $filesystem->copy($project . '/scripts/bootstrap-state.sh', $workspace . '/scripts/bootstrap-state.sh');
        $filesystem->copy($project . '/scripts/native-inputs.sh', $workspace . '/scripts/native-inputs.sh');
        $filesystem->dumpFile($workspace . '/.build/spc/native-inputs.sha256', 'synthetic verified native inputs');
        $filesystem->copy($project . '/Dockerfile', $workspace . '/Dockerfile');
        // Only the verified fixture tool may satisfy the real init's reuse gate.
        $spcFixture = "#!/usr/bin/env bash\nexit 0\n";
        $inputs     = preg_replace('/^SPC_BINARY_SHA256=[0-9a-f]{64}$/m', 'SPC_BINARY_SHA256=' . hash('sha256', $spcFixture), $filesystem->readFile($project . '/config/build-inputs.env'));
        self::assertIsString($inputs);
        $filesystem->dumpFile($workspace . '/config/build-inputs.env', $inputs);
        $filesystem->dumpFile($workspace . '/.build/bin/spc', $spcFixture);
        $filesystem->chmod($workspace . '/.build/bin/spc', 0o755);
        $filesystem->copy($project . '/.env.dist', $workspace . '/.env.dist');
        $filesystem->dumpFile($workspace . '/bin/docker', <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            if [[ "${TEST_INIT_SCENARIO}" == docker-failure ]]; then exit 33; fi
            printf '%s:%s' "${USERID:-missing}" "${GROUPID:-missing}" > observed-builder-ids
            if [[ "${TEST_INIT_SCENARIO}" == already-initialized || "${TEST_INIT_SCENARIO}" == corrupt-bootstrap || "${TEST_INIT_SCENARIO}" == stale-bootstrap ]]; then
                for argument in "$@"; do
                    if [[ "${argument}" == ./scripts/init ]]; then exec bash ./scripts/init "${!#}"; fi
                done
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
