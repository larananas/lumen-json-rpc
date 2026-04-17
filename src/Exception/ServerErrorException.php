<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Exception;

class ServerErrorException extends JsonRpcException
{
    public function __construct(
        string $message = 'Server error',
        int $appCode = -32000,
        ?\Throwable $previous = null,
        mixed $data = null
    ) {
        $code = max(-32099, min(-32000, $appCode));
        parent::__construct($message, $code, $previous, $data);
    }

    public function getErrorCode(): int
    {
        return $this->code;
    }

    public function getErrorMessage(): string
    {
        return $this->getMessage();
    }
}
