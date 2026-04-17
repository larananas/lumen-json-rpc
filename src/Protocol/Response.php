<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Protocol;

final class Response
{
    public function __construct(
        public readonly string|int|float|null $id,
        public readonly mixed $result = null,
        public readonly ?Error $error = null,
        public readonly string $jsonrpc = '2.0',
    ) {}

    public static function success(string|int|float|null $id, mixed $result): self
    {
        return new self(id: $id, result: $result);
    }

    public static function error(string|int|float|null $id, Error $error): self
    {
        return new self(id: $id, error: $error);
    }

    public function toArray(): array
    {
        $arr = [
            'jsonrpc' => $this->jsonrpc,
        ];
        if ($this->error !== null) {
            $arr['error'] = $this->error->toArray();
        } else {
            $arr['result'] = $this->result;
        }
        $arr['id'] = $this->id;
        return $arr;
    }

    public function toJson(int $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): string
    {
        $encoded = json_encode($this->toArray(), $flags);
        if ($encoded !== false) {
            return $encoded;
        }
        return json_encode([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32603, 'message' => 'Internal error', 'data' => 'JSON encoding failed'],
            'id' => $this->id,
        ], $flags) ?: '{"jsonrpc":"2.0","error":{"code":-32603,"message":"Internal error"},"id":null}';
    }
}
