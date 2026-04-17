# Configuration Reference

Configuration is passed as a PHP array to `Config`, or loaded from a file via `Config::fromFile()`.

```php
$config = Config::fromFile('config.php');
// or
$config = new Config([ /* overrides */ ]);
```

`Config::fromFile()` throws `RuntimeException` if the file is missing or returns non-array data. Unspecified keys fall back to defaults.

---

## Full Configuration

```php
return [
    'handlers' => [
        'paths' => [__DIR__ . '/../handlers'],
        'namespace' => 'App\\Handlers\\',
        'method_separator' => '.',
    ],
    'auth' => [
        'enabled' => false,
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
    ],
    'batch' => [
        'max_items' => 100,
    ],
    'limits' => [
        'max_body_size' => 1048576,
        'max_json_depth' => 64,
    ],
    'logging' => [
        'enabled' => true,
        'level' => 'info',
        'path' => __DIR__ . '/../logs/app.log',
        'sanitize_secrets' => true,
    ],
    'log_rotation' => [
        'enabled' => true,
        'max_size' => 10485760,
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
        'storage_path' => __DIR__ . '/../storage/rate_limit',
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
```

## Section Details

### `handlers`

| Key | Default | Description |
|---|---|---|
| `paths` | `[]` | Array of directories containing handler PHP files |
| `namespace` | `'App\\Handlers\\'` | PSR-4 namespace prefix for handler classes |
| `method_separator` | `'.'` | Separator between handler name and method name (e.g., `user.get`) |

### `auth`

| Key | Default | Description |
|---|---|---|
| `enabled` | `false` | Enable JWT authentication |
| `protected_methods` | `[]` | Array of method prefixes that require authentication (e.g., `['user.', 'order.']`) |
| `jwt.secret` | `''` | JWT signing secret (required when auth is enabled) |
| `jwt.algorithm` | `'HS256'` | JWT algorithm: `HS256`, `HS384`, or `HS512` |
| `jwt.header` | `'Authorization'` | HTTP header to read the token from |
| `jwt.prefix` | `'Bearer '` | Token prefix in the header value |
| `jwt.issuer` | `''` | Expected `iss` claim (empty = not validated) |
| `jwt.audience` | `''` | Expected `aud` claim (empty = not validated, supports string and array) |
| `jwt.leeway` | `0` | Clock skew tolerance in seconds (applied to `exp`, `nbf`, `iat`) |

The built-in JWT decoder supports HMAC algorithms only. Install `firebase/php-jwt` if you need additional algorithm support.

### `batch`

| Key | Default | Description |
|---|---|---|
| `max_items` | `100` | Maximum number of requests in a batch |

### `limits`

| Key | Default | Description |
|---|---|---|
| `max_body_size` | `1048576` | Maximum request body size in bytes (enforced before and after decompression) |
| `max_json_depth` | `64` | Maximum JSON nesting depth |

### `logging`

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Enable structured logging |
| `level` | `'info'` | Minimum log level: `debug`, `info`, `warning`, `error` |
| `path` | `'logs/app.log'` | Path to the log file |
| `sanitize_secrets` | `true` | Redact sensitive values (passwords, tokens, etc.) from log output |

### `log_rotation`

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Enable automatic log rotation |
| `max_size` | `10485760` | Maximum log file size in bytes before rotation |
| `max_files` | `5` | Maximum number of rotated log files to keep |
| `compress` | `true` | Compress rotated log files with gzip (requires `ext-zlib`) |

### `compression`

| Key | Default | Description |
|---|---|---|
| `request_gzip` | `true` | Accept gzip-encoded request bodies (`Content-Encoding: gzip`) |
| `response_gzip` | `false` | Send gzip-encoded responses when client sends `Accept-Encoding: gzip` |

Gzip support requires the `zlib` extension (`ext-zlib`), declared as a Composer requirement.

### `rate_limit`

| Key | Default | Description |
|---|---|---|
| `enabled` | `false` | Enable rate limiting |
| `max_requests` | `100` | Maximum requests per window |
| `window_seconds` | `60` | Rate limit window in seconds |
| `strategy` | `'ip'` | Rate limit key strategy: `ip`, `user`, or `token` |
| `storage_path` | `'storage/rate_limit'` | Directory for rate limit storage files |
| `batch_weight` | `1` | Each request in a batch counts as N hits |
| `fail_open` | `true` | Allow requests on storage failure (`true`) or deny (`false`) |

When rate-limited, the server returns HTTP 429 with a `-32000` JSON-RPC error and `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After` headers.

### `debug`

| Key | Default | Description |
|---|---|---|
| `debug` | `false` | Include stack traces and internal details in error responses |

### `notifications`

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Process JSON-RPC notifications (requests without an `id`) |
| `log` | `true` | Log processed notifications |

### `response_fingerprint`

| Key | Default | Description |
|---|---|---|
| `enabled` | `false` | Include `ETag` header on successful responses |
| `algorithm` | `'sha256'` | Hash algorithm for fingerprinting (any algorithm from `hash_algos()`) |

Clients can use `If-None-Match` for conditional requests. A matching ETag returns HTTP 304 (non-batch single requests only).

### `health`

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Return a health JSON object on `GET /` |

### `validation`

| Key | Default | Description |
|---|---|---|
| `strict` | `true` | Reject requests with unknown members. Set `false` for lenient mode. |

### `content_type`

| Key | Default | Description |
|---|---|---|
| `strict` | `false` | Require `application/json` Content-Type on POST requests |

### `hooks`

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Enable hook/extension point system |

### `server`

| Key | Default | Description |
|---|---|---|
| `version` | `'1.0.0'` | Server version (returned by `system.version` and health endpoint) |
| `name` | `'Lumen JSON-RPC'` | Server name (returned in health endpoint) |
