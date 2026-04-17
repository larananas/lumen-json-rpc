# Lumen JSON-RPC

<p align="center">
  <img src=".github/assets/logo.svg" alt="Lumen JSON-RPC logo" width="420">
</p>

<div align="center">
  
[![Tests](https://github.com/larananas/lumen-json-rpc/actions/workflows/ci.yml/badge.svg)](https://github.com/larananas/lumen-json-rpc/actions/workflows/ci.yml)
[![License: LGPL-3.0-or-later](https://img.shields.io/badge/license-LGPL--3.0--or--later-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-modern-777bb4.svg)](#)

</div>

> ✨ Framework-free JSON-RPC for PHP — strict `handler.method` routing, strong defaults, auth drivers, gzip, rate limiting, middleware, schema validation, direct core usage, and docs generation.

A production-grade, framework-free JSON-RPC server library for modern PHP.

It keeps the boring parts solid — request validation, batching, auth, compression, rate limiting, hooks, docs, and predictable handler execution — while keeping your application code explicit and reviewable.

---

## 🚀 Why this feels good in real projects

Lumen JSON-RPC is built for developers who want a **real server library**, not a vague protocol toolkit and not a heavy framework abstraction.

### You get, out of the box

- 🧱 **Standalone library** — plain PHP, no framework required
- 🎯 **Strict `handler.method` mapping** — predictable and easy to review
- 🔐 **Auth drivers built in** — JWT, API key, or HTTP Basic
- 🧩 **Direct core usage** — use HTTP by default, or call the engine directly
- 🧪 **Strong protocol handling** — strict request validation, batching, notifications
- 🛡️ **Safe defaults** — reserved methods blocked, magic methods excluded, public instance methods only
- 🗜️ **Compression + rate limiting** — useful production features without extra packages
- 🪝 **Hooks + middleware** — extension points without turning the library into a framework
- 📚 **Docs generation** — generate API docs from your handlers

### What it is trying to be

A clean JSON-RPC 2.0 server for PHP that stays:

- explicit
- composable
- easy to wire into a plain app
- strict enough to be trusted

### What it is **not** trying to be

- a full framework
- a giant DI container
- a magical procedure registry
- a “bring 12 packages before hello world” library

---

## 📦 Install

```bash
composer require larananas/lumen-json-rpc
```

### Optional extras

- `ext-zlib` → enables gzip request / response support
- `firebase/php-jwt` → enables broader JWT algorithm support

Without optional extras, the library still works.

---

## ⚡ Quick Start

### 1) Create an entry point (`public/index.php`)

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

### 2) Create a handler (`handlers/User.php`)

```php
<?php

declare(strict_types=1);

namespace App\Handlers;

use Lumen\JsonRpc\Support\RequestContext;

final class User
{
    public function get(RequestContext $context, int $id): array
    {
        return [
            'id' => $id,
            'name' => 'Example User',
            'requested_by' => $context->authUserId,
        ];
    }
}
```

### 3) Send a request

```bash
curl -X POST http://localhost:8000/ \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"user.get","params":{"id":1},"id":1}'
```

### 4) Response

```json
{"jsonrpc":"2.0","result":{"id":1,"name":"Example User","requested_by":null},"id":1}
```

---

## 🧭 The 30-second mental model

Methods follow the `handler.method` pattern:

| JSON-RPC Method | Handler Class         | Method     |
| --------------- | --------------------- | ---------- |
| `user.get`      | `handlers/User.php`   | `get()`    |
| `user.create`   | `handlers/User.php`   | `create()` |
| `system.health` | `handlers/System.php` | `health()` |

That means:

- `user.get` → handler class `User`
- method `get()` on that handler
- discovered from your configured handlers path + namespace

No manual method registry.
No hidden auto-generated procedures.
No “where is this route even defined?” nonsense.

---

## ✨ What you gain beyond basic JSON-RPC

### 🔐 Multiple auth drivers

You can protect method prefixes with:

- JWT *(default driver when auth is enabled)*
- API key
- HTTP Basic

```php
'auth' => [
    'enabled' => true,
    'driver' => 'jwt', // jwt | api_key | basic
    'protected_methods' => ['user.', 'order.'],
],
```

### 🧩 Direct core usage

HTTP is still the default, but you can also call the engine directly when you need transport-independent usage.

```php
<?php

use Lumen\JsonRpc\Support\RequestContext;

$context = new RequestContext(
    correlationId: 'demo-1',
    headers: [],
    clientIp: '127.0.0.1',
    requestBody: '{"jsonrpc":"2.0","method":"system.health","id":1}'
);

$json = $server->handleJson(
    '{"jsonrpc":"2.0","method":"system.health","id":1}',
    $context,
);

echo $json;
```

### 🏗️ Handler factory for lightweight DI

You can inject app services into handlers without forcing a framework container.

```php
<?php

use Lumen\JsonRpc\Dispatcher\HandlerFactoryInterface;
use Lumen\JsonRpc\Support\RequestContext;

$factory = new class($db) implements HandlerFactoryInterface {
    public function __construct(private DatabaseService $db) {}

    public function create(string $className, RequestContext $context): object
    {
        return new $className($this->db);
    }
};

$server->setHandlerFactory($factory);
```

### 🪝 Middleware pipeline

Run logic before / after each request without mixing it into handlers.

```php
<?php

use Lumen\JsonRpc\Middleware\MiddlewareInterface;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Protocol\Response;
use Lumen\JsonRpc\Support\RequestContext;

$server->addMiddleware(new class implements MiddlewareInterface {
    public function process(Request $request, RequestContext $context, callable $next): ?Response
    {
        error_log("[JSON-RPC] -> {$request->method}");
        $response = $next($request, $context);
        error_log('[JSON-RPC] <- done');
        return $response;
    }
});
```

### ✅ Optional schema validation

When you want more than simple type binding, a handler can provide a lightweight validation schema.

```php
<?php

use Lumen\JsonRpc\Validation\RpcSchemaProviderInterface;

final class Product implements RpcSchemaProviderInterface
{
    public static function rpcValidationSchemas(): array
    {
        return [
            'create' => [
                'type' => 'object',
                'required' => ['name', 'price'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1],
                    'price' => ['type' => 'number'],
                ],
                'additionalProperties' => false,
            ],
        ];
    }
}
```

Enable it with:

```php
'validation' => [
    'strict' => true,
    'schema' => ['enabled' => true],
],
```

If you do nothing, normal parameter binding still works exactly fine.

---

## 🆚 At a glance: how it compares

This is a **scope-level comparison** based on public docs and default library behavior.
It is intentionally simplified and focused on developer-facing features.

| Feature | Lumen JSON-RPC | UMA / `uma/json-rpc` | Datto ecosystem | `fguillot/json-rpc` |
|---|---:|---:|---:|---:|
| Framework-free server | 🟢 ✅ | 🟢 ✅ | 🟢 ✅ | 🟢 ✅ |
| HTTP support out of the box | 🟢 ✅ | 🔴 ❌ | 🟢 ✅ | 🟢 ✅ |
| Direct core usage without HTTP | 🟢 ✅ | 🟢 ✅ | 🟢 ✅ | 🔴 ❌ |
| Strict `handler.method` auto-discovery | 🟢 ✅ | 🔴 ❌ | 🔴 ❌ | 🔴 ❌ |
| Middleware pipeline | 🟢 ✅ | 🟢 ✅ | 🔴 ❌ | 🟢 ✅ |
| Optional advanced param validation | 🟢 ✅ | 🟢 ✅ | 🟡 ~ | 🔴 ❌ |
| JWT built in | 🟢 ✅ | 🔴 ❌ | 🟡 ~ | 🔴 ❌ |
| API key built in | 🟢 ✅ | 🔴 ❌ | 🟡 ~ | 🔴 ❌ |
| Basic auth built in | 🟢 ✅ | 🔴 ❌ | 🟡 ~ | 🟢 ✅ |
| Rate limiting built in | 🟢 ✅ | 🔴 ❌ | 🔴 ❌ | 🔴 ❌ |
| Gzip support built in | 🟢 ✅ | 🔴 ❌ | 🔴 ❌ | 🔴 ❌ |
| Docs generation built in | 🟢 ✅ | 🔴 ❌ | 🔴 ❌ | 🔴 ❌ |
| No mandatory external Composer deps in production | 🟢 ✅ | 🔴 ❌ | 🟢 ✅ | 🟢 ✅ |

### Why this matters

Lumen JSON-RPC is opinionated in a very specific way:

- **stricter than the “just map whatever” style**
- **more complete than minimal protocol-only cores**
- **lighter than solutions that push you into container/schema stacks immediately**

If that trade-off matches how you like to build plain PHP backends, that is exactly where it shines.

---

## 🧠 Design decisions

These choices are intentional.
They are not accidental omissions.

### 1) Why `handler.method`?

Because it stays easy to reason about.

When you see `user.get`, you know where to look:

- handler `User`
- method `get()`
- in your configured handlers path

That keeps the execution path explicit, reviewable, and boring in a good way.

### 2) Why no manual method registry?

Because a second mapping layer becomes busywork fast.

A lot of JSON-RPC libraries let you manually register callbacks or procedure maps.
That can be useful in tiny demos, but in real apps it also means:

- more wiring to maintain
- more chances to forget an entry
- more distance between the request method and the actual PHP code

Lumen JSON-RPC chooses discovery + convention instead.
If the handler exists and the method is callable, the library can resolve it directly.

### 3) Why JWT by default when auth is enabled?

Because it is the most common modern default for API-style auth.

But “default” does **not** mean “forced”.

You can switch to:

- `api_key`
- `basic`

without changing the rest of the server model.

So the default is opinionated, but the library is still practical.

---

## 🛡️ Handler Safety

The resolver is intentionally strict:

- methods starting with `rpc.` are always rejected
- method names must match `handler.method`
- magic methods (`__construct`, `__call`, etc.) are blocked
- only **public instance methods declared on the concrete handler class** are callable
- static methods are excluded
- inherited methods are excluded
- internal framework/library methods are excluded

This keeps execution paths explicit and limits surprises.

---

## 🔐 Authentication

### Available drivers

- `jwt` *(default when auth is enabled)*
- `api_key`
- `basic`

### Example: JWT

```php
'auth' => [
    'enabled' => true,
    'driver' => 'jwt',
    'protected_methods' => ['user.', 'order.'],
    'jwt' => [
        'secret' => 'your-secret-key',
        'algorithm' => 'HS256',
        'header' => 'Authorization',
        'prefix' => 'Bearer ',
        'issuer' => '',
        'audience' => '',
        'leeway' => 0,
    ],
],
```

### Example: API key

```php
'auth' => [
    'enabled' => true,
    'driver' => 'api_key',
    'protected_methods' => ['user.'],
    'api_key' => [
        'header' => 'X-API-Key',
        'keys' => [
            'demo-key-123' => [
                'user_id' => 'service-name',
                'roles' => ['service'],
                'claims' => ['source' => 'api_key'],
            ],
        ],
    ],
],
```

### Example: Basic auth

```php
'auth' => [
    'enabled' => true,
    'driver' => 'basic',
    'protected_methods' => ['user.'],
    'basic' => [
        'users' => [
            'admin' => [
                'password' => 'secret',
                'user_id' => 'admin',
                'roles' => ['admin'],
            ],
        ],
    ],
],
```

### Access auth data in handlers

```php
public function me(RequestContext $context): array
{
    return [
        'id' => $context->authUserId,
        'roles' => $context->authRoles,
        'email' => $context->getClaim('email'),
    ];
}
```

See:

- [docs/authentication.md](docs/authentication.md)
- [examples/auth/](examples/auth/)

> **Apache note:** depending on your setup, you may need to forward the `Authorization` header explicitly. See the authentication docs and the auth example for a working `.htaccess` snippet.

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

- HTTP `429`
- JSON-RPC error `-32000`
- headers such as:
  - `X-RateLimit-Limit`
  - `X-RateLimit-Remaining`
  - `Retry-After`

Batch weight counts actual items received, and consumption is atomic.

---

## 🗜️ Compression

If `ext-zlib` is available:

- `compression.request_gzip: true` *(default)* accepts `Content-Encoding: gzip`
- `compression.response_gzip: true` sends gzipped responses when the client advertises support

If `ext-zlib` is not available, the library degrades cleanly.
It does not become uninstallable just because gzip is unavailable.

---

## 🧬 Response Fingerprinting

```php
'response_fingerprint' => ['enabled' => true, 'algorithm' => 'sha256'],
```

Successful single responses can include an `ETag` header.
Clients can then use `If-None-Match` for conditional requests:

- matching fingerprint → HTTP `304`
- applies to non-batch single requests only

---

## 🪝 Hooks and middleware

Hooks and middleware are complementary:

- **hooks** are great for lightweight lifecycle events
- **middleware** is better when you want to wrap request execution

### Hook flow

```text
BEFORE_REQUEST -> BEFORE_HANDLER -> [handler] -> AFTER_HANDLER -> ON_RESPONSE -> AFTER_REQUEST
ON_ERROR fires instead of AFTER_HANDLER on exception.
ON_AUTH_SUCCESS / ON_AUTH_FAILURE fire during authentication.
```

### Hook example

```php
$server->getHooks()->register(
    HookPoint::BEFORE_HANDLER,
    function (array $context) {
        return ['custom_data' => 'value'];
    }
);
```

---

## 🌐 Transport behavior

- `POST /` handles JSON-RPC requests
- empty POST body returns `-32600 Invalid Request`
- `GET /` returns a health/status JSON when `health.enabled` is `true`
- non-POST, non-GET methods return HTTP `405`
- set `content_type.strict: true` to require `application/json` on POST

---

## 🧠 Parameter binding

Parameters are type-checked and mapped to `-32602 Invalid params` for mismatches.

### Supported behavior

- wrong scalar types produce `-32602`
- missing required parameters produce `-32602`
- unknown named parameters produce `-32602`
- surplus positional parameters produce `-32602`
- optional parameters use their defaults when omitted
- both positional and named parameters are supported
- `int` to `float` coercion is allowed
- `RequestContext` is injected automatically when declared as the first method parameter

This keeps handler signatures clean without turning binding into magic.

---

## 📚 Documentation generation

Generate docs from handler metadata:

```bash
php bin/generate-docs.php --format=markdown --output=docs/api.md
php bin/generate-docs.php --format=html --output=docs/api.html
php bin/generate-docs.php --format=json --output=docs/api.json
```

---

## 🧪 Examples

### Basic example

A minimal server with handlers and no auth:

- [examples/basic/](examples/basic/)

### Auth example

Shows JWT auth with a working example app:

- [examples/auth/](examples/auth/)

### Advanced example

Shows:

- custom handler factory
- middleware
- schema validation

- [examples/advanced/](examples/advanced/)

### Browser demo

A tiny HTML page that lets you send raw JSON-RPC requests and inspect the raw response:

- [examples/browser-demo/](examples/browser-demo/)

---

## 🚨 Error codes

| Code   | Meaning                 | When                                                |
| ------ | ----------------------- | --------------------------------------------------- |
| -32700 | Parse error             | Invalid JSON                                        |
| -32600 | Invalid Request         | Malformed request, empty body, empty batch          |
| -32601 | Method not found        | Unknown or reserved method                          |
| -32602 | Invalid params          | Missing, wrong type, unknown, or surplus parameters |
| -32603 | Internal error          | Handler or middleware exception, serialization failure |
| -32000 | Rate limit exceeded     | Too many requests                                   |
| -32001 | Authentication required | Protected method without valid credentials          |
| -32099 | Custom server error     | Application-defined                                 |

### Error handling notes

- `JsonRpcException` subclasses are preserved with their original codes
- only unknown `Throwable` maps to `-32603`
- debug mode includes stack traces
- production mode strips them
- JSON serialization failures in batch responses are isolated per response

---

## ⚙️ Configuration

See [docs/configuration.md](docs/configuration.md) for the full configuration reference with all keys, defaults, and descriptions.

For architecture details and the request lifecycle, see [docs/architecture.md](docs/architecture.md).

For authentication-specific setup and integration notes, see [docs/authentication.md](docs/authentication.md).

---

## 🧪 Running tests

```bash
vendor/bin/phpunit
```

---

## 🔒 Security

Security-sensitive behavior includes:

- method execution restricted to public instance methods on the concrete handler class
- reserved `rpc.*` namespace blocked
- JWT algorithm confusion prevented (`alg` must match config exactly)
- server refuses to start with invalid auth driver configuration
- server refuses to start with auth enabled but invalid required auth config
- gzip bombs mitigated with size limits enforced before and after decompression
- log injection prevented (newlines escaped, context JSON-encoded)
- rate limiting uses atomic file locking with configurable fail-open / fail-closed behavior

See [docs/security.md](docs/security.md) for details.

---

## 📄 License

Lumen JSON-RPC is free software licensed under the **GNU Lesser General Public License, version 3 or any later version (`LGPL-3.0-or-later`)**.

> **In practical terms:** you can use this library in both open-source and proprietary applications. You can integrate it into your own codebase, extend it, subclass it, and build commercial or closed-source software on top of it without having to release your whole application under the LGPL.

The main condition is about the library itself:

- if you distribute a modified version of **Lumen JSON-RPC**,
- those modifications to the library must remain available under the LGPL.

This is an intentional choice: the goal is to keep the library easy to adopt in real-world PHP projects while ensuring that improvements to the core engine are contributed back when they are distributed.

For the exact legal terms, see the [LICENSE](LICENSE) file.

---

## ✉️ Contact

For licensing or project-related questions: [larananas.dev@proton.me](mailto:larananas.dev@proton.me)
