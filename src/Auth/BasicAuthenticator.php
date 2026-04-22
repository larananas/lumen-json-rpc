<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Auth;

final class BasicAuthenticator implements RequestAuthenticatorInterface
{
    /**
     * @param array<string, array{password?: string, password_hash?: string, user_id?: string, claims?: array<string, mixed>, roles?: array<int, string>}> $users
     */
    public function __construct(
        private readonly string $header = 'Authorization',
        private readonly array $users = [],
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public function authenticateFromHeaders(array $headers): ?UserContext
    {
        $authHeader = $this->getHeaderCaseInsensitive($headers, $this->header);
        if ($authHeader === null) {
            return null;
        }

        if (!str_starts_with($authHeader, 'Basic ')) {
            return null;
        }

        $encoded = substr($authHeader, 6);
        if ($encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            return null;
        }

        $colonPos = strpos($decoded, ':');
        if ($colonPos === false) {
            return null;
        }

        $username = substr($decoded, 0, $colonPos);
        $password = substr($decoded, $colonPos + 1);

        if (!isset($this->users[$username])) {
            return null;
        }

        $userConfig = $this->users[$username];

        if (!$this->isPasswordValid($userConfig, $password)) {
            return null;
        }

        return new UserContext(
            userId: $userConfig['user_id'] ?? $username,
            claims: $userConfig['claims'] ?? [],
            roles: $userConfig['roles'] ?? [],
        );
    }

    /**
     * @param array{password?: string, password_hash?: string, user_id?: string, claims?: array<string, mixed>, roles?: array<int, string>} $userConfig
     */
    private function isPasswordValid(array $userConfig, string $password): bool
    {
        $passwordHash = $userConfig['password_hash'] ?? null;
        if (is_string($passwordHash) && $passwordHash !== '') {
            return password_verify($password, $passwordHash);
        }

        $configuredPassword = $userConfig['password'] ?? null;
        if (!is_string($configuredPassword) || $configuredPassword === '') {
            return false;
        }

        return hash_equals($configuredPassword, $password);
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
