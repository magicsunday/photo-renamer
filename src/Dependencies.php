<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer;

use RuntimeException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

use function sprintf;

require_once __DIR__ . '/../vendor/autoload.php';

$cachedContainer = __DIR__ . '/../var/cache/DependencyContainer.php';

if (!file_exists(__DIR__ . '/../var/cache')
    && !mkdir($concurrentDirectory = __DIR__ . '/../var/cache', 0775, true)
    && !is_dir($concurrentDirectory)
) {
    throw new RuntimeException(
        sprintf(
            'Directory "%s" was not created',
            $concurrentDirectory
        )
    );
}

if (!file_exists($cachedContainer)) {
    $containerBuilder = new ContainerBuilder();

    $yamlFileLoader = new YamlFileLoader($containerBuilder, new FileLocator(__DIR__ . '/../config'));
    $yamlFileLoader->load('Services.yaml');

    $containerBuilder->compile();

    $dumper = new PhpDumper($containerBuilder);

    file_put_contents(
        $cachedContainer,
        $dumper->dump(
            [
                'class'     => 'DependencyContainer',
                'namespace' => 'MagicSunday\Renamer',
            ]
        )
    );
}
