<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Auth;

final class ApiKeyAuthenticator implements RequestAuthenticatorInterface
{
    /**
     * @param array<string, array{user_id?: string, claims?: array<string, mixed>, roles?: array<int, string>}> $keys
     */
    public function __construct(
        private readonly string $header = 'X-API-Key',
        private readonly array $keys = [],
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public function authenticateFromHeaders(array $headers): ?UserContext
    {
        $apiKey = $this->getHeaderCaseInsensitive($headers, $this->header);
        if ($apiKey === null || $apiKey === '') {
            return null;
        }

        $matchedConfig = null;
        foreach ($this->keys as $knownKey => $config) {
            if (hash_equals($knownKey, $apiKey)) {
                $matchedConfig = $config;
                break;
            }
        }

        if ($matchedConfig === null) {
            return null;
        }

        return new UserContext(
            userId: $matchedConfig['user_id'] ?? 'unknown',
            claims: $matchedConfig['claims'] ?? [],
            roles: $matchedConfig['roles'] ?? [],
        );
    }

    /**
     * @param array<string, string> $headers
     */
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
