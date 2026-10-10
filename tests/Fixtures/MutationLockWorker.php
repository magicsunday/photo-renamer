<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use MagicSunday\Renamer\Application;
use MagicSunday\Renamer\Service\Filesystem\BatchRunLock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Filesystem;

use function fflush;
use function fgets;
use function fwrite;
use function getenv;
use function is_string;

require __DIR__ . '/../../.build/vendor/autoload.php';

// Announce lock acquisition from the actual application command body, then wait
// for parent input. Contention and forced termination never depend on a sleep.
$directory = getenv('RENAMER_LOCK_TEST_DIRECTORY');

if (!is_string($directory) || ($directory === '')) {
    throw new RuntimeException('The synthetic lock worker requires its disposable workspace.');
}

$command = new Command('rename:lower');
$command->setCode(static function (): int {
    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);
    fgets(STDIN);

    return Command::SUCCESS;
});
$application = new Application([$command], new BatchRunLock(new Filesystem(), $directory));
$application->setAutoExit(false);
exit($application->run(new ArrayInput(['command' => 'rename:lower']), new NullOutput()));
