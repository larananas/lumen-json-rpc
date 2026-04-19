<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Support;

final class RequestContext
{
    /**
     * @param string $correlationId Unique request identifier
     * @param array<string, string> $headers HTTP request headers
     * @param string $clientIp Client IP address
     * @param string|null $authUserId Authenticated user ID
     * @param array<string, mixed> $authClaims JWT claims
     * @param array<int, string> $authRoles User roles
     * @param string|null $rawBody Original transport-level body (may be gzipped if Content-Encoding: gzip was used)
     * @param string|null $requestBody Decoded body used for processing (after gzip decompression, if applicable)
     * @param array<string, mixed> $attributes Additional request attributes
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly array $headers,
        public readonly string $clientIp,
        public readonly ?string $authUserId = null,
        public readonly array $authClaims = [],
        public readonly array $authRoles = [],
        public readonly ?string $rawBody = null,
        public readonly ?string $requestBody = null,
        public readonly array $attributes = [],
    ) {}

    public function hasAuth(): bool
    {
        return $this->authUserId !== null;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->authRoles, true);
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<int, string> $roles
     */
    public function withAuth(?string $userId, array $claims = [], array $roles = []): self
    {
        return new self(
            correlationId: $this->correlationId,
            headers: $this->headers,
            clientIp: $this->clientIp,
            authUserId: $userId,
            authClaims: $claims,
            authRoles: $roles,
            rawBody: $this->rawBody,
            requestBody: $this->requestBody,
            attributes: $this->attributes,
        );
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function getClaim(string $key, mixed $default = null): mixed
    {
        return $this->authClaims[$key] ?? $default;
    }
}