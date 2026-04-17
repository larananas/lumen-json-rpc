<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Server\JsonRpcServer;

$config = new Config([
    'handlers' => [
        'paths' => [__DIR__ . '/../handlers'],
        'namespace' => 'App\\Handlers\\',
    ],
    'debug' => true,
    'logging' => [
        'enabled' => true,
        'level' => 'debug',
        'path' => __DIR__ . '/../../logs/app.log',
    ],
    'auth' => [
        'enabled' => false,
    ],
    'rate_limit' => [
        'enabled' => false,
    ],
]);

$server = new JsonRpcServer($config);
$server->run();
