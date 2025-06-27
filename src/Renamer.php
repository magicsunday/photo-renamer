<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

require_once __DIR__ . '/Dependencies.php';
require_once __DIR__ . '/../var/cache/DependencyContainer.php';

// Create the container
$container = new DependencyContainer();

// Create and set the SymfonyStyle instance
$input  = new ArgvInput();
$output = new ConsoleOutput();
$io     = new SymfonyStyle($input, $output);
$container->set(SymfonyStyle::class, $io);

// Run the application

/** @var Application $application */
$application = $container->get(Application::class);
$result      = $application->run($input, $output);

exit($result);
