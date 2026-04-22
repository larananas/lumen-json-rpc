# Security Notes

## Method Execution Safety

The `MethodResolver` enforces strict rules to prevent arbitrary code execution:

1. **Regex validation**: Method names must match `^[a-zA-Z][a-zA-Z0-9]*\.[a-zA-Z][a-zA-Z0-9_]*$`
2. **Reserved prefix**: Methods starting with `rpc.` are always rejected
3. **Magic method blocking**: Methods starting with `__` are never callable
4. **Visibility check**: Only public methods can be invoked
5. **No dynamic includes**: File paths are derived from the class name, not user input
6. **Whitelist approach**: Only files in configured handler directories are loaded

## Path Traversal Prevention

Handler file paths are constructed deterministically:

- The handler part (e.g., `user` from `user.create`) is mapped to `User.php`
- Directory separators are never derived from user input
- Only configured handler paths are searched

## Authentication

- JWT tokens are extracted from the `Authorization` header, never from request params
- Token validation includes signature verification, expiration check, and issuer/audience validation
- Authentication failures are logged without exposing token contents

## Logging

### Secret Sanitization

The `LogFormatter` automatically redacts values for keys containing:

- `password`, `secret`, `token`, `api_key`, `apikey`
- `authorization`, `credit_card`, `creditcard`, `cvv`
- `access_token`, `refresh_token`, `private_key`

Nested values are also sanitized recursively.

### Log Injection Prevention

Log messages are structured as single-line entries. Newline characters in messages are replaced with escaped literals (`\n`, `\r`). Context data is JSON-encoded, preventing log injection through crafted input.

### Log Rotation Integrity

When compression is enabled, backup files only receive the `.gz` extension when gzip compression actually succeeds. If compression fails (e.g., due to runtime constraints), the backup is stored uncompressed without the `.gz` extension, preventing misleading file naming.

## Rate Limiting

- File-based storage is used to avoid external dependencies
- Rate limit keys are sanitized before file creation (special characters replaced)
- Window-based counting resets naturally at time boundaries
- Weighted consumption is atomic: a batch request either consumes all its quota hits or none
- Oversized batches count against rate limits based on the actual number of items received, not the post-validation count
- Configurable `fail_open` behavior: by default, requests are denied on storage failure with a warning (fail-closed); set `fail_open: true` only if you intentionally want storage failures to allow requests
- Storage lock failures and file access errors trigger `E_USER_WARNING` for monitoring

## Compression

- Gzip decompression failures return `-32600 Invalid Request`, not `-32700 Parse error`
- Compressed payloads still count toward the body size limit
- The raw body is checked before decompression

## Batch Request Abuse

- Configurable maximum batch size (default: 100)
- Each item in a batch is validated independently
- Invalid items produce individual error responses without affecting valid items
- Rate limit weight is computed from the actual number of items received (not post-validation count), preventing bypass through oversized or invalid batches
- Rate limit consumption is atomic for weighted requests
- Empty POST body returns a controlled `-32600 Invalid Request` error instead of being silently accepted

## Config Loading

- `Config::fromFile()` throws `RuntimeException` on missing files or non-array returns
- Silent fallback to defaults on missing config is not supported — this prevents misconfiguration from going undetected

## Content-Type Enforcement

- By default (`content_type.strict: false`), POST requests are accepted regardless of Content-Type
- Set `content_type.strict: true` to require `application/json` Content-Type on POST requests
- Non-conforming requests receive a `-32600 Invalid Request` error

## Error Information Leakage

In production mode (`debug: false`):

- Stack traces are never included in responses
- Internal error messages are replaced with generic "Internal error"
- File paths and line numbers are stripped
- Only the JSON-RPC error code and standard message are returned

## Recommended Production Configuration

```php
[
    'debug' => false,
    'limits' => [
        'max_body_size' => 1048576,     // 1MB max
        'max_json_depth' => 32,          // Reasonable depth
    ],
    'batch' => [
        'max_items' => 50,               // Conservative limit
    ],
    'logging' => [
        'sanitize_secrets' => true,
    ],
    'content_type' => [
        'strict' => true,            // Require application/json
    ],
    'rate_limit' => [
        'enabled' => true,
        'max_requests' => 100,
        'window_seconds' => 60,
        'fail_open' => false,        // Default and recommended: fail-closed
    ],
    'auth' => [
        'enabled' => true,
        'jwt' => [
            'secret' => '<strong-random-secret>',
            'algorithm' => 'HS256',
        ],
    ],
]
```
