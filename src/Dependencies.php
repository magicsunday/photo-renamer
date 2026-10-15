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
use Phar;
use RuntimeException;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
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
 * is regenerated when Symfony's tracked YAML, class signatures or installed
 * dependency resources become stale. Freshness checking remains enabled even
 * outside debug runs; debug=false would accept every existing cache file.
 */
require_once __DIR__ . '/../.build/vendor/autoload.php';

$cachedContainer    = __DIR__ . '/../.build/cache/DependencyContainer.php';
$filesystem         = new Filesystem();
$configCache        = new ConfigCache($cachedContainer, true);
$isImmutableArchive = Phar::running() !== '';

// Only source builds create a compiled-container directory; archives are immutable.
if (!$isImmutableArchive) {
    $filesystem->mkdir(__DIR__ . '/../.build/cache');
}

// Check the actual framework resources rather than file existence alone.
// PHAR resources refer to build paths; its code/config/container form one
// immutable artifact and must never be recompiled inside the archive.
$cacheFresh = $isImmutableArchive ? $filesystem->exists($cachedContainer) : $configCache->isFresh();

if (!$cacheFresh) {
    if ($isImmutableArchive) {
        throw new RuntimeException('Packaged DI container is missing; rebuild the immutable archive.');
    }

    // Create and configure the container builder
    $containerBuilder = new ContainerBuilder();
    $containerBuilder->addResource(new FileResource(__FILE__));
    $installedPackages = __DIR__ . '/../.build/vendor/composer/installed.php';

    if ($filesystem->exists($installedPackages)) {
        $containerBuilder->addResource(new FileResource($installedPackages));
    }

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

    $configCache->write(
        $dumper->dump(
            [
                'class'     => 'DependencyContainer',
                'namespace' => 'MagicSunday\Renamer',
            ]
        ),
        $containerBuilder->getResources(),
    );
}
