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
use function hash;
use function json_decode;
use function str_contains;
use function str_repeat;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * Uses the actual manifest writer and installed dependencies to verify stable
 * public provenance, exact lock identity and omission of credentials/workspace
 * locations. Production package selection is additionally checked in Docker.
 */
#[CoversNothing]
final class BuildManifestTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Repeated writes of identical public inputs must produce identical bytes.
     * A synthetic secret is present in the child environment but must not be
     * recorded, nor may the manifest serialize its absolute checkout directory.
     *
     * @return void
     */
    #[Test]
    public function manifestRecordsStablePublicInputsWithoutEnvironmentOrPaths(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $project    = dirname(__DIR__, 2);
        $revision   = str_repeat('a', 40);

        try {
            foreach (['first', 'second'] as $name) {
                $process = new Process([PHP_BINARY, $project . '/scripts/write-build-manifest.php', $workspace . '/' . $name . '.json', $revision, '0', 'runtime'], $project, ['SYNTHETIC_PRIVATE_VALUE' => 'synthetic-secret-must-never-be-published']);
                $process->setTimeout(30);
                $process->mustRun();
            }

            $first = $filesystem->readFile($workspace . '/first.json');
            self::assertTrue($first === $filesystem->readFile($workspace . '/second.json'));
            self::assertFalse(str_contains($first, 'synthetic-secret-must-never-be-published'));
            self::assertFalse(str_contains($first, $project));
            self::assertFalse(str_contains($first, $workspace));
            $manifest = json_decode($first, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($manifest);
            self::assertSame($revision, $manifest['revision']);
            self::assertFalse($manifest['workspace_modified']);
            self::assertSame(hash('sha256', $filesystem->readFile($project . '/composer.lock')), $manifest['composer_lock_sha256']);
            self::assertIsString($manifest['source_sha256']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $manifest['source_sha256']);
            self::assertIsArray($manifest['base_images']);
            self::assertCount(3, $manifest['base_images']);
            self::assertIsArray($manifest['packages']);
            self::assertNotEmpty($manifest['packages']);
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * An arbitrary non-commit revision must fail without producing misleading
     * provenance. The input is synthetic; avoid printing potentially injected
     * text or an entire environment when an assertion fails.
     *
     * @return void
     */
    #[Test]
    public function malformedRevisionFailsBeforeWritingManifest(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();

        try {
            $output  = $workspace . '/invalid.json';
            $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/scripts/write-build-manifest.php', $output, 'not-a-commit', '0', 'runtime']);
            $process->setTimeout(30);
            $process->run();
            self::assertNotSame(0, $process->getExitCode());
            self::assertFileDoesNotExist($output);
        } finally {
            $filesystem->remove($workspace);
        }
    }
}
