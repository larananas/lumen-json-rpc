# Configuration Reference

Configuration is passed as a PHP array to `Config`, or loaded from a file via `Config::fromFile()`.

```php
$config = Config::fromFile('config.php');
// or
$config = new Config([ /* overrides */ ]);
```

`Config::fromFile()` throws `RuntimeException` if the file is missing or returns non-array data. Unspecified keys fall back to defaults.

---

## Merged Default Configuration

```php
return [
    'handlers' => [
        'paths' => [],
        'namespace' => 'App\\Handlers\\',
        'method_separator' => '.',
    ],
    'auth' => [
        'enabled' => false,
        'driver' => 'jwt',
        'protected_methods' => [],
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
        'path' => 'logs/app.log',
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
        'storage_path' => 'storage/rate_limit',
        'batch_weight' => 1,
        'fail_open' => false,
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
        'strict' => false,
        'schema' => [
            'enabled' => false,
        ],
    ],
    'content_type' => [
        'strict' => false,
    ],
    'hooks' => [
        'enabled' => true,
        'isolate_exceptions' => true,
    ],
    'server' => [
        'version' => '1.0.0',
        'name' => 'Lumen JSON-RPC',
        'url' => '',
    ],
];
```

These are the library's merged defaults. In real applications you will normally set `handlers.paths` explicitly and often override file-system paths such as `logging.path` or `rate_limit.storage_path` to match your deployment layout.

## Section Details

### `handlers`

| Key                | Default             | Description                                                       |
| ------------------ | ------------------- | ----------------------------------------------------------------- |
| `paths`            | `[]`                | Array of directories containing handler PHP files                 |
| `namespace`        | `'App\\Handlers\\'` | PSR-4 namespace prefix for handler classes                        |
| `method_separator` | `'.'`               | Separator between handler name and method name (e.g., `user.get`) |

Auto-discovery scans only the top-level PHP files inside each configured handler path. Nested subdirectories are ignored.

### `auth`

| Key                 | Default           | Description                                                                              |
| ------------------- | ----------------- | ---------------------------------------------------------------------------------------- |
| `enabled`           | `false`           | Enable authentication                                                                    |
| `driver`            | `'jwt'`           | Auth driver: `jwt`, `api_key`, or `basic`                                                |
| `protected_methods` | `[]`              | Array of exact method names or trailing-separator prefixes that require authentication   |
| `jwt.secret`        | `''`              | JWT signing secret (required when driver is `jwt`)                                       |
| `jwt.algorithm`     | `'HS256'`         | JWT algorithm: `HS256`, `HS384`, or `HS512`                                              |
| `jwt.header`        | `'Authorization'` | HTTP header to read the token from                                                       |
| `jwt.prefix`        | `'Bearer '`       | Token prefix in the header value                                                         |
| `jwt.issuer`        | `''`              | Expected `iss` claim (empty = not validated)                                             |
| `jwt.audience`      | `''`              | Expected `aud` claim (empty = not validated, supports string and array)                  |
| `jwt.leeway`        | `0`               | Clock skew tolerance in seconds (applied to `exp`, `nbf`, `iat`)                         |
| `api_key.header`    | `'X-API-Key'`     | HTTP header for API key                                                                  |
| `api_key.keys`      | `[]`              | Map of valid API keys to user config (`user_id`, `roles`, `claims`)                      |
| `basic.header`      | `'Authorization'` | HTTP header for Basic auth                                                               |
| `basic.users`       | `[]`              | Map of usernames to config (`password` or `password_hash`, `user_id`, `roles`, `claims`) |

The built-in JWT decoder supports HMAC algorithms only. Install `firebase/php-jwt` if you need additional algorithm support.

Use exact names like `user.get` to protect a single procedure, or prefixes ending in the method separator like `user.` to protect a whole handler namespace.

For Basic auth, prefer storing precomputed `password_hash` values. Plaintext `password` entries remain supported for development and test setups.

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
- Mixed valid/invalid batches are not guaranteed to preserve input order in the response array

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
| `fail_open`      | `false`                | Allow requests on storage failure (`true`) or deny (`false`) |

When rate-limited, the server returns HTTP 429 with a `-32000` JSON-RPC error and `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After` headers.

`fail_open` defaults to `false`, so the built-in file limiter denies requests when storage cannot be opened or locked. Set it to `true` only if your deployment explicitly prefers availability over strict limit enforcement during storage failures.

#### Custom rate limit backends

The rate limiter backend is pluggable via `RateLimiterInterface`. Use the stable `JsonRpcServer::setRateLimiter()` entry point to swap in a custom backend:

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

$server->setRateLimiter(new RedisRateLimiter(...));
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

Clients can use `If-None-Match` for conditional requests. A matching ETag returns HTTP 304 (non-batch single requests only). When response gzip negotiation is active, ETags are representation-specific and the conditional response preserves `Vary: Accept-Encoding` so caches keep the representation metadata aligned.

### `health`

| Key       | Default | Description                            |
| --------- | ------- | -------------------------------------- |
| `enabled` | `true`  | Return a health JSON object on `GET /` |

When `health.enabled` is `false`, `GET /` is not advertised in the `Allow` header on `405 Method Not Allowed` responses; only `POST` remains listed.

### `validation`

| Key              | Default | Description                                                                        |
| ---------------- | ------- | ---------------------------------------------------------------------------------- |
| `strict`         | `false` | Reject requests with unknown members. Set `true` for stricter request validation.  |
| `schema.enabled` | `false` | Enable advanced parameter validation via `RpcSchemaProviderInterface` on handlers. |

When `schema.enabled` is `true`, handlers implementing `RpcSchemaProviderInterface` will have their parameters validated against declared schemas before invocation. Errors produce a `-32602 Invalid params` response.

The built-in validator is intentionally a lightweight JSON Schema subset for runtime request validation, not a full JSON Schema implementation.

### `content_type`

| Key      | Default | Description                                              |
| -------- | ------- | -------------------------------------------------------- |
| `strict` | `false` | Require `application/json` Content-Type on POST requests |

HTTP status codes are used for transport-level outcomes only. JSON-RPC parse/protocol/application errors still return HTTP 200 with a JSON-RPC error body, while all-notification batches return 204, conditional fingerprint matches return 304, and rate-limited requests return 429.

### `hooks`

| Key                  | Default | Description                                                        |
| -------------------- | ------- | ------------------------------------------------------------------ |
| `enabled`            | `true`  | Enable hook/extension point system                                 |
| `isolate_exceptions` | `true`  | Log and suppress hook callback exceptions instead of aborting work |

Hook callbacks run inline with request processing. By default, a thrown hook exception is logged and suppressed so later hooks and request handling can continue. Set `hooks.isolate_exceptions` to `false` if you need fail-fast hook behavior.

### `server`

| Key       | Default            | Description                                                       |
| --------- | ------------------ | ----------------------------------------------------------------- |
| `version` | `'1.0.0'`          | Server version (returned by `system.version` and health endpoint) |
| `name`    | `'Lumen JSON-RPC'` | Server name (returned in health endpoint)                         |
| `url`     | `''`               | Optional server URL included in generated OpenRPC documents       |
