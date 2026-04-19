<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Protocol;

final class Error
{
    public function __construct(
        public readonly int $code,
        public readonly string $message,
        public readonly mixed $data = null,
    ) {}

    public static function parseError(?string $detail = null): self
    {
        return new self(-32700, 'Parse error', $detail);
    }

    public static function invalidRequest(?string $detail = null): self
    {
        return new self(-32600, 'Invalid Request', $detail);
    }

    public static function methodNotFound(?string $detail = null): self
    {
        return new self(-32601, 'Method not found', $detail);
    }

    public static function invalidParams(?string $detail = null): self
    {
        return new self(-32602, 'Invalid params', $detail);
    }

    public static function internalError(?string $detail = null): self
    {
        return new self(-32603, 'Internal error', $detail);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $arr = [
            'code' => $this->code,
            'message' => $this->message,
        ];
        if ($this->data !== null) {
            $arr['data'] = $this->data;
        }
        return $arr;
    }
}
