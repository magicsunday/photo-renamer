<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Fixtures;

use function fclose;
use function fgets;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function str_starts_with;
use function stream_get_contents;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strlen;
use function substr;
use function trim;

use const FILE_APPEND;

// Bind only loopback and publish the kernel-selected port in a READY handshake.
// Responses come exclusively from the parent test's synthetic repository data.
$listener = stream_socket_server('tcp://127.0.0.1:0');

if ($listener === false) {
    exit(70);
}

$address = stream_socket_get_name($listener, false);

if ($address === false) {
    exit(71);
}

fwrite(STDOUT, 'READY ' . $address . "\n");

while (($client = stream_socket_accept($listener, 10)) !== false) {
    $request = fgets($client);
    file_put_contents('requests.log', $request === false ? "missing request\n" : $request, FILE_APPEND);
    $length = 0;

    while (($header = fgets($client)) !== false) {
        if (trim($header) === '') {
            break;
        }

        if (str_starts_with($header, 'Content-Length: ')) {
            $length = (int) trim(substr($header, 16));
        }
    }

    if ($length > 0) {
        stream_get_contents($client, $length);
    }

    $root    = ($request !== false) && str_starts_with($request, 'GET /packages.json ');
    $payload = file_get_contents($root ? 'repository.json' : 'advisories.json');

    if ($payload === false) {
        exit(72);
    }

    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\nConnection: close\r\n\r\n" . $payload);
    fclose($client);
}

fclose($listener);
