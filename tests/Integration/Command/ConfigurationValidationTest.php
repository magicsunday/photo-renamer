<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Integration\Command;

use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function dirname;
use function glob;
use function str_contains;
use function str_replace;
use function strtoupper;

use const PHP_BINARY;

/**
 * Uses the real CLI argument parser to verify valid filename options and that
 * configuration failures precede media actions, including confirmed runs.
 * All source content, cache paths and environment values are synthetic.
 */
#[CoversNothing]
final class ConfigurationValidationTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Short and long filename-pattern options must survive actual argv parsing,
     * preserve bytes during preview and rename only the selected source on execution.
     *
     * @param string $option       Short or long public filename-pattern option
     * @param bool   $singleFile   Select one file instead of its containing directory
     * @param bool   $relativePath Resolve the source relative to the child working directory
     *
     * @return void
     */
    #[Test]
    #[DataProvider('filenamePatternInputs')]
    public function filenamePatternOptionsWorkThroughRealCli(string $option, bool $singleFile, bool $relativePath): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $directory  = $workspace . '/Medien Grüße';
        $source     = $directory . '/source.jpg';
        $target     = $directory . '/2024_06_15.jpg';
        $fixture    = dirname(__DIR__, 2) . '/Fixtures/Images/01-basic-rename/IMG_1234.jpg';

        try {
            $filesystem->mkdir($directory);
            $filesystem->copy($fixture, $source);
            $bytes = $filesystem->readFile($source);

            if ($singleFile) {
                $filesystem->copy($fixture, $directory . '/neighbor.jpg');
            }

            $selected = $singleFile ? $source : $directory;

            if ($relativePath) {
                $selected = 'Medien Grüße' . ($singleFile ? '/source.jpg' : '');
            }

            foreach ([true, false] as $dryRun) {
                $arguments = [PHP_BINARY, dirname(__DIR__, 3) . '/src/Renamer.php', 'rename:exif', $option, 'Y_m_d', '--no-ansi', $selected];

                if ($dryRun) {
                    $arguments[] = '--dry-run';
                }

                $process = new Process($arguments, $workspace, ['CACHE_DIR' => $workspace . '/state', 'TIMEZONE' => 'UTC', 'MAX_DATE_DRIFT' => '0']);
                $process->setInput("yes\n");
                $process->setTimeout(30);
                $process->run();
                self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
                self::assertFileExists($dryRun ? $source : $target);
                self::assertFileDoesNotExist($dryRun ? $target : $source);
                self::assertSame($bytes, $filesystem->readFile($dryRun ? $source : $target));

                if ($singleFile) {
                    self::assertSame($bytes, $filesystem->readFile($directory . '/neighbor.jpg'));
                }
            }
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * @return iterable<string, array{string, bool, bool}> Public option spellings and source-path partitions
     */
    public static function filenamePatternInputs(): iterable
    {
        foreach (['-p', '--target-filename-pattern'] as $option) {
            foreach ([false, true] as $singleFile) {
                foreach ([false, true] as $relativePath) {
                    yield $option . ($singleFile ? ' file' : ' directory') . ($relativePath ? ' relative' : ' absolute') => [$option, $singleFile, $relativePath];
                }
            }
        }
    }

    /**
     * Malformed and out-of-range CLI/environment settings must fail visibly
     * before parsing/renaming the synthetic source. Any opened journal must have
     * no mutation intents; early validation can reject before a scope is opened.
     * The original name and bytes must survive unchanged.
     *
     * @param string $option  Configuration option whose boundary is verified
     * @param string $value   Invalid explicit value
     * @param bool   $fromCli Whether the selected value comes from CLI or environment
     *
     * @return void
     */
    #[Test]
    #[DataProvider('invalidSettings')]
    public function invalidConfigurationPreventsMediaMutation(string $option, string $value, bool $fromCli): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $source     = $workspace . '/media/synthetic-source.jpg';
        $state      = $workspace . '/state';
        $key        = strtoupper(str_replace('-', '_', $option));

        try {
            $filesystem->dumpFile($source, 'synthetic unchanged source bytes');
            $arguments   = [PHP_BINARY, dirname(__DIR__, 3) . '/src/Renamer.php', 'rename:exif', $workspace . '/media', '--no-ansi'];
            $environment = ['CACHE_DIR' => $state, 'TIMEZONE' => 'UTC', 'MAX_DATE_DRIFT' => '7', 'MERGE_THRESHOLD' => '0.06'];

            if ($fromCli) {
                $arguments[] = '--' . $option . '=' . $value;
            } else {
                $environment[$key] = $value;
            }

            $process = new Process($arguments, dirname(__DIR__, 3), $environment);
            $process->setInput("yes\n");
            $process->setTimeout(30);
            $process->run();
            $output = $process->getOutput() . $process->getErrorOutput();
            self::assertSame(1, $process->getExitCode());
            self::assertStringContainsString($key, $output);
            self::assertFalse(str_contains($output, '[OK]'));
            self::assertSame('synthetic unchanged source bytes', $filesystem->readFile($source));
            $remaining = glob($workspace . '/media/*');
            self::assertSame([$source], $remaining);
            $journals = glob(new PrivateCacheStorage($filesystem)->directory($state) . '/mutation-*.jsonl');
            self::assertIsArray($journals);

            foreach ($journals as $journal) {
                self::assertFalse(str_contains($filesystem->readFile($journal), '"event":"intent"'));
            }
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * @return iterable<string, array{string, string, bool}> Critical rejected settings supplied through each input boundary
     */
    public static function invalidSettings(): iterable
    {
        foreach ([['timezone', 'Invalid/Zone'], ['max-date-drift', 'abc'], ['max-date-drift', '-1'], ['merge-threshold', 'abc'], ['merge-threshold', '1.1'], ['merge-threshold', '1e9999']] as [$option, $value]) {
            foreach ([false, true] as $fromCli) {
                yield $option . ' ' . $value . ($fromCli ? ' CLI' : ' environment') => [$option, $value, $fromCli];
            }
        }
    }
}
