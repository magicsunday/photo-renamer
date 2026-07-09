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

/**
 * Dependency Injection Container setup and service registration for the renamer.
 *
 * This script initializes the Symfony Dependency Injection container, loads service
 * definitions from 'config/Services.yaml', and caches the compiled container to
 * improve startup performance in CLI environments.
 *
 * The cached container is stored in '.build/cache/DependencyContainer.php' and
 * is automatically regenerated whenever it is missing.
 */
require_once __DIR__ . '/../.build/vendor/autoload.php';

$cachedContainer = __DIR__ . '/../.build/cache/DependencyContainer.php';
$filesystem      = new Filesystem();

// Ensure the build cache directory exists for the compiled container
$filesystem->mkdir(__DIR__ . '/../.build/cache');

// Create a cached container if it doesn't exist (e.g., after clean or first run)
if (!$filesystem->exists($cachedContainer)) {
    // Create and configure the container builder
    $containerBuilder = new ContainerBuilder();

    // Load services from YAML configuration (Services.yaml)
    try {
        $yamlFileLoader = new YamlFileLoader($containerBuilder, new FileLocator(__DIR__ . '/../config'));
        $yamlFileLoader->load('Services.yaml');
    } catch (Exception $exception) {
        // Fail hard if configuration is broken to prevent inconsistent state
        throw new RuntimeException('Failed to load service configuration: ' . $exception->getMessage(), 0, $exception);
    }

    // Register SymfonyStyle as a synthetic service to allow passing the CLI IO
    $containerBuilder
        ->register(SymfonyStyle::class)
        ->setPublic(true)
        ->setSynthetic(true);

    // Compile the container to resolve dependencies and parameters
    $containerBuilder->compile();

    // Dump the compiled container to a PHP file for high-performance reuse
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
