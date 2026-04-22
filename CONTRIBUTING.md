# Contributing to Lumen JSON-RPC

Thank you for your interest in contributing. This document covers the essentials.

## Development Setup

```bash
git clone https://github.com/larananas/lumen-json-rpc.git
cd lumen-json-rpc
composer install
```

## Quick Quality Check

```bash
composer check          # PHPStan + PHPUnit (fast)
composer qa             # Full QA: validate, audit, package verify, lint, stan, test
composer qa:max         # Extended local bar: qa + coverage check + mutation testing
```

CI covers the same release areas as `qa:max`, split across parallel jobs:
`quality` (validate + audit + package verify + lint + stan) → `tests` (PHPUnit on PHP 8.2–8.4) → `coverage` (coverage + threshold check) → `mutation` (Infection on PHP 8.3).

This library intentionally does not commit `composer.lock`; contributors resolve dev dependencies from `composer.json` and CI verifies the unlocked install path.

## Code Style

- PHP >=8.2 syntax with strict types (`declare(strict_types=1)`)
- No comments in production code unless explicitly requested
- PSR-4 autoloading via the `Lumen\JsonRpc\` namespace
- No framework dependencies in `require`
- Every new public method must be covered by tests

## Running Tests

```bash
composer test                         # PHPUnit
composer test:coverage                # PHPUnit with coverage (requires Xdebug coverage mode or PCOV)
vendor/bin/phpunit --filter=TestName  # Run a specific test
```

If you use Xdebug 3 locally, run coverage commands with `XDEBUG_MODE=coverage` or enable `xdebug.mode=coverage` in your PHP configuration.

## Static Analysis

```bash
composer stan                         # PHPStan at level 9
```

## Mutation Testing

```bash
composer mutate                       # Infection with MSI >= 80, covered MSI >= 85
```

`composer mutate` has the same coverage-driver requirement as `composer test:coverage`.

## Stable Public API

The supported public surface is centered on `JsonRpcServer` and its documented collaborators such as `RequestContext`, `HandlerFactoryInterface`, `MiddlewareInterface`, `RateLimiterInterface`, hooks, procedure descriptors, and stable server accessors like `getHooks()`, `getRegistry()`, and `getLogger()`.

`JsonRpcServer::getEngine()` remains an internal escape hatch and is not covered by backward-compatibility guarantees between minor releases.

The documented stable surface is guarded by `tests/Integration/StablePublicApiTest.php`. Compatibility-sensitive changes should update that test, the docs, and the changelog deliberately.

Release policy follows Semantic Versioning for the stable public API surface: patch releases may fix internals and packaging, minor releases may add stable API, and major releases may break the documented stable surface.

## Pull Request Process

1. Create a branch from `main`.
2. Make your changes with tests.
3. Run `composer qa` and ensure it passes.
4. Open a pull request with a clear description of what changed and why.
5. CI must pass (quality + tests on PHP 8.2–8.4 + coverage + mutation).

## Reporting Issues

- Use [GitHub Issues](https://github.com/larananas/lumen-json-rpc/issues).
- Include PHP version, library version, and a minimal reproduction case.

## Security Issues

See [SECURITY.md](SECURITY.md) for responsible disclosure details.
