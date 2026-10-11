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
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function dirname;
use function filemtime;
use function var_export;

use const PHP_BINARY;

/**
 * Runs the real DI bootstrap in fresh child processes with a tiny disposable
 * service tree. This proves actual warm reuse and reconstruction, independently
 * of the parent process's already loaded classes or compiled production cache.
 */
#[CoversNothing]
final class DependencyCacheFreshnessTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Disposable application skeleton containing only synthetic configuration.
     */
    private string $workspace;

    /**
     * Copies the production bootstrap and points its vendor loader to the
     * installed dependencies without copying or mutating the real vendor tree.
     *
     * @return void
     */
    #[Override]
    protected function setUp(): void
    {
        $this->workspace = $this->createTempWorkspace('di-freshness-');
        $filesystem      = new Filesystem();
        $filesystem->mkdir([$this->workspace . '/src', $this->workspace . '/config', $this->workspace . '/.build/vendor']);
        $filesystem->copy(__DIR__ . '/../../src/Dependencies.php', $this->workspace . '/src/Dependencies.php');

        $autoload = dirname(__DIR__, 2) . '/.build/vendor/autoload.php';
        $filesystem->dumpFile($this->workspace . '/.build/vendor/autoload.php', '<?php declare(strict_types=1); require ' . var_export($autoload, true) . ';');
        $filesystem->dumpFile($this->workspace . '/run.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            require __DIR__ . '/src/Probe.php';
            require __DIR__ . '/src/Dependencies.php';
            require __DIR__ . '/.build/cache/DependencyContainer.php';
            echo (new \MagicSunday\Renamer\DependencyContainer())->get(\CacheFixture\Probe::class)->value();
            PHP);
        $filesystem->dumpFile($this->workspace . '/src/Probe.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace CacheFixture;
            /** Synthetic scalar service used only in disposable child processes. */
            final readonly class Probe {
                /**
                 * Stores a configured value so stale compiled arguments are observable.
                 * @param string $marker Synthetic scalar dependency
                 */
                public function __construct(private string $marker = 'first') {}
                /**
                 * Returns the configured probe value for the parent test.
                 * @return string Value resolved by the actual compiled container
                 */
                public function value(): string { return $this->marker; }
            }
            PHP);
        $filesystem->dumpFile($this->workspace . '/config/Services.yaml', "services:\n  CacheFixture\\Probe:\n    autowire: true\n    public: true\n    arguments: ['first']\n");
    }

    /**
     * Removes the synthetic skeleton and its cache only.
     *
     * @return void
     */
    #[Override]
    protected function tearDown(): void
    {
        $this->removeWorkspace($this->workspace);
    }

    /**
     * Loads twice with unchanged resources and then changes a configured scalar
     * argument. Real container contents must change after the warm snapshot;
     * controlled cache timestamps avoid sleeps and filesystem clock granularity.
     *
     * @return void
     */
    #[Test]
    public function changedServiceConfigurationRebuildsAfterWarmReuse(): void
    {
        self::assertSame('first', $this->runContainer());
        $filesystem = new Filesystem();
        $filesystem->appendToFile($this->cachePath(), "\n// Synthetic witness: unchanged resources must reuse this cache.\n");

        $original = $filesystem->readFile($this->cachePath());
        self::assertSame('first', $this->runContainer());
        self::assertSame($original, $filesystem->readFile($this->cachePath()));
        $filesystem->dumpFile($this->workspace . '/config/Services.yaml', "services:\n  CacheFixture\\Probe:\n    autowire: true\n    public: true\n    arguments: ['second']\n");
        $sourceMtime = filemtime($this->workspace . '/config/Services.yaml');
        self::assertIsInt($sourceMtime);
        $filesystem->touch($this->cachePath(), $sourceMtime - 1);
        self::assertSame('second', $this->runContainer());
        self::assertNotSame($original, $filesystem->readFile($this->cachePath()));
    }

    /**
     * Changes a service constructor after compilation, adding a required typed
     * collaborator already declared in YAML. A stale container invokes it with
     * too few arguments; a resource-aware rebuild autowires the new dependency.
     *
     * @return void
     */
    #[Test]
    public function changedConstructorIsRewiredWithoutYamlChanges(): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->workspace . '/config/Services.yaml', "services:\n  CacheFixture\\Probe:\n    autowire: true\n    public: true\n  DateTimeImmutable:\n    class: DateTimeImmutable\n    arguments: ['2024-01-15T10:30:00Z']\n");
        self::assertSame('first', $this->runContainer());
        $filesystem->dumpFile($this->workspace . '/src/Probe.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace CacheFixture;
            /** Synthetic service with a newly required constructor dependency. */
            final readonly class Probe {
                /**
                 * Accepts the collaborator introduced after the first compilation.
                 * @param \DateTimeImmutable $date Newly required synthetic dependency
                 */
                public function __construct(private \DateTimeImmutable $date) {}
                /**
                 * Exposes the resolved collaborator to prove real rewiring.
                 * @return string Synthetic date from the injected dependency
                 */
                public function value(): string { return $this->date->format('Y-m-d'); }
            }
            PHP);
        $sourceMtime = filemtime($this->workspace . '/src/Probe.php');
        self::assertIsInt($sourceMtime);
        $filesystem->touch($this->cachePath(), $sourceMtime - 1);
        self::assertSame('2024-01-15', $this->runContainer());
    }

    /**
     * Packs the already compiled synthetic application into a real PHAR and
     * deletes its build tree. Resource metadata contains obsolete build paths,
     * but the archive's immutable code/config/container must remain executable
     * without attempting to rewrite a read-only packaged cache.
     *
     * @return void
     */
    #[Test]
    public function immutableArchiveUsesPackagedContainerWithoutItsBuildTree(): void
    {
        self::assertSame('first', $this->runContainer());
        $archiveWorkspace = $this->createTempWorkspace('immutable-di-archive-');
        $archive          = $archiveWorkspace . '/probe.phar';
        $filesystem       = new Filesystem();

        try {
            $pack = new Process([
                PHP_BINARY,
                '-d',
                'phar.readonly=0',
                '-r',
                '$archive = new Phar($argv[1]); $archive->buildFromDirectory($argv[2]); $archive->setStub(Phar::createDefaultStub("run.php"));',
                $archive,
                $this->workspace,
            ]);
            $pack->setTimeout(10.0);
            $pack->mustRun();
            $filesystem->remove($this->workspace);
            $run = new Process([PHP_BINARY, $archive]);
            $run->setTimeout(10.0);
            $run->mustRun();
            self::assertSame('first', $run->getOutput());
        } finally {
            $filesystem->remove($archiveWorkspace);
        }
    }

    /**
     * Starts a fresh PHP process using the same Docker interpreter as PHPUnit.
     * A finite timeout prevents broken bootstrap behavior hanging the suite.
     *
     * @return string Actual synthetic service output from the compiled container
     */
    private function runContainer(): string
    {
        $process = new Process([PHP_BINARY, $this->workspace . '/run.php']);
        $process->setTimeout(10.0);
        $process->mustRun();

        return $process->getOutput();
    }

    /**
     * Resolves the bootstrap's fixed compiled cache within the disposable tree.
     *
     * @return string Synthetic compiled-container pathname
     */
    private function cachePath(): string
    {
        return $this->workspace . '/.build/cache/DependencyContainer.php';
    }
}
