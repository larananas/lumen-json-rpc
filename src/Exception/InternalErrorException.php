<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Exception;

class InternalErrorException extends JsonRpcException
{
    public function getErrorCode(): int
    {
        return -32603;
    }

    public function getErrorMessage(): string
    {
        return 'Internal error';
    }
}
