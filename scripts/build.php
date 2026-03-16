<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

$pharFile = dirname(__DIR__) . '/renamer.phar';
$buildDir = dirname(__DIR__) . '/.build/renamer';

// Build the DI container cache before packing
require_once $buildDir . '/src/Dependencies.php';

if (file_exists($pharFile)) {
    unlink($pharFile);
}

$phar = new Phar($pharFile);
$phar->startBuffering();

// Create the default stub
$defaultStub = Phar::createDefaultStub('src/Renamer.php');

$phar->buildFromDirectory($buildDir);
$phar->setStub("#!/usr/bin/env php \n" . $defaultStub);
$phar->stopBuffering();
$phar->compressFiles(Phar::GZ);

//  Make the file executable
chmod($pharFile, 0755);

echo basename($pharFile) . " successfully created" . PHP_EOL;
