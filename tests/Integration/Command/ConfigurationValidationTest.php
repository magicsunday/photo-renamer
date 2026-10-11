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
 * Uses the real mutating application to prove configuration failures occur
 * before media actions, even after confirmation and source-identity capture.
 * All source content, cache paths and environment values are synthetic.
 */
#[CoversNothing]
final class ConfigurationValidationTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Malformed and out-of-range CLI/environment settings must fail visibly
     * before parsing/renaming the synthetic source. Check the real journal has
     * no mutation intents and the original name and bytes survive unchanged.
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
            self::assertFalse(str_contains($output, 'done'));
            self::assertSame('synthetic unchanged source bytes', $filesystem->readFile($source));
            $remaining = glob($workspace . '/media/*');
            self::assertSame([$source], $remaining);
            $journals = glob(new PrivateCacheStorage($filesystem)->directory($state) . '/mutation-*.jsonl');
            self::assertIsArray($journals);
            self::assertCount(1, $journals);
            self::assertFalse(str_contains($filesystem->readFile($journals[0]), '"event":"intent"'));
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
