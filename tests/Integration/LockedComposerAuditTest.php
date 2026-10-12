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
use function is_array;
use function json_decode;
use function json_encode;
use function str_contains;
use function str_starts_with;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * Executes the actual Composer audit alias against a synthetic loopback
 * advisory provider. A deliberately different installed graph proves that
 * release security follows the shared lock, including development packages.
 */
#[CoversNothing]
final class LockedComposerAuditTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Lock and installed versions disagree in both directions. Vulnerable
     * runtime/dev lock entries must fail despite safe installed metadata; a safe
     * lock must pass despite vulnerable installed metadata. No public advisory
     * service, package download, original media or ambient token is involved.
     *
     * @param string $package          Synthetic runtime or development package
     * @param string $lockedVersion    Version represented by the application lock
     * @param string $installedVersion Different local installed package version
     * @param int    $expectedStatus   Expected real Composer audit result
     *
     * @return void
     */
    #[Test]
    #[DataProvider('lockedGraphs')]
    public function auditUsesLockedGraph(string $package, string $lockedVersion, string $installedVersion, int $expectedStatus): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $project    = dirname(__DIR__, 2);
        $server     = new Process([PHP_BINARY, $project . '/tests/Fixtures/advisory-repository.php'], $workspace);
        $server->setTimeout(15);

        try {
            $filesystem->dumpFile($workspace . '/requests.log', '');
            $server->start();
            $ready = '';
            self::assertTrue($server->waitUntil(static function (string $type, string $buffer) use (&$ready): bool {
                if ($type === Process::OUT) {
                    $ready .= $buffer;
                }

                return str_starts_with($ready, 'READY ') && str_contains($ready, "\n");
            }), $server->getErrorOutput());
            $url = 'http://' . trim(substr($ready, 6));

            $configuration = json_decode($filesystem->readFile($project . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue(is_array($configuration));
            self::assertIsArray($configuration['scripts']);
            self::assertIsArray($configuration['scripts']['ci:test:php:audit']);
            self::assertIsString($configuration['scripts']['ci:test:php:audit'][0]);
            $audit = $configuration['scripts']['ci:test:php:audit'][0];

            $filesystem->dumpFile($workspace . '/repository.json', json_encode([
                'packages'            => [],
                'metadata-url'        => $url . '/p2/%package%.json',
                'security-advisories' => ['api-url' => $url . '/advisories'],
            ], JSON_THROW_ON_ERROR));
            $filesystem->dumpFile($workspace . '/advisories.json', json_encode([
                'advisories' => [$package => [[
                    'advisoryId'       => 'PKSA-synthetic-audit',
                    'affectedVersions' => '<2.0.0',
                    'title'            => 'Synthetic fixture advisory',
                    'reportedAt'       => '2020-01-01T00:00:00+00:00',
                    'sources'          => [],
                    'severity'         => 'high',
                ]]],
            ], JSON_THROW_ON_ERROR));
            $filesystem->dumpFile($workspace . '/composer.json', json_encode([
                'name'         => 'fixture/audit-application',
                'version'      => '1.0.0',
                'require'      => ['fixture/runtime' => '*'],
                'require-dev'  => ['fixture/development' => '*'],
                'repositories' => [['type' => 'composer', 'url' => $url], ['packagist.org' => false]],
                'config'       => ['secure-http' => false, 'vendor-dir' => 'vendor'],
                'scripts'      => ['ci:test:php:audit' => [$audit]],
            ], JSON_THROW_ON_ERROR));
            $filesystem->dumpFile($workspace . '/composer.lock', json_encode([
                'packages'     => [['name' => 'fixture/runtime', 'version' => $package === 'fixture/runtime' ? $lockedVersion : '2.0.0', 'type' => 'library']],
                'packages-dev' => [['name' => 'fixture/development', 'version' => $package === 'fixture/development' ? $lockedVersion : '2.0.0', 'type' => 'library']],
            ], JSON_THROW_ON_ERROR));
            $filesystem->dumpFile($workspace . '/vendor/composer/installed.json', json_encode([
                'packages' => [[
                    'name'                => $package,
                    'version'             => $installedVersion,
                    'version_normalized'  => $installedVersion . '.0',
                    'type'                => 'library',
                    'installation-source' => 'dist',
                    'install-path'        => '../' . $package,
                ]],
                'dev'               => true,
                'dev-package-names' => $package === 'fixture/development' ? [$package] : [],
            ], JSON_THROW_ON_ERROR));
            $filesystem->mkdir($workspace . '/vendor/' . $package);

            $process = new Process(['composer', '--no-interaction', 'ci:test:php:audit'], $workspace, [
                'COMPOSER_HOME'      => $workspace . '/composer-home',
                'COMPOSER_CACHE_DIR' => $workspace . '/composer-cache',
                'COMPOSER_AUTH'      => false,
                'GITHUB_TOKEN'       => false,
                'GH_TOKEN'           => false,
            ]);
            $process->setTimeout(10);
            $process->run();
            $output = $process->getOutput() . $process->getErrorOutput();

            self::assertSame($expectedStatus, $process->getExitCode(), $output . $filesystem->readFile($workspace . '/requests.log'));
            self::assertStringNotContainsString('returned names which were not requested', $output);

            if ($expectedStatus === 0) {
                self::assertStringContainsString('No security vulnerability advisories found', $output);
            } else {
                self::assertStringContainsString('PKSA-synthetic-audit', $output);
                self::assertStringContainsString('Synthetic fixture advisory', $output);
            }
        } finally {
            $server->stop(0);
            $filesystem->remove($workspace);
        }
    }

    /**
     * Both package scopes fail on the locked vulnerability, while the inverted
     * graph is a positive control against an always-failing audit fixture.
     *
     * @return iterable<string, array{string, string, string, int}> Divergent lock/installed graphs
     */
    public static function lockedGraphs(): iterable
    {
        yield 'runtime lock vulnerable' => ['fixture/runtime', '1.0.0', '2.0.0', 1];
        yield 'development lock vulnerable' => ['fixture/development', '1.0.0', '2.0.0', 1];
        yield 'lock safe installed vulnerable' => ['fixture/runtime', '2.0.0', '1.0.0', 0];
        yield 'both graphs safe' => ['fixture/runtime', '2.0.0', '2.0.0', 0];
    }
}
