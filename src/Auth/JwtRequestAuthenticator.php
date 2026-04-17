<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Auth;

final class JwtRequestAuthenticator implements RequestAuthenticatorInterface
{
    public function __construct(
        private readonly JwtAuthenticator $jwtAuthenticator,
        private readonly string $header = 'Authorization',
        private readonly string $prefix = 'Bearer ',
    ) {}

    public function authenticateFromHeaders(array $headers): ?UserContext
    {
        $authHeader = $this->getHeaderCaseInsensitive($headers, $this->header);
        if ($authHeader === null) {
            return null;
        }

        if (!str_starts_with($authHeader, $this->prefix)) {
            return null;
        }

        $token = substr($authHeader, strlen($this->prefix));
        if ($token === '') {
            return null;
        }

        return $this->jwtAuthenticator->authenticate($token);
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
