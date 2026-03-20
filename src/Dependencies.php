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
use RuntimeException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Filesystem\Filesystem;

require_once __DIR__ . '/../.build/vendor/autoload.php';

$cachedContainer = __DIR__ . '/../.build/cache/DependencyContainer.php';
$filesystem      = new Filesystem();

$filesystem->mkdir(__DIR__ . '/../.build/cache');

// Create a cached container if it doesn't exist
if (!$filesystem->exists($cachedContainer)) {
    // Create and configure the container
    $containerBuilder = new ContainerBuilder();

    // Load services from YAML configuration
    try {
        $yamlFileLoader = new YamlFileLoader($containerBuilder, new FileLocator(__DIR__ . '/../config'));
        $yamlFileLoader->load('Services.yaml');
    } catch (Exception $exception) {
        throw new RuntimeException('Failed to load service configuration: ' . $exception->getMessage(), 0, $exception);
    }

    // Register SymfonyStyle as a service
    $containerBuilder
        ->register(SymfonyStyle::class)
        ->setPublic(true)
        ->setSynthetic(true);

    // Compile the container
    $containerBuilder->compile();

    // Dump the container to a PHP file for caching
    $dumper = new PhpDumper($containerBuilder);

    $filesystem->dumpFile(
        $cachedContainer,
        $dumper->dump(
            [
                'class'     => 'DependencyContainer',
                'namespace' => 'MagicSunday\Renamer',
            ]
        ),
    );
}
