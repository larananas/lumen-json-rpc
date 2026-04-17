<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Exception;

class InvalidParamsException extends JsonRpcException
{
    public function getErrorCode(): int
    {
        return -32602;
    }

    public function getErrorMessage(): string
    {
        return 'Invalid params';
    }
}
