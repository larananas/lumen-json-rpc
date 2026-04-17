<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Auth;

final class AuthManager
{
    private ?AuthenticatorInterface $authenticator = null;

    public function __construct(
        private readonly bool $enabled = false,
    ) {}

    public function setAuthenticator(AuthenticatorInterface $authenticator): void
    {
        $this->authenticator = $authenticator;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function authenticate(string $token): ?UserContext
    {
        if (!$this->enabled || $this->authenticator === null) {
            return null;
        }

        return $this->authenticator->authenticate($token);
    }
}
