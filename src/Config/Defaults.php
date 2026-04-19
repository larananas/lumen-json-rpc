<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Config;

/**
 * @internal
 */
final class Defaults
{
    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return [
            'handlers' => [
                'paths' => [__DIR__ . '/../../handlers'],
                'namespace' => 'App\\Handlers\\',
                'method_separator' => '.',
            ],
            'auth' => [
                'enabled' => false,
                'driver' => 'jwt',
                'protected_methods' => ['user.', 'order.'],
                'jwt' => [
                    'secret' => '',
                    'algorithm' => 'HS256',
                    'header' => 'Authorization',
                    'prefix' => 'Bearer ',
                    'issuer' => '',
                    'audience' => '',
                    'leeway' => 0,
                ],
                'api_key' => [
                    'header' => 'X-API-Key',
                    'keys' => [],
                ],
                'basic' => [
                    'header' => 'Authorization',
                    'users' => [],
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
                'level' => 'info',
                'path' => __DIR__ . '/../../logs/app.log',
                'format' => 'text',
                'sanitize_secrets' => true,
            ],
            'log_rotation' => [
                'enabled' => true,
                'max_size' => 10_485_760,
                'max_files' => 5,
                'compress' => true,
            ],
            'compression' => [
                'request_gzip' => true,
                'response_gzip' => false,
            ],
            'rate_limit' => [
                'enabled' => false,
                'max_requests' => 100,
                'window_seconds' => 60,
                'strategy' => 'ip',
                'storage_path' => __DIR__ . '/../../storage/rate_limit',
                'batch_weight' => 1,
                'fail_open' => true,
            ],
            'debug' => false,
            'notifications' => [
                'enabled' => true,
                'log' => true,
            ],
            'response_fingerprint' => [
                'enabled' => false,
                'algorithm' => 'sha256',
            ],

            'health' => [
                'enabled' => true,
            ],
            'validation' => [
                'strict' => true,
                'schema' => [
                    'enabled' => false,
                ],
            ],
            'content_type' => [
                'strict' => false,
            ],
            'hooks' => [
                'enabled' => true,
            ],
            'server' => [
                'version' => '1.0.0',
                'name' => 'Lumen JSON-RPC',
            ],
        ];
    }
}
