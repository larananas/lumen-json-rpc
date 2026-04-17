# Lumen JSON-RPC

<p align="center">
  <img src=".github/assets/logo.svg" alt="Lumen JSON-RPC logo" width="420">
</p>

<div align="center">
  
[![Tests](https://github.com/larananas/lumen-json-rpc/actions/workflows/ci.yml/badge.svg)](https://github.com/larananas/lumen-json-rpc/actions/workflows/ci.yml)
[![License: LGPL-3.0-or-later](https://img.shields.io/badge/license-LGPL--3.0--or--later-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-modern-777bb4.svg)](#)

</div>

> ✨ Framework-free JSON-RPC for PHP — clean handlers, strong defaults, auth, gzip, rate limiting, hooks, and docs generation.

A production-grade, framework-free JSON-RPC server library for modern PHP.

It gives you a clean way to expose handlers you control, keep your code explicit, and still get the features you actually need in real projects: strict protocol handling, auth, gzip, batching, rate limiting, structured logging, hooks, and documentation generation.

---

## ✨ Why

You need a JSON-RPC 2.0 server that:

- 🧱 works as a standalone library
- 🎯 uses handlers you control, with zero magic
- 🔐 handles auth, rate limiting, logging, and batching out of the box
- ✅ ships with strict spec compliance and safe defaults
- 🛠️ stays explicit, reviewable, and easy to wire into a plain PHP app

---

## 📦 Install

```bash
composer require larananas/lumen-json-rpc
```

JWT auth works with the built-in HMAC decoder.
Optionally install `firebase/php-jwt` for broader algorithm support.

---

## 🚀 Quick Start

### 1. Create an entry point (`public/index.php`)

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Server\JsonRpcServer;

$config = new Config([
    'handlers' => [
        'paths' => [__DIR__ . '/../handlers'],
        'namespace' => 'App\\Handlers\\',
    ],
]);

$server = new JsonRpcServer($config);
$server->run();
```

### 2. Create a handler (`handlers/User.php`)

```php
<?php

declare(strict_types=1);

namespace App\Handlers;

use Lumen\JsonRpc\Support\RequestContext;

class User
{
    public function __construct(private RequestContext $context) {}

    /**
     * Get a user by ID.
     * @param int $id The user ID
     */
    public function get(RequestContext $context, int $id): array
    {
        return [
            'id' => $id,
            'name' => 'Example User',
        ];
    }
}
```

### 3. Send a request

```bash
curl -X POST http://localhost:8000/ \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"user.get","params":{"id":1},"id":1}'
```

### 4. Example response

```json
{"jsonrpc":"2.0","result":{"id":1,"name":"Example User"},"id":1}
```

---

## 🧭 Method Mapping

Methods follow the `handler.method` pattern *(configurable via `method_separator`)*:

| JSON-RPC Method | Handler Class         | Method     |
| --------------- | --------------------- | ---------- |
| `user.get`      | `handlers/User.php`   | `get()`    |
| `user.create`   | `handlers/User.php`   | `create()` |
| `system.health` | `handlers/System.php` | `health()` |

The handler class receives a `RequestContext` as its first constructor argument.
Methods that need context receive it as the first parameter.

Each request creates a **fresh handler instance**, preventing stale context leaks across requests.

---

## 🛡️ Handler Safety

The resolver is intentionally strict:

* Methods starting with `rpc.` are always rejected
  *(reserved by the JSON-RPC 2.0 spec, independent of the configured separator)*
* Method names must match `^[a-zA-Z][a-zA-Z0-9]*{sep}[a-zA-Z][a-zA-Z0-9_]*$`
* Magic methods (`__construct`, `__call`, etc.) are blocked
* Only public instance methods declared on the concrete handler class are callable
* Static methods, inherited methods, and internal framework methods are excluded

This keeps execution paths explicit, predictable, and reviewable.

---

## 🔐 Authentication

Enable JWT authentication:

```php
'auth' => [
    'enabled' => true,
    'protected_methods' => ['user.', 'order.'],
    'jwt' => [
        'secret' => 'your-secret-key',
        'algorithm' => 'HS256',
    ],
],
```

### Behavior

* Methods matching any prefix in `protected_methods` require a valid JWT
* Unauthenticated requests to protected methods receive `-32001 Authentication required`
* The `auth.jwt.header` config controls which HTTP header is checked *(default: `Authorization`)*
* The built-in decoder supports `HS256`, `HS384`, and `HS512`
* The `none` algorithm is always rejected
* Claims `exp`, `nbf`, `iat`, `iss`, and `aud` are validated when configured
* `leeway` helps with clock skew
* Install `firebase/php-jwt` for broader algorithm support

### Access auth context in handlers

```php
public function get(RequestContext $context, int $id): array
{
    $userId = $context->authUserId;
    $roles  = $context->authRoles;
    $email  = $context->getClaim('email');

    return [
        'id' => $id,
        'requested_by' => $userId,
    ];
}
```

---

## 🚦 Rate Limiting

File-based rate limiting with atomic file locking:

```php
'rate_limit' => [
    'enabled' => true,
    'max_requests' => 100,
    'window_seconds' => 60,
    'strategy' => 'ip',
    'fail_open' => true,
],
```

Rate-limited requests return:

* HTTP `429`
* JSON-RPC error `-32000`
* headers such as:

  * `X-RateLimit-Limit`
  * `X-RateLimit-Remaining`
  * `Retry-After`

Batch weight counts actual items received, and consumption is atomic.

---

## 🗜️ Compression

* `compression.request_gzip: true` *(default)* — accept `Content-Encoding: gzip`
  Size limits are enforced **before and after** decompression.
* `compression.response_gzip: true` — send gzip-encoded responses when the client sends `Accept-Encoding: gzip`.

---

## 🧬 Response Fingerprinting

```php
'response_fingerprint' => ['enabled' => true, 'algorithm' => 'sha256'],
```

Successful responses include an `ETag` header.

Clients can use `If-None-Match` for conditional requests:

* matching fingerprint → HTTP `304`
* applies to non-batch single requests only

---

## 🪝 Hooks / Extension Points

```text
BEFORE_REQUEST -> BEFORE_HANDLER -> [handler] -> AFTER_HANDLER -> ON_RESPONSE -> AFTER_REQUEST
ON_ERROR fires instead of AFTER_HANDLER on exception.
ON_AUTH_SUCCESS / ON_AUTH_FAILURE fire during authentication.
```

```php
$server->getHooks()->register(
    HookPoint::BEFORE_HANDLER,
    function (array $context) {
        return ['custom_data' => 'value'];
    }
);
```

GET health requests fire a reduced set:

* `BEFORE_REQUEST`
* `ON_RESPONSE`
* `AFTER_REQUEST`

with `health: true` in context.

---

## 🌐 Transport Behavior

* `POST /` handles JSON-RPC requests
* An empty POST body returns `-32600 Invalid Request`
* `GET /` returns a health/status JSON when `health.enabled` is `true`
* Non-POST, non-GET methods return HTTP `405`
* Set `content_type.strict: true` to require `application/json` on POST

---

## 🧠 Parameter Binding

Parameters are type-checked and mapped to `-32602 Invalid params` for mismatches.

### Supported behavior

* Wrong scalar types produce `-32602`
* Missing required parameters produce `-32602`
* Unknown named parameters produce `-32602`
* Surplus positional parameters produce `-32602`
* Optional parameters use their defaults when omitted
* Both positional and named parameters are supported
* `int` to `float` coercion is allowed

This keeps handler signatures clean without turning parameter binding into magic.

---

## 📚 Documentation Generation

Generate docs from handler metadata:

```bash
php bin/generate-docs.php --format=markdown --output=docs/api.md
php bin/generate-docs.php --format=html --output=docs/api.html
php bin/generate-docs.php --format=json --output=docs/api.json
```

---

## 🚨 Error Codes

| Code   | Meaning                 | When                                                |
| ------ | ----------------------- | --------------------------------------------------- |
| -32700 | Parse error             | Invalid JSON                                        |
| -32600 | Invalid Request         | Malformed request, empty body, empty batch          |
| -32601 | Method not found        | Unknown or reserved method                          |
| -32602 | Invalid params          | Missing, wrong type, unknown, or surplus parameters |
| -32603 | Internal error          | Handler exception, serialization failure            |
| -32000 | Rate limit exceeded     | Too many requests                                   |
| -32001 | Authentication required | Protected method without valid JWT                  |
| -32099 | Custom server error     | Application-defined                                 |

### Error handling notes

* `JsonRpcException` subclasses are preserved with their original codes
* Only unknown `Throwable` maps to `-32603`
* Debug mode includes stack traces
* Production mode strips them
* JSON serialization failures in batch responses are isolated per response:
  one broken response does not poison the rest of the batch

---

## ⚙️ Configuration

See [docs/configuration.md](docs/configuration.md) for the full configuration reference with all keys, defaults, and descriptions.

---

## 🧪 Running Tests

```bash
vendor/bin/phpunit
```

---

## 🔒 Security

Security-sensitive behavior includes:

* method execution restricted to public instance methods on the concrete handler class
* JWT algorithm confusion prevented (`alg` must match config exactly)
* server refuses to start with auth enabled but empty secret
* gzip zip bombs mitigated with size limits enforced before and after decompression
* log injection prevented (newlines escaped, context JSON-encoded)
* rate limiting uses atomic file locking with configurable fail-open / fail-closed behavior

See [docs/security.md](docs/security.md) for details.

---

## 📄 License

Lumen JSON-RPC is free software licensed under the **GNU Lesser General Public License, version 3 or any later version (`LGPL-3.0-or-later`)**.

> **In practical terms:** you can use this library in both open-source and proprietary applications. You can integrate it into your own codebase, extend it, subclass it, and build commercial or closed-source software on top of it without having to release your whole application under the LGPL.

The main condition is about the library itself:

* if you distribute a modified version of **Lumen JSON-RPC**,
* those modifications to the library must remain available under the LGPL.

This is an intentional choice: the goal is to keep the library easy to adopt in real-world PHP projects while ensuring that improvements to the core engine are contributed back when they are distributed.

For the exact legal terms, see the [LICENSE](LICENSE) file.

---

## ✉️ Contact

For licensing or project-related questions: [larananas.dev@proton.me](mailto:larananas.dev@proton.me)
