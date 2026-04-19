<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Protocol;

final class Response
{
    public function __construct(
        public readonly string|int|null $id,
        public readonly mixed $result = null,
        public readonly ?Error $error = null,
        public readonly string $jsonrpc = '2.0',
    ) {}

    public static function success(string|int|null $id, mixed $result): self
    {
        return new self(id: $id, result: $result);
    }

    public static function error(string|int|null $id, Error $error): self
    {
        return new self(id: $id, error: $error);
    }

    /**
     * @return array<string, mixed>
     */
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
        try {
            $encoded = json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $fallback = [
                'jsonrpc' => '2.0',
                'error' => ['code' => -32603, 'message' => 'Internal error', 'data' => 'JSON encoding failed'],
                'id' => $this->id,
            ];
            $encoded = json_encode($fallback, $flags);
            if ($encoded === false) {
                $encoded = '{"jsonrpc":"2.0","error":{"code":-32603,"message":"Internal error"},"id":null}';
            }
        }
        return $encoded;
    }
}
