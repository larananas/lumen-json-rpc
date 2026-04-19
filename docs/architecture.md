# Architecture Notes

## Design Principles

1. **Protocol compliance first** - Core JSON-RPC 2.0 protocol fully implemented; HTTP transport has documented deviations (see Transport Behavior in README)
2. **Clean separation** - Each class has a single, focused responsibility
3. **No framework lock-in** - Pure PHP 8.x with minimal dependencies
4. **Safe defaults** - Security-conscious defaults (debug off, secrets sanitized, safe method resolution)
5. **Explicit over magic** - No dynamic method execution without validation

## Request Flow

```
HTTP Request
  -> JsonRpcServer (HTTP facade)
  -> RequestReader (body extraction, gzip decoding, size limit)
  -> JsonRpcEngine (core, transport-agnostic)
     -> HookManager (fire BEFORE_REQUEST)
     -> JSON Decode (with depth limit)
     -> BatchProcessor (detect single vs batch, validate structure, enforce batch.max_items)
     -> AuthManager + RequestAuthenticator (extract credentials, authenticate, build UserContext)
     -> RateLimitManager (check rate limits)
     -> For each request:
        -> MiddlewarePipeline (execute middleware stack)
           -> SchemaValidator (optional, if handler provides schemas)
           -> HookManager (fire BEFORE_HANDLER)
           -> MethodResolver (map method name to handler class+method)
           -> HandlerDispatcher + HandlerFactory (instantiate handler, invoke method)
           -> ParameterBinder (bind params to method arguments)
           -> HookManager (fire AFTER_HANDLER) -- fires inside handler execution
        -> Response building (success or error)
     -> HookManager (fire ON_RESPONSE)
  -> HttpResponse (JSON encode, optional ETag, optional gzip)
```

### Lifecycle Guarantees

The execution order is formalized and tested. The following guarantees hold for every request:

**Before middleware runs:**

- JSON has been decoded successfully (parse errors short-circuit before middleware)
- Request structure has been validated (invalid requests short-circuit before middleware)
- Batch detection has completed, batch limit has been enforced
- Authentication has been resolved (if enabled and headers are present)
- Rate limit has been checked (if enabled)
- `RequestContext` is available with correlation ID, headers, client IP, and auth state

**During middleware execution:**

- Middleware receives a fully parsed and validated `Request` object
- `RequestContext` contains all authentication information
- Middleware executes in registration order (outer first, inner last)
- Each middleware can short-circuit by returning a `Response` without calling `$next()`
- `BEFORE_HANDLER` hook fires before the first middleware's `$next()` call
- `AFTER_HANDLER` hook fires after handler execution, before the middleware's post-processing

**After middleware completes:**

- Handler has been invoked (unless middleware short-circuited)
- Response is a valid JSON-RPC `Response` object or `null` (for notifications)

**Hook execution order (per request):**

```
BEFORE_REQUEST -> [auth hooks] -> [rate limit] -> BEFORE_HANDLER -> [middleware wraps:] -> AFTER_HANDLER -> ON_RESPONSE -> AFTER_REQUEST
```

**What is NOT guaranteed before middleware:**

- Method resolution has NOT occurred yet (method may still be not found)
- Parameter binding has NOT occurred yet (params may be invalid)
- Schema validation has NOT occurred yet (even if enabled)

## Component Responsibilities

### Core (`src/Core/`)

- `JsonRpcEngine`: Transport-agnostic JSON-RPC processing engine (decode, dispatch, auth, rate limit, middleware, hooks)
- `EngineResult`: Value object for engine output (JSON string or null, status code, headers)

### Config (`src/Config/`)

- `Config`: Dot-notation access to nested configuration
- `Defaults`: Complete default configuration values

### Protocol (`src/Protocol/`)

- `Request`: Immutable value object for JSON-RPC requests
- `Response`: Immutable value object for JSON-RPC responses
- `Error`: Immutable value object for JSON-RPC errors
- `RequestValidator`: Validates request structure against spec
- `BatchProcessor`: Handles single vs batch detection and processing
- `BatchResult`: Result of batch processing (requests + validation errors)

### Http (`src/Http/`)

- `HttpRequest`: Wraps PHP globals into a testable request object
- `HttpResponse`: Represents an HTTP response with body, status, headers
- `RequestReader`: Reads raw body, handles compression and size limits

### Dispatcher (`src/Dispatcher/`)

- `MethodResolver`: Maps `handler.method` to class file + method name safely
- `HandlerDispatcher`: Creates handler instances and invokes methods
- `HandlerFactoryInterface`: Contract for custom handler instantiation (DI)
- `DefaultHandlerFactory`: Default factory (RequestContext injection, optional params)
- `ParameterBinder`: Binds positional or named params to method arguments
- `HandlerRegistry`: Discovers all available handlers and methods
- `ProcedureDescriptor`: Value object for explicit procedure registration with metadata
- `MethodResolution`: Value object representing a resolved handler class + method

### Auth (`src/Auth/`)

- `AuthManager`: Orchestrates authentication (enabled/disabled, token extraction)
- `AuthenticatorInterface`: Low-level token validator contract
- `JwtAuthenticator`: Decodes and validates JWT tokens (HMAC)
- `RequestAuthenticatorInterface`: Driver-level auth from HTTP headers
- `JwtRequestAuthenticator`: JWT driver (extracts Bearer token from header)
- `ApiKeyAuthenticator`: API key driver (reads key from configurable header)
- `BasicAuthenticator`: HTTP Basic driver (username/password from Authorization header)
- `UserContext`: Immutable authenticated user context with roles

### Middleware (`src/Middleware/`)

- `MiddlewareInterface`: Contract for request middleware
- `MiddlewarePipeline`: Ordered middleware execution with short-circuit support

### Validation (`src/Validation/`)

- `RpcSchemaProviderInterface`: Contract for handlers declaring parameter schemas
- `SchemaValidator`: Lightweight JSON Schema subset validator

### RateLimit (`src/RateLimit/`)

- `RateLimitManager`: Orchestrates rate limiting by strategy
- `RateLimiterInterface`: Pluggable contract for rate limit backends
- `FileRateLimiter`: File-based rate limit storage with atomic weighted consumption and configurable fail-open/fail-closed behavior
- `InMemoryRateLimiter`: In-memory rate limiter for testing or single-process use

### Log (`src/Log/`)

- `Logger`: Writes structured logs with level filtering
- `LogRotator`: Rotates, compresses, and prunes log files
- `LogFormatter`: Formats log lines with optional secret sanitization

### Cache (`src/Cache/`)

- `ResponseFingerprinter`: Generates content hashes for ETag support

### Doc (`src/Doc/`)

- `DocGenerator`: Extracts documentation from handlers via reflection + PHPDoc. Uses already-discovered handlers from `HandlerRegistry` — does not mutate registry state.
- `MarkdownGenerator`: Renders docs as Markdown
- `HtmlGenerator`: Renders docs as styled HTML
- `JsonDocGenerator`: Renders docs as JSON
- `OpenRpcGenerator`: Renders docs as OpenRPC 1.3.2 specification

### Support (`src/Support/`)

- `RequestContext`: Immutable request context (IP, headers, auth info, rawBody = transport-level body, requestBody = decoded body)
- `HookManager`: Registers and fires lifecycle hooks
- `HookPoint`: Enum of available hook points
- `Compressor`: Gzip encode/decode utilities
- `CorrelationId`: Generates unique request IDs

## Error Handling Strategy

All errors are converted to proper JSON-RPC error responses:

| Scenario                  | Error Code       | Message                 |
| ------------------------- | ---------------- | ----------------------- |
| Invalid JSON              | -32700           | Parse error             |
| Invalid request structure | -32600           | Invalid Request         |
| Empty POST body           | -32600           | Invalid Request         |
| Empty batch `[]`          | -32600           | Invalid Request         |
| Batch exceeds limit       | -32600           | Invalid Request         |
| Unknown method            | -32601           | Method not found        |
| Bad parameters            | -32602           | Invalid params          |
| Unknown/surplus params    | -32602           | Invalid params          |
| Server/application error  | -32603           | Internal error          |
| JSON serialization failed | -32603           | Internal error          |
| Rate limit exceeded       | -32000           | Rate limit exceeded     |
| Authentication required   | -32001           | Authentication required |
| Custom server errors      | -32000 to -32099 | Implementation-defined  |

In production mode (`debug: false`), stack traces and internal details are stripped from error responses. In debug mode, additional diagnostic data is included.

JSON serialization failures in response encoding are handled per-response: each response is encoded independently using `JSON_THROW_ON_ERROR` so one unserializable result does not affect other responses in a batch. A single failed response becomes a `-32603` error with a diagnostic message, while other batch responses remain intact.

Documentation generators (`OpenRpcGenerator`, `JsonDocGenerator`) use `JSON_THROW_ON_ERROR` — encoding failures are surfaced as exceptions rather than silently replaced with empty JSON objects.

The `FileRateLimiter` uses `JSON_THROW_ON_ERROR` for persisting rate limit state — encoding failures surface as exceptions rather than silently corrupting state with empty JSON.

## Extension Points

### Custom Handler Factory (DI)

```php
$server->setHandlerFactory(new class implements \Lumen\JsonRpc\Dispatcher\HandlerFactoryInterface {
    public function create(string $className, \Lumen\JsonRpc\Support\RequestContext $context): object {
        // inject dependencies before returning the handler instance
    }
});
```

### Middleware

```php
$server->addMiddleware(new class implements \Lumen\JsonRpc\Middleware\MiddlewareInterface {
    public function process(
        \Lumen\JsonRpc\Protocol\Request $request,
        \Lumen\JsonRpc\Support\RequestContext $context,
        callable $next
    ): ?\Lumen\JsonRpc\Protocol\Response {
        // pre-processing
        $response = $next($request, $context);
        // post-processing
        return $response;
    }
});
```

### Custom Authenticator

Implement `AuthenticatorInterface`:

```php
class MyAuth implements \Lumen\JsonRpc\Auth\AuthenticatorInterface {
    public function authenticate(string $token): ?\Lumen\JsonRpc\Auth\UserContext {
        // Custom auth logic
    }
}
```

### Custom Rate Limiter

Implement `RateLimiterInterface`:

```php
class RedisRateLimiter implements \Lumen\JsonRpc\RateLimit\RateLimiterInterface {
    public function check(string $key): \Lumen\JsonRpc\RateLimit\RateLimitResult {
        // Redis-backed single check
    }

    public function checkAndConsume(string $key, int $weight): \Lumen\JsonRpc\RateLimit\RateLimitResult {
        // Redis-backed atomic weighted check+consume
    }
}
```

An `InMemoryRateLimiter` is also available for testing or single-process use cases.

### Explicit Procedure Descriptors

For advanced use cases where you want an explicit API contract instead of (or in addition to) handler auto-discovery:

```php
use Lumen\JsonRpc\Dispatcher\ProcedureDescriptor;

$registry = $server->getRegistry();

$registry->register('math.add', MathHandler::class, 'add', [
    'description' => 'Add two numbers',
    'requiresAuth' => false,
]);

// Or using descriptor objects:
$registry->registerDescriptor(new ProcedureDescriptor(
    method: 'math.multiply',
    handlerClass: MathHandler::class,
    handlerMethod: 'multiply',
    metadata: ['description' => 'Multiply two numbers'],
));
```

Descriptor metadata is used by the documentation generators (including OpenRPC).

### OpenRPC Export

Generate machine-readable OpenRPC specification:

```bash
php bin/generate-docs.php --format=openrpc --output=docs/openrpc.json
```

### Hooks

```php
$server->getHooks()->register(
    \Lumen\JsonRpc\Support\HookPoint::BEFORE_HANDLER,
    function (array $context) {
        // Pre-processing logic
        return ['extra_data' => 'value'];
    }
);
```
