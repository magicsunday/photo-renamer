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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function dirname;
use function str_repeat;

/**
 * Runs the real host orchestration scripts with a controlled Docker executable.
 * No host PHP/Composer/SPC fixture may run; the recorded Docker commands must
 * separate credentialed download from token-empty compile and package phases.
 */
#[CoversNothing]
final class BuildDockerBoundaryTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Docker orchestration must call three distinct container phases, forwarding
     * the synthetic token by variable name only for download, then explicitly
     * emptying every project download credential for compile and app packaging.
     * Fake host runtimes deliberately fail if the wrapper accidentally uses one.
     *
     * @return void
     */
    #[Test]
    public function binaryWrapperUsesSeparateDockerPhasesWithoutHostRuntimes(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();

        try {
            $project = dirname(__DIR__, 2);

            foreach (['build-with-docker.sh', 'init-with-docker', 'check-lock.sh'] as $script) {
                $filesystem->copy($project . '/scripts/' . $script, $workspace . '/scripts/' . $script);
            }

            $filesystem->copy($project . '/.env.dist', $workspace . '/.env.dist');
            $filesystem->dumpFile($workspace . '/composer.lock', "{}\n");
            $filesystem->mkdir($workspace . '/state');
            $filesystem->dumpFile($workspace . '/bin/docker', <<<'SH'
                #!/usr/bin/env bash
                set -euo pipefail
                phase=
                for argument in "$@"; do
                    case "${argument}" in download|compile) phase="${argument}" ;; scripts/build) phase=package ;; esac
                done
                if [[ -z "${phase}" ]]; then exit 81; fi
                if [[ "${phase}" == download ]]; then
                    [[ "${GITHUB_TOKEN:-}" == synthetic-download-token ]]
                    [[ " $* " == *' -e GITHUB_TOKEN '* ]]
                else
                    for key in GITHUB_TOKEN GH_TOKEN COMPOSER_AUTH; do
                        [[ " $* " == *" -e ${key}= "* ]]
                    done
                fi
                printf '%s\n' "${phase}" >> "${BUILD_FIXTURE_STATE}/phases"
                SH);

            foreach (['php', 'composer', 'spc'] as $name) {
                $filesystem->dumpFile($workspace . '/bin/' . $name, "#!/usr/bin/env bash\nprintf 'host runtime called' > \"\${BUILD_FIXTURE_STATE}/host-runtime\"\nexit 75\n");
                $filesystem->chmod($workspace . '/bin/' . $name, 0o755);
            }

            $filesystem->chmod($workspace . '/bin/docker', 0o755);
            $process = new Process(['bash', $workspace . '/scripts/build-with-docker.sh'], $workspace, [
                'PATH'                => $workspace . '/bin:/usr/bin:/bin',
                'BUILD_FIXTURE_STATE' => $workspace . '/state',
                'GITHUB_TOKEN'        => 'synthetic-download-token',
                'GH_TOKEN'            => 'synthetic-shadow-token',
                'COMPOSER_AUTH'       => 'synthetic-shadow-auth',
                'APP_REVISION'        => str_repeat('a', 40),
                'APP_SOURCE_DIRTY'    => '0',
            ]);
            $process->setTimeout(15);
            $process->run();
            self::assertSame(0, $process->getExitCode());
            self::assertSame("download\ncompile\npackage\n", $filesystem->readFile($workspace . '/state/phases'));
            self::assertFileDoesNotExist($workspace . '/state/host-runtime');
        } finally {
            $filesystem->remove($workspace);
        }
    }
}
