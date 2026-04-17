<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Auth;

interface AuthenticatorInterface
{
    public function authenticate(string $token): ?UserContext;
}
