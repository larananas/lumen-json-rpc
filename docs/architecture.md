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
  -> RequestReader (body extraction, gzip decoding, size limit)
  -> JSON Decode (with depth limit)
  -> BatchProcessor (detect single vs batch, validate structure)
  -> AuthManager (extract JWT, authenticate, build UserContext)
  -> RateLimitManager (check rate limits)
  -> For each request:
     -> HookManager (fire BEFORE_HANDLER)
     -> MethodResolver (map method name to handler class+method)
     -> HandlerDispatcher (instantiate handler, invoke method)
     -> ParameterBinder (bind params to method arguments)
     -> HookManager (fire AFTER_HANDLER)
  -> Response building (success or error)
  -> HookManager (fire ON_RESPONSE)
  -> HttpResponse (JSON encode, optional ETag)
```

## Component Responsibilities

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
- `ParameterBinder`: Binds positional or named params to method arguments
- `HandlerRegistry`: Discovers all available handlers and methods

### Auth (`src/Auth/`)

- `AuthManager`: Orchestrates authentication (enabled/disabled, token extraction)
- `JwtAuthenticator`: Decodes and validates JWT tokens
- `UserContext`: Immutable authenticated user context with roles

### RateLimit (`src/RateLimit/`)

- `RateLimitManager`: Orchestrates rate limiting by strategy
- `FileRateLimiter`: File-based rate limit storage with atomic weighted consumption and configurable fail-open/fail-closed behavior

### Log (`src/Log/`)

- `Logger`: Writes structured logs with level filtering
- `LogRotator`: Rotates, compresses, and prunes log files
- `LogFormatter`: Formats log lines with optional secret sanitization

### Cache (`src/Cache/`)

- `ResponseFingerprinter`: Generates content hashes for ETag support

### Doc (`src/Doc/`)

- `DocGenerator`: Extracts documentation from handlers via reflection + PHPDoc
- `MarkdownGenerator`: Renders docs as Markdown
- `HtmlGenerator`: Renders docs as styled HTML
- `JsonDocGenerator`: Renders docs as JSON

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
| Unknown method            | -32601           | Method not found        |
| Bad parameters            | -32602           | Invalid params          |
| Unknown/surplus params    | -32602           | Invalid params          |
| Server/application error  | -32603           | Internal error          |
| JSON serialization failed | -32603           | Internal error          |
| Rate limit exceeded       | -32000           | Rate limit exceeded     |
| Authentication required   | -32001           | Authentication required |
| Custom server errors      | -32000 to -32099 | Implementation-defined  |

In production mode (`debug: false`), stack traces and internal details are stripped from error responses. In debug mode, additional diagnostic data is included.

JSON serialization failures in response encoding are handled per-response: each response is encoded independently so one unserializable result does not affect other responses in a batch. A single failed response becomes a `-32603` error, while other batch responses remain intact.

## Extension Points

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
