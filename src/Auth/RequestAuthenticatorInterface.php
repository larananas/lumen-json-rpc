<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Auth;

interface RequestAuthenticatorInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function authenticateFromHeaders(array $headers): ?UserContext;
}
