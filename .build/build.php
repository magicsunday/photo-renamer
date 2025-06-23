<?php

try
{
    $pharFile = "renamer.phar";
    $binName  = "renamer";

    if (file_exists($pharFile)) {
        unlink($pharFile);
    }

    if (file_exists($pharFile . ".gz")) {
        unlink($pharFile . ".gz");
    }

    require_once __DIR__ . '/renamer/src/Dependencies.php';

    $phar = new Phar($pharFile);
    $phar->startBuffering();

    // Create the default stub
    $defaultStub = $phar->createDefaultStub('src/Renamer.php');

    $phar->buildFromDirectory(__DIR__ . "/renamer");
    $phar->setStub("#!/usr/bin/env php \n" . $defaultStub);
    $phar->stopBuffering();
    $phar->compressFiles(Phar::GZ);

    //  Make the file executable
    chmod($pharFile, 0755);

    echo "$pharFile successfully created" . PHP_EOL;
}
catch (Exception $e)
{
    echo $e->getMessage();
}