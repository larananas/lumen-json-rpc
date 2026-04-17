<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Auth;

interface RequestAuthenticatorInterface
{
    public function authenticateFromHeaders(array $headers): ?UserContext;
}
