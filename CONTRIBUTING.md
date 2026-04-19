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
composer qa:max         # Maximum bar: all of the above + coverage check + mutation testing
```

CI runs the equivalent of `qa:max` split across parallel jobs:
`quality` (validate + audit + package verify + lint + stan) → `tests` (PHPUnit on PHP 8.1–8.4) → `coverage` (coverage + threshold check) → `mutation` (Infection).

## Code Style

- PHP 8.1+ syntax with strict types (`declare(strict_types=1)`)
- No comments in production code unless explicitly requested
- PSR-4 autoloading via the `Lumen\JsonRpc\` namespace
- No framework dependencies in `require`
- Every new public method must be covered by tests

## Running Tests

```bash
composer test                         # PHPUnit
composer test:coverage                # PHPUnit with coverage (requires XDebug or PCOV)
vendor/bin/phpunit --filter=TestName  # Run a specific test
```

## Static Analysis

```bash
composer stan                         # PHPStan at level 8
```

## Mutation Testing

```bash
composer mutate                       # Infection with MSI >= 80, covered MSI >= 85
```

## Pull Request Process

1. Create a branch from `main`.
2. Make your changes with tests.
3. Run `composer qa` and ensure it passes.
4. Open a pull request with a clear description of what changed and why.
5. CI must pass (quality + tests on PHP 8.1–8.4 + coverage + mutation).

## Reporting Issues

- Use [GitHub Issues](https://github.com/larananas/lumen-json-rpc/issues).
- Include PHP version, library version, and a minimal reproduction case.

## Security Issues

See [SECURITY.md](SECURITY.md) for responsible disclosure details.
