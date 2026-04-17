# 🔐 Authentication

This guide explains what Lumen JSON-RPC handles for JWT authentication, and what still belongs to your application.

## Scope

Lumen JSON-RPC handles **JWT validation and method protection**. It does **not** handle login, token issuance, user storage, or fine-grained authorization.

### ✅ What the library handles

- Authentication via JWT, API key, or HTTP Basic (configurable driver)
- JWT token validation and signature verification for supported HMAC algorithms
- Signature verification for supported HMAC algorithms
- Claim checks for `exp`, `nbf`, `iat`, `iss`, and `aud` when configured
- Token extraction from the HTTP `Authorization` header
- Method protection through prefix-based `protected_methods`
- Injection of authentication data into `RequestContext`

### 🧩 What your application handles

- Login flow and credential verification
- JWT creation / token issuance
- User storage (database, file, API, etc.)
- Fine-grained authorization (roles, ownership, ACL, business rules)
- Web server quirks around the `Authorization` header

In short:

- the **library validates tokens**
- your **application creates tokens and decides what users can do**

---

## What happens by default?

| Scenario                                                   | Behavior                                                                                                                          |
| ---------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `auth.enabled = false` _(default)_                         | No authentication is performed. All methods are public.                                                                           |
| `auth.enabled = true` + no protected prefix match          | A token is validated if present, and auth info is injected into `RequestContext`. The method still remains callable without auth. |
| `auth.enabled = true` + method matches `protected_methods` | The method requires valid credentials. Missing or invalid credentials result in `-32001 Authentication required`.                 |
| Valid token on a protected method                          | The request proceeds and auth claims are available through `RequestContext`.                                                      |
| Valid token on a non-protected method                      | The request still proceeds, and the handler may optionally use the auth context.                                                  |

---

## Configuration

### Auth driver selection

The `auth.driver` setting determines which authentication method is used:

| Driver    | Description                                   |
| --------- | --------------------------------------------- |
| `jwt`     | JWT Bearer token (default)                    |
| `api_key` | API key in a configurable HTTP header         |
| `basic`   | HTTP Basic authentication (username/password) |

### JWT driver

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

### `auth.enabled`

Default: `false`

When enabled, the server will use the configured `driver` to authenticate requests.

### `auth.protected_methods`

Default: `[]`

This is a list of **method prefixes**.

```php
'protected_methods' => ['user.', 'order.']
```

This means:

- `user.get` is protected
- `user.create` is protected
- `order.list` is protected
- `auth.login` is **not** protected
- `system.health` is **not** protected

This is a lightweight access gate, not a full authorization system.

### `auth.jwt.secret`

Required when authentication is enabled.

Used to verify the token signature.

### `auth.jwt.algorithm`

Default: `HS256`

Built-in support covers:

- `HS256`
- `HS384`
- `HS512`

For broader algorithm support, install `firebase/php-jwt`.

### `auth.jwt.header`

Default: `Authorization`

The header used to read the token from the request.

### `auth.jwt.prefix`

Default: `Bearer `

Expected token prefix in the header value.

### `auth.jwt.issuer`, `auth.jwt.audience`, `auth.jwt.leeway`

Optional controls for stricter validation and clock skew tolerance.

### API Key driver

```php
'auth' => [
    'enabled' => true,
    'driver' => 'api_key',
    'protected_methods' => ['user.'],
    'api_key' => [
        'header' => 'X-API-Key',
        'keys' => [
            'your-api-key' => [
                'user_id' => 'service-name',
                'roles' => ['service'],
                'claims' => ['source' => 'api_key'],
            ],
        ],
    ],
],
```

The API key driver reads a key from the configured header and matches it against the `keys` map. No external library required.

### Basic Auth driver

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

The Basic auth driver reads standard `Authorization: Basic <base64>` headers. Passwords are verified using `hash_equals` for timing-safe comparison. No external library required.

---

## Accessing auth data in handlers

Auth data is injected into `RequestContext`.

```php
use Lumen\JsonRpc\Support\RequestContext;

public function me(RequestContext $context): array
{
    return [
        'id' => $context->authUserId,
        'roles' => $context->authRoles,
        'email' => $context->getClaim('email'),
    ];
}
```

Useful helpers:

- `$context->authUserId`
- `$context->authRoles`
- `$context->authClaims`
- `$context->getClaim('email')`
- `$context->hasRole('admin')`

---

## Authorization vs authentication

This is the important distinction:

- **Authentication** = “is this token valid?” → handled by the library
- **Authorization** = “is this authenticated user allowed to do this?” → handled by your application

Example:

```php
if (!$context->hasRole('admin') && $context->authUserId !== $id) {
    return ['error' => 'You can only view your own profile'];
}
```

That rule belongs in application code, not in the core library.

---

## Token issuance

Lumen JSON-RPC does **not** issue JWTs for you.

Your application is responsible for:

- checking credentials
- building the token payload
- signing the token
- returning it from your own login method

See [`examples/auth/`](../examples/auth/) for a complete reference example.

---

## Apache note: forwarding `Authorization`

Depending on your Apache/PHP setup, the `Authorization` header may not be forwarded automatically.

If needed, add this to your `.htaccess`:

```apache
RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
```

This is a web server concern, not something the library can solve internally.

---

## Recommended developer flow

A typical setup looks like this:

1. `auth.login` verifies credentials in your app
2. your app issues a JWT
3. the client sends `Authorization: Bearer <token>`
4. the library validates the token
5. protected methods receive auth info through `RequestContext`
6. your handler applies business authorization rules

That keeps the library focused and the application in control.
