<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer;

use Exception;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

use function fwrite;
use function getenv;
use function ini_parse_quantity;
use function ini_set;
use function is_string;
use function preg_match;

use const STDERR;

// A finite, configurable PHP budget complements native/container limits. Apply
// it before building the DI container; explicit unlimited/invalid values fail.
$configuredMemoryLimit = getenv('PHP_MEMORY_LIMIT');
$memoryLimit           = is_string($configuredMemoryLimit) && ($configuredMemoryLimit !== '')
    ? $configuredMemoryLimit
    : '1024M';

if ((preg_match('/\A[1-9][0-9]*[KMG]?\z/i', $memoryLimit) !== 1)
    || (ini_parse_quantity($memoryLimit) <= 0)
    || (ini_set('memory_limit', $memoryLimit) === false)
) {
    fwrite(STDERR, "PHP_MEMORY_LIMIT must be a positive finite byte quantity, optionally suffixed K, M or G, sufficient for CLI startup.\n");
    exit(1);
}

require_once __DIR__ . '/Dependencies.php';
require_once __DIR__ . '/../.build/cache/DependencyContainer.php';

// Create the container
$container = new DependencyContainer();

// Create and set the SymfonyStyle instance
$input  = new ArgvInput();
$output = new ConsoleOutput();
$io     = new SymfonyStyle($input, $output);
$container->set(SymfonyStyle::class, $io);

// Run the application
try {
    /** @var Application $application */
    $application = $container->get(Application::class);
    $result      = $application->run($input, $output);
} catch (Exception $exception) {
    $io->error($exception->getMessage());
    $result = 1;
}

exit($result);
