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
use function str_repeat;

/**
 * Exercises bootstrap integrity before any SPC command in an isolated checkout.
 * A harmless executable fixture reports execution through a marker then exits;
 * fake Curl supplies the same bytes without network access. Expected digests
 * belong to fixture-local versioned configuration, never caller environment.
 */
#[CoversNothing]
final class SpcIntegrityTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * A digest mismatch must block a newly downloaded or cached executable
     * before doctor, downloads or compilation. Exit failure alone is inadequate:
     * the marker proves that no command in the unverified tool was executed.
     *
     * @param bool $cached      Whether the unverified executable already exists
     * @param bool $environment Whether caller-controlled digests try to override the pinned fixture configuration
     *
     * @return void
     */
    #[Test]
    #[DataProvider('unverifiedSources')]
    public function incorrectDigestPreventsEverySpcExecution(bool $cached, bool $environment): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $fixture    = "#!/usr/bin/env bash\nprintf 'executed' > \"\${SPC_FIXTURE_STATE}/executed\"\nexit 73\n";

        try {
            $filesystem->copy(dirname(__DIR__, 2) . '/scripts/init', $workspace . '/scripts/init');
            $filesystem->copy(dirname(__DIR__, 2) . '/scripts/ensure-spc.sh', $workspace . '/scripts/ensure-spc.sh');
            $filesystem->mkdir([$workspace . '/bin', $workspace . '/state', $workspace . '/.build/bin']);
            $filesystem->dumpFile($workspace . '/unverified-spc', $fixture);
            $filesystem->dumpFile($workspace . '/config/build-inputs.env', "SPC_VERSION=2.8.5\nSPC_ARCHIVE_SHA256=" . str_repeat('0', 64) . "\nSPC_BINARY_SHA256=" . str_repeat('0', 64) . "\n");
            $filesystem->dumpFile($workspace . '/bin/curl', <<<'SH'
                #!/usr/bin/env bash
                set -euo pipefail
                destination=
                while [[ $# -gt 0 ]]; do
                    if [[ "$1" == -o || "$1" == --output ]]; then destination="$2"; shift; fi
                    shift
                done
                cp "${SPC_FIXTURE_ROOT}/unverified-spc" "${destination}"
                SH);
            $filesystem->chmod([$workspace . '/unverified-spc', $workspace . '/bin/curl'], 0o755);

            if ($cached) {
                $filesystem->copy($workspace . '/unverified-spc', $workspace . '/.build/bin/spc');
            }

            $process = new Process(['bash', $workspace . '/scripts/init'], $workspace, [
                'PATH'               => $workspace . '/bin:/usr/bin:/bin',
                'SPC_FIXTURE_ROOT'   => $workspace,
                'SPC_FIXTURE_STATE'  => $workspace . '/state',
                'GITHUB_TOKEN'       => false,
                'GH_TOKEN'           => false,
                'COMPOSER_AUTH'      => false,
                'SPC_ARCHIVE_SHA256' => $environment ? hash('sha256', $fixture) : false,
                'SPC_BINARY_SHA256'  => $environment ? hash('sha256', $fixture) : false,
            ]);
            $process->setTimeout(15);
            $process->run();
            self::assertNotSame(0, $process->getExitCode());
            self::assertFileDoesNotExist($workspace . '/state/executed');
            self::assertStringContainsString('checksum', $process->getOutput() . $process->getErrorOutput());
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * The trusted cached fixture completes doctor/download and deliberately
     * stops at compile. Download may use synthetic credentials, while doctor
     * and compile must observe all three credentials as entirely unset. Record
     * only booleans so failed assertions cannot print secret values.
     *
     * @param bool $validSource Whether the expected PHP source hash matches the downloaded fixture
     *
     * @return void
     */
    #[Test]
    #[DataProvider('phpSourceOutcomes')]
    public function compilationDoesNotInheritDownloadCredentials(bool $validSource): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $fixture    = <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            case "$1" in
                doctor|download|build)
                    present=0
                    if [[ -n "${GITHUB_TOKEN+x}" || -n "${GH_TOKEN+x}" || -n "${COMPOSER_AUTH+x}" ]]; then present=1; fi
                    printf '%s:%s\n' "$1" "${present}" >> "${SPC_FIXTURE_STATE}/stages"
                    if [[ "$1" == download ]]; then
                        mkdir -p downloads
                        printf 'synthetic PHP source' > downloads/php-8.5.11.tar.xz
                    fi
                    ;;
            esac
            if [[ "$1" == build ]]; then exit 79; fi
            SH;

        try {
            $project = dirname(__DIR__, 2);
            $filesystem->copy($project . '/scripts/init', $workspace . '/scripts/init');
            $filesystem->copy($project . '/scripts/ensure-spc.sh', $workspace . '/scripts/ensure-spc.sh');
            $filesystem->mkdir($workspace . '/state');
            $filesystem->dumpFile($workspace . '/.build/bin/spc', $fixture);
            $filesystem->chmod($workspace . '/.build/bin/spc', 0o755);
            $filesystem->dumpFile($workspace . '/config/build-inputs.env', "SPC_VERSION=2.8.5\nSPC_ARCHIVE_SHA256=" . str_repeat('0', 64) . "\nSPC_BINARY_SHA256=" . hash('sha256', $fixture) . "\nPHP_BUILD_VERSION=8.5.11\nPHP_SOURCE_SHA256=" . ($validSource ? hash('sha256', 'synthetic PHP source') : str_repeat('0', 64)) . "\n");
            $process = new Process(['bash', $workspace . '/scripts/init'], $workspace, [
                'SPC_FIXTURE_STATE' => $workspace . '/state',
                'GITHUB_TOKEN'      => 'synthetic-download-token',
                'GH_TOKEN'          => 'synthetic-download-token',
                'COMPOSER_AUTH'     => 'synthetic-download-auth',
            ]);
            $process->setTimeout(15);
            $process->run();
            self::assertSame($validSource ? 79 : 1, $process->getExitCode());
            self::assertSame($validSource ? "doctor:0\ndownload:1\nbuild:0\n" : "doctor:0\ndownload:1\n", $filesystem->readFile($workspace . '/state/stages'));
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * @return iterable<string, array{bool}> Matching source reaches a token-free build; mismatching source never executes compile
     */
    public static function phpSourceOutcomes(): iterable
    {
        yield 'verified PHP source' => [true];
        yield 'unverified PHP source' => [false];
    }

    /**
     * @return iterable<string, array{bool, bool}> Network/cached bytes with and without caller-controlled digest overrides
     */
    public static function unverifiedSources(): iterable
    {
        yield 'downloaded' => [false, false];
        yield 'cached' => [true, false];
        yield 'downloaded ENV override' => [false, true];
        yield 'cached ENV override' => [true, true];
    }
}
