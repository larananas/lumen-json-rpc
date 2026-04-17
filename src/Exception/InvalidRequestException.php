<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Exception;

class InvalidRequestException extends JsonRpcException
{
    public function getErrorCode(): int
    {
        return -32600;
    }

    public function getErrorMessage(): string
    {
        return 'Invalid Request';
    }
}
