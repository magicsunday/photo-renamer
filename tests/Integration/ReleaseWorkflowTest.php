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
use function trim;

/**
 * Exercises the real release helper against disposable Git repositories and a
 * local bare remote. Only Make and controlled push failures are substituted;
 * commits, index state and remote refs are observed through real Git. No actual
 * project release, credentials or remote publication can be reached.
 */
#[CoversNothing]
final class ReleaseWorkflowTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Dirty worktrees, foreign staged files and existing local/remote release
     * tags must fail before changing version, invoking quality gates or pushing.
     * Preserve the entire observed Git status, not just the version contents.
     *
     * @param string $scenario Unsafe initial state supplied by the provider
     *
     * @return void
     */
    #[Test]
    #[DataProvider('unsafeInitialStates')]
    public function unsafeInitialStatePreventsEveryReleaseMutation(string $scenario): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();

        try {
            $this->prepareRepository($workspace, $filesystem);
            $repository = $workspace . '/repository';

            if (($scenario === 'unstaged') || ($scenario === 'staged')) {
                $filesystem->dumpFile($repository . '/foreign.txt', 'foreign retained change');

                if ($scenario === 'staged') {
                    $this->git($repository, ['add', 'foreign.txt']);
                }
            } elseif ($scenario === 'untracked') {
                $filesystem->dumpFile($repository . '/untracked.txt', 'foreign retained file');
            } else {
                $this->git($repository, ['tag', '1.1.0']);

                if ($scenario === 'remote-tag') {
                    $this->git($repository, ['push', 'origin', 'refs/tags/1.1.0']);
                    $this->git($repository, ['tag', '-d', '1.1.0']);
                }
            }

            $head    = $this->git($repository, ['rev-parse', 'HEAD']);
            $status  = $this->git($repository, ['status', '--porcelain=v1', '--untracked-files=all']);
            $process = $this->release($workspace);
            self::assertNotSame(0, $process->getExitCode());
            self::assertSame("1.0.0\n", $filesystem->readFile($repository . '/version'));
            self::assertSame($head, $this->git($repository, ['rev-parse', 'HEAD']));
            self::assertSame($status, $this->git($repository, ['status', '--porcelain=v1', '--untracked-files=all']));
            self::assertFileDoesNotExist($workspace . '/state/make-arguments');
            self::assertFileDoesNotExist($workspace . '/state/push-arguments');
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * @return iterable<string, array{string}> Independently unsafe index, worktree and tag states
     */
    public static function unsafeInitialStates(): iterable
    {
        foreach (['unstaged', 'staged', 'untracked', 'local-tag', 'remote-tag'] as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    /**
     * A failed gate or an altered post-gate index/version must prevent release
     * commit, tag and push. The helper retains only its proposed version change
     * and any explicitly introduced foreign change instead of resetting data.
     *
     * @param string $scenario Controlled quality-gate failure or unexpected side effect
     *
     * @return void
     */
    #[Test]
    #[DataProvider('unsafeQualityResults')]
    public function unsuccessfulOrChangedQualityStateCannotBeReleased(string $scenario): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();

        try {
            $this->prepareRepository($workspace, $filesystem);
            $repository = $workspace . '/repository';
            $head       = $this->git($repository, ['rev-parse', 'HEAD']);
            $process    = $this->release($workspace, $scenario);
            self::assertNotSame(0, $process->getExitCode());
            self::assertFileExists($workspace . '/state/make-arguments');
            self::assertSame("test\n", $filesystem->readFile($workspace . '/state/make-arguments'));
            self::assertSame("1.1.0\n", $filesystem->readFile($workspace . '/state/tested-version'));
            self::assertSame($head, $this->git($repository, ['rev-parse', 'HEAD']));
            self::assertSame('', $this->git($repository, ['tag', '--list', '1.1.0']));
            self::assertFileDoesNotExist($workspace . '/state/push-arguments');
            self::assertSame($head, $this->git($workspace . '/remote.git', ['rev-parse', 'refs/heads/main']));
            self::assertStringContainsString('No release commit, tag or push', $process->getOutput() . $process->getErrorOutput());

            if ($scenario === 'staged-by-make') {
                self::assertSame('foreign gate change', $filesystem->readFile($repository . '/foreign.txt'));
                self::assertSame('foreign.txt', $this->git($repository, ['diff', '--cached', '--name-only']));
            }
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * @return iterable<string, array{string}> Failed gate, foreign staged file and changed version after the gate
     */
    public static function unsafeQualityResults(): iterable
    {
        foreach (['make-failure', 'staged-by-make', 'version-by-make'] as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    /**
     * Success must test the proposed version, commit only that tracked file with
     * a valid imperative subject and DCO, and publish exactly branch and new tag
     * atomically. An unrelated local tag must never appear at the bare remote.
     *
     * @return void
     */
    #[Test]
    public function successfulReleasePublishesOnlyTheTestedVersionAndExactRefs(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();

        try {
            $this->prepareRepository($workspace, $filesystem);
            $repository = $workspace . '/repository';
            $this->git($repository, ['tag', 'keep-local']);
            $process = $this->release($workspace);
            self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            self::assertFileExists($workspace . '/state/make-arguments');
            self::assertSame("test\n", $filesystem->readFile($workspace . '/state/make-arguments'));
            self::assertSame("1.1.0\n", $filesystem->readFile($workspace . '/state/tested-version'));
            self::assertSame('Release version 1.1.0', $this->git($repository, ['log', '-1', '--format=%s']));
            self::assertStringContainsString('Signed-off-by: Release Fixture <release@example.invalid>', $this->git($repository, ['log', '-1', '--format=%b']));
            self::assertSame('version', $this->git($repository, ['diff', '--name-only', 'HEAD~1', 'HEAD']));
            self::assertSame('1.1.0', $this->git($repository, ['show', 'HEAD:version']));
            self::assertSame('', $this->git($repository, ['status', '--porcelain=v1']));
            $head = $this->git($repository, ['rev-parse', 'HEAD']);
            self::assertSame($head, $this->git($workspace . '/remote.git', ['rev-parse', 'refs/heads/main']));
            self::assertSame($head, $this->git($workspace . '/remote.git', ['rev-parse', 'refs/tags/1.1.0']));
            self::assertSame('', $this->git($workspace . '/remote.git', ['tag', '--list', 'keep-local']));
            self::assertSame('--atomic -- origin ' . $head . ':refs/heads/main ' . $head . ":refs/tags/1.1.0\n", $filesystem->readFile($workspace . '/state/push-arguments'));
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * Transport/server/unsupported-atomic failures must fail visibly and retain
     * the local release for inspection, without retrying separate refs. Rejected
     * atomic pushes leave both remote refs unchanged; a simulated lost server
     * acknowledgement deliberately updates both refs yet still requires remote
     * inspection instead of falsely claiming no publication occurred.
     *
     * @param string $scenario Controlled transport or actual server rejection
     *
     * @return void
     */
    #[Test]
    #[DataProvider('publishFailures')]
    public function failedPublicationIsExplicitAndRetainsLocalRelease(string $scenario): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();

        try {
            $this->prepareRepository($workspace, $filesystem);
            $repository = $workspace . '/repository';
            $head       = $this->git($repository, ['rev-parse', 'HEAD']);

            if ($scenario === 'remote-rejection') {
                $filesystem->dumpFile($workspace . '/remote.git/hooks/pre-receive', "#!/usr/bin/env bash\nexit 1\n");
                $filesystem->chmod($workspace . '/remote.git/hooks/pre-receive', 0o755);
            }

            $process = $this->release($workspace, $scenario);
            self::assertNotSame(0, $process->getExitCode());
            self::assertSame('Release version 1.1.0', $this->git($repository, ['log', '-1', '--format=%s']));
            self::assertSame($this->git($repository, ['rev-parse', 'HEAD']), $this->git($repository, ['rev-parse', 'refs/tags/1.1.0']));
            $remoteHead = $this->git($workspace . '/remote.git', ['rev-parse', 'refs/heads/main']);

            if ($scenario === 'push-response-lost') {
                self::assertSame($this->git($repository, ['rev-parse', 'HEAD']), $remoteHead);
                self::assertSame($remoteHead, $this->git($workspace . '/remote.git', ['rev-parse', 'refs/tags/1.1.0']));
                self::assertStringContainsString('inspect remote refs before retrying', $process->getOutput() . $process->getErrorOutput());
            } else {
                self::assertSame($head, $remoteHead);
                self::assertSame('', $this->git($workspace . '/remote.git', ['tag', '--list', '1.1.0']));
            }

            $releaseCommit = $this->git($repository, ['rev-parse', 'HEAD']);
            self::assertSame('--atomic -- origin ' . $releaseCommit . ':refs/heads/main ' . $releaseCommit . ":refs/tags/1.1.0\n", $filesystem->readFile($workspace . '/state/push-arguments'));
            self::assertStringContainsString('Atomic publication failed', $process->getOutput() . $process->getErrorOutput());
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * @return iterable<string, array{string}> Transport, server, unsupported atomicity and lost-acknowledgement failures
     */
    public static function publishFailures(): iterable
    {
        yield 'transport' => ['push-failure'];
        yield 'remote rejection' => ['remote-rejection'];
        yield 'unsupported atomic push' => ['push-unsupported'];
        yield 'lost server acknowledgement' => ['push-response-lost'];
    }

    /**
     * Builds an entirely synthetic clean repository with configured local
     * upstream, identity and ignored test-state files outside the checkout.
     * Stubs record Make/push arguments; every other Git operation stays real.
     *
     * @param string     $workspace  Disposable workspace root
     * @param Filesystem $filesystem Test filesystem collaborator
     *
     * @return void
     */
    private function prepareRepository(string $workspace, Filesystem $filesystem): void
    {
        $repository = $workspace . '/repository';
        $filesystem->mkdir([$repository, $workspace . '/bin', $workspace . '/state']);
        $filesystem->copy(dirname(__DIR__, 2) . '/scripts/create-version', $repository . '/scripts/create-version');
        $filesystem->dumpFile($repository . '/version', "1.0.0\n");
        $filesystem->dumpFile($repository . '/foreign.txt', 'original retained bytes');
        $this->git($repository, ['init', '-b', 'main']);
        $this->git($repository, ['config', 'user.name', 'Release Fixture']);
        $this->git($repository, ['config', 'user.email', 'release@example.invalid']);
        $this->git($repository, ['add', '.']);
        $this->git($repository, ['commit', '-m', 'Initialize synthetic release fixture']);
        $this->git($workspace, ['init', '--bare', $workspace . '/remote.git']);
        $this->git($repository, ['remote', 'add', 'origin', $workspace . '/remote.git']);
        $this->git($repository, ['push', '--set-upstream', 'origin', 'main']);
        $filesystem->dumpFile($workspace . '/bin/make', <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf '%s\n' "$*" > "${RELEASE_TEST_STATE}/make-arguments"
            cat version > "${RELEASE_TEST_STATE}/tested-version"
            case "${RELEASE_TEST_SCENARIO}" in
                make-failure) exit 17 ;;
                staged-by-make) printf '%s' 'foreign gate change' > foreign.txt; git add foreign.txt ;;
                version-by-make) printf '1.9.9\n' > version ;;
            esac
            SH);
        $filesystem->dumpFile($workspace . '/bin/git', <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            if [[ "${1:-}" == push ]]; then
                printf '%s\n' "${*:2}" >> "${RELEASE_TEST_STATE}/push-arguments"
                if [[ "${RELEASE_TEST_SCENARIO}" == push-failure ]]; then exit 44; fi
                if [[ "${RELEASE_TEST_SCENARIO}" == push-unsupported ]]; then exit 129; fi
                if [[ "${RELEASE_TEST_SCENARIO}" == push-response-lost ]]; then
                    /usr/bin/git "$@"
                    exit 44
                fi
            fi
            exec /usr/bin/git "$@"
            SH);
        $filesystem->chmod([$workspace . '/bin/make', $workspace . '/bin/git'], 0o755);
    }

    /**
     * Runs real Git without global configuration, prompts or inherited hooks.
     * Repository-local identity is synthetic and applies only to these fixtures.
     *
     * @param string       $directory Working repository or workspace
     * @param list<string> $arguments Git operation and literal arguments
     *
     * @return string Trimmed stdout of a successful real Git operation
     */
    private function git(string $directory, array $arguments): string
    {
        $process = new Process(['git', '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', ...$arguments], $directory, ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0']);
        $process->setTimeout(15);
        $process->mustRun();

        return trim($process->getOutput());
    }

    /**
     * Starts the actual interactive helper with stub Make/controlled Git push,
     * deterministic synthetic version and no inherited Git authentication.
     *
     * @param string $workspace Disposable workspace root
     * @param string $scenario  Controlled quality/push behavior
     *
     * @return Process Completed helper with captured status and diagnostics
     */
    private function release(string $workspace, string $scenario = 'success'): Process
    {
        $process = new Process(['bash', $workspace . '/repository/scripts/create-version'], $workspace . '/repository', [
            'PATH'                  => $workspace . '/bin:/usr/bin:/bin',
            'RELEASE_TEST_STATE'    => $workspace . '/state',
            'RELEASE_TEST_SCENARIO' => $scenario,
            'GIT_CONFIG_GLOBAL'     => '/dev/null',
            'GIT_CONFIG_SYSTEM'     => '/dev/null',
            'GIT_TERMINAL_PROMPT'   => '0',
        ]);
        $process->setInput("1.1.0\n");
        $process->setTimeout(30);
        $process->run();

        return $process;
    }
}
