<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Exception;

class ParseErrorException extends JsonRpcException
{
    public function getErrorCode(): int
    {
        return -32700;
    }

    public function getErrorMessage(): string
    {
        return 'Parse error';
    }
}
