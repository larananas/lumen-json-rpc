<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Auth;

final class BasicAuthenticator implements RequestAuthenticatorInterface
{
    public function __construct(
        private readonly string $header = 'Authorization',
        private readonly array $users = [],
    ) {}

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

        if (!hash_equals($userConfig['password'], $password)) {
            return null;
        }

        return new UserContext(
            userId: $userConfig['user_id'] ?? $username,
            claims: $userConfig['claims'] ?? [],
            roles: $userConfig['roles'] ?? [],
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
