# Authentication Example

This example shows the intended split between **library-level authentication** and **application-level logic**.

## What the library handles

- JWT validation
- Protection of methods matching `protected_methods`
- Injection of auth data into `RequestContext`

## What the application handles

- login
- issuing the token
- user storage
- business authorization rules

## Files

- `public/index.php` — entry point
- `config.php` — auth and server configuration
- `handlers/Auth.php` — application login + token creation example
- `handlers/User.php` — protected methods using `RequestContext`
- `.htaccess` — Apache note for forwarding `Authorization`

## Example flow

### 1. Login and get a token

```bash
curl -X POST http://localhost:8000/ \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "method": "auth.login",
    "params": {
      "email": "admin@example.com",
      "password": "admin123"
    },
    "id": 1
  }'
```

### 2. Call a protected method

```bash
curl -X POST http://localhost:8000/ \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{
    "jsonrpc": "2.0",
    "method": "user.me",
    "id": 2
  }'
```

### 3. Try the same call without a token

The library rejects the request because `user.` is listed in `protected_methods`.

## Demo accounts

| Email | Password | Roles |
|---|---|---|
| `admin@example.com` | `admin123` | `admin`, `user` |
| `user@example.com` | `user123` | `user` |

## Production note

This example intentionally uses hardcoded users and a hardcoded secret for readability.
In a real application:

- load the secret from environment variables
- hash passwords with `password_hash()` / `password_verify()`
- store users in a real persistence layer
- use HTTPS
- prefer a dedicated JWT library for token creation
