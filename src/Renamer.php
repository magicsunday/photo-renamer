<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer;

require_once __DIR__ . '/Dependencies.php';
require_once __DIR__ . '/../var/cache/DependencyContainer.php';

$containerBuilder = new DependencyContainer();
exit($containerBuilder->get(Application::class)->run());
