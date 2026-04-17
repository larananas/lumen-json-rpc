# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-04-17

### Added

- JSON-RPC 2.0 server with full spec compliance (requests, notifications, batch)
- Handler auto-discovery from directories with configurable namespace and method separator
- JWT authentication with built-in HMAC decoder (HS256/HS384/HS512)
- Method-level authentication via protected method prefixes
- Gzip request decompression and response compression (requires ext-zlib)
- Rate limiting with file-based storage, configurable strategy (ip/user/token)
- Structured logging with configurable levels and secret sanitization
- Log rotation with automatic compression of rotated files
- Batch request processing with configurable item limit
- Request/response fingerprinting with ETag support and conditional 304 responses
- Health endpoint on GET requests
- Hook/extension point system for request lifecycle events
- Content-Type and validation strictness controls
- Documentation generator CLI tool (markdown, JSON, HTML output)
