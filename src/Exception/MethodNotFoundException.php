<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Exception;

class MethodNotFoundException extends JsonRpcException
{
    public function getErrorCode(): int
    {
        return -32601;
    }

    public function getErrorMessage(): string
    {
        return 'Method not found';
    }
}
