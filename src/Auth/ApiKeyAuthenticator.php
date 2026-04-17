<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Auth;

final class ApiKeyAuthenticator implements RequestAuthenticatorInterface
{
    public function __construct(
        private readonly string $header = 'X-API-Key',
        private readonly array $keys = [],
    ) {}

    public function authenticateFromHeaders(array $headers): ?UserContext
    {
        $apiKey = $this->getHeaderCaseInsensitive($headers, $this->header);
        if ($apiKey === null || $apiKey === '') {
            return null;
        }

        if (!isset($this->keys[$apiKey])) {
            return null;
        }

        $keyConfig = $this->keys[$apiKey];

        return new UserContext(
            userId: $keyConfig['user_id'] ?? 'unknown',
            claims: $keyConfig['claims'] ?? [],
            roles: $keyConfig['roles'] ?? [],
        );
    }

    private function getHeaderCaseInsensitive(array $headers, string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower((string)$key) === $lower) {
                return $value;
            }
        }
        return null;
    }
}
