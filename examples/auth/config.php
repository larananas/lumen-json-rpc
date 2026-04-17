<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

return [
    'handlers' => [
        'paths' => [__DIR__ . '/handlers'],
        'namespace' => 'App\\Handlers\\AuthExample\\',
        'method_separator' => '.',
    ],
    'auth' => [
        'enabled' => true,
        'protected_methods' => ['user.'],
        'jwt' => [
            'secret' => AUTH_EXAMPLE_JWT_SECRET,
            'algorithm' => AUTH_EXAMPLE_JWT_ALGORITHM,
            'header' => 'Authorization',
            'prefix' => 'Bearer ',
            'issuer' => AUTH_EXAMPLE_JWT_ISSUER,
            'audience' => '',
            'leeway' => 0,
        ],
    ],
    'batch' => [
        'max_items' => 100,
    ],
    'limits' => [
        'max_body_size' => 1_048_576,
        'max_json_depth' => 64,
    ],
    'logging' => [
        'enabled' => true,
        'level' => 'debug',
        'path' => __DIR__ . '/../../logs/auth-example.log',
        'sanitize_secrets' => true,
    ],
    'log_rotation' => [
        'enabled' => false,
    ],
    'compression' => [
        'request_gzip' => true,
        'response_gzip' => false,
    ],
    'rate_limit' => [
        'enabled' => false,
    ],
    'debug' => true,
    'notifications' => [
        'enabled' => true,
        'log' => true,
    ],
    'response_fingerprint' => [
        'enabled' => false,
    ],
    'health' => [
        'enabled' => true,
    ],
    'validation' => [
        'strict' => true,
    ],
    'content_type' => [
        'strict' => false,
    ],
    'hooks' => [
        'enabled' => true,
    ],
    'server' => [
        'version' => '1.0.0',
        'name' => 'Lumen JSON-RPC Auth Example',
    ],
];
