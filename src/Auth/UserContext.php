<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Auth;

final class UserContext
{
    /**
     * @param array<string, mixed> $claims
     * @param array<int, string> $roles
     */
    public function __construct(
        public readonly string $userId,
        public readonly array $claims = [],
        public readonly array $roles = [],
    ) {}

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function getClaim(string $key, mixed $default = null): mixed
    {
        return $this->claims[$key] ?? $default;
    }
}
