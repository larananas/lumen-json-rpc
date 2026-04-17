<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Server\JsonRpcServer;

$config = Config::fromFile(__DIR__ . '/../config.php');

$server = new JsonRpcServer($config);
$server->run();
