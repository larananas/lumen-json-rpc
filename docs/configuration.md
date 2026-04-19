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
```

## Section Details

### `handlers`

| Key                | Default             | Description                                                       |
| ------------------ | ------------------- | ----------------------------------------------------------------- |
| `paths`            | `[]`                | Array of directories containing handler PHP files                 |
| `namespace`        | `'App\\Handlers\\'` | PSR-4 namespace prefix for handler classes                        |
| `method_separator` | `'.'`               | Separator between handler name and method name (e.g., `user.get`) |

### `auth`

| Key                 | Default           | Description                                                                        |
| ------------------- | ----------------- | ---------------------------------------------------------------------------------- |
| `enabled`           | `false`           | Enable authentication                                                              |
| `driver`            | `'jwt'`           | Auth driver: `jwt`, `api_key`, or `basic`                                          |
| `protected_methods` | `[]`              | Array of method prefixes that require authentication (e.g., `['user.', 'order.']`) |
| `jwt.secret`        | `''`              | JWT signing secret (required when driver is `jwt`)                                 |
| `jwt.algorithm`     | `'HS256'`         | JWT algorithm: `HS256`, `HS384`, or `HS512`                                        |
| `jwt.header`        | `'Authorization'` | HTTP header to read the token from                                                 |
| `jwt.prefix`        | `'Bearer '`       | Token prefix in the header value                                                   |
| `jwt.issuer`        | `''`              | Expected `iss` claim (empty = not validated)                                       |
| `jwt.audience`      | `''`              | Expected `aud` claim (empty = not validated, supports string and array)            |
| `jwt.leeway`        | `0`               | Clock skew tolerance in seconds (applied to `exp`, `nbf`, `iat`)                   |
| `api_key.header`    | `'X-API-Key'`     | HTTP header for API key                                                            |
| `api_key.keys`      | `[]`              | Map of valid API keys to user config (`user_id`, `roles`, `claims`)                |
| `basic.header`      | `'Authorization'` | HTTP header for Basic auth                                                         |
| `basic.users`       | `[]`              | Map of usernames to config (`password`, `user_id`, `roles`, `claims`)              |

The built-in JWT decoder supports HMAC algorithms only. Install `firebase/php-jwt` if you need additional algorithm support.

### `batch`

| Key         | Default | Description                           |
| ----------- | ------- | ------------------------------------- |
| `max_items` | `100`   | Maximum number of requests in a batch |

When a batch request exceeds `max_items`, the server returns a single `-32600 Invalid Request` error with a `data` field indicating the maximum allowed.

**Batch behavior summary:**

- Empty batch (`[]`): returns `-32600 Invalid Request`
- Batch under limit: processed normally
- Batch at limit exactly: processed normally
- Batch over limit: returns single `-32600` error for the entire batch
- Batch of only notifications (no `id` fields): returns HTTP 204, no body
- Mixed batch with notifications: only non-notification responses are returned

### `limits`

| Key              | Default   | Description                                                                  |
| ---------------- | --------- | ---------------------------------------------------------------------------- |
| `max_body_size`  | `1048576` | Maximum request body size in bytes (enforced before and after decompression) |
| `max_json_depth` | `64`      | Maximum JSON nesting depth                                                   |

### `logging`

| Key                | Default          | Description                                                       |
| ------------------ | ---------------- | ----------------------------------------------------------------- |
| `enabled`          | `true`           | Enable structured logging                                         |
| `level`            | `'info'`         | Minimum log level: `debug`, `info`, `warning`, `error`            |
| `path`             | `'logs/app.log'` | Path to the log file                                              |
| `sanitize_secrets` | `true`           | Redact sensitive values (passwords, tokens, etc.) from log output |

### `log_rotation`

| Key         | Default    | Description                                                |
| ----------- | ---------- | ---------------------------------------------------------- |
| `enabled`   | `true`     | Enable automatic log rotation                              |
| `max_size`  | `10485760` | Maximum log file size in bytes before rotation             |
| `max_files` | `5`        | Maximum number of rotated log files to keep                |
| `compress`  | `true`     | Compress rotated log files with gzip (requires `ext-zlib`) |

### `compression`

| Key             | Default | Description                                                           |
| --------------- | ------- | --------------------------------------------------------------------- |
| `request_gzip`  | `true`  | Accept gzip-encoded request bodies (`Content-Encoding: gzip`)         |
| `response_gzip` | `false` | Send gzip-encoded responses when client sends `Accept-Encoding: gzip` |

Gzip support requires the `zlib` extension (`ext-zlib`), which is an optional dependency. If the extension is not available, gzip features are gracefully disabled.

### `rate_limit`

| Key              | Default                | Description                                                  |
| ---------------- | ---------------------- | ------------------------------------------------------------ |
| `enabled`        | `false`                | Enable rate limiting                                         |
| `max_requests`   | `100`                  | Maximum requests per window                                  |
| `window_seconds` | `60`                   | Rate limit window in seconds                                 |
| `strategy`       | `'ip'`                 | Rate limit key strategy: `ip`, `user`, or `token`            |
| `storage_path`   | `'storage/rate_limit'` | Directory for rate limit storage files                       |
| `batch_weight`   | `1`                    | Each request in a batch counts as N hits                     |
| `fail_open`      | `true`                 | Allow requests on storage failure (`true`) or deny (`false`) |

When rate-limited, the server returns HTTP 429 with a `-32000` JSON-RPC error and `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After` headers.

#### Custom rate limit backends

The rate limiter backend is pluggable via `RateLimiterInterface`. To use a custom backend:

```php
use Lumen\JsonRpc\RateLimit\RateLimiterInterface;
use Lumen\JsonRpc\RateLimit\RateLimitResult;

class RedisRateLimiter implements RateLimiterInterface
{
    public function check(string $key): RateLimitResult
    {
        return $this->checkAndConsume($key, 1);
    }

    public function checkAndConsume(string $key, int $weight): RateLimitResult
    {
        // Your Redis-backed implementation
    }
}

$server->getEngine()->getRateLimitManager()->setLimiter(new RedisRateLimiter(...));
```

An `InMemoryRateLimiter` is included for testing and single-process use cases.

### `debug`

| Key     | Default | Description                                                  |
| ------- | ------- | ------------------------------------------------------------ |
| `debug` | `false` | Include stack traces and internal details in error responses |

### `notifications`

| Key       | Default | Description                                               |
| --------- | ------- | --------------------------------------------------------- |
| `enabled` | `true`  | Process JSON-RPC notifications (requests without an `id`) |
| `log`     | `true`  | Log processed notifications                               |

### `response_fingerprint`

| Key         | Default    | Description                                                           |
| ----------- | ---------- | --------------------------------------------------------------------- |
| `enabled`   | `false`    | Include `ETag` header on successful responses                         |
| `algorithm` | `'sha256'` | Hash algorithm for fingerprinting (any algorithm from `hash_algos()`) |

Clients can use `If-None-Match` for conditional requests. A matching ETag returns HTTP 304 (non-batch single requests only).

### `health`

| Key       | Default | Description                            |
| --------- | ------- | -------------------------------------- |
| `enabled` | `true`  | Return a health JSON object on `GET /` |

### `validation`

| Key              | Default | Description                                                                        |
| ---------------- | ------- | ---------------------------------------------------------------------------------- |
| `strict`         | `true`  | Reject requests with unknown members. Set `false` for lenient mode.                |
| `schema.enabled` | `false` | Enable advanced parameter validation via `RpcSchemaProviderInterface` on handlers. |

When `schema.enabled` is `true`, handlers implementing `RpcSchemaProviderInterface` will have their parameters validated against declared schemas before invocation. Errors produce a `-32602 Invalid params` response.

### `content_type`

| Key      | Default | Description                                              |
| -------- | ------- | -------------------------------------------------------- |
| `strict` | `false` | Require `application/json` Content-Type on POST requests |

### `hooks`

| Key       | Default | Description                        |
| --------- | ------- | ---------------------------------- |
| `enabled` | `true`  | Enable hook/extension point system |

### `server`

| Key       | Default            | Description                                                       |
| --------- | ------------------ | ----------------------------------------------------------------- |
| `version` | `'1.0.0'`          | Server version (returned by `system.version` and health endpoint) |
| `name`    | `'Lumen JSON-RPC'` | Server name (returned in health endpoint)                         |
