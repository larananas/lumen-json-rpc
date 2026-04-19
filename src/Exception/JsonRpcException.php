<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Exception;

use Throwable;

abstract class JsonRpcException extends \RuntimeException
{
    abstract public function getErrorCode(): int;
    abstract public function getErrorMessage(): string;

    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, private mixed $data = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getErrorData(): mixed
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $error = [
            'code' => $this->getErrorCode(),
            'message' => $this->getErrorMessage(),
        ];
        if ($this->data !== null) {
            $error['data'] = $this->data;
        }
        return $error;
    }
}
