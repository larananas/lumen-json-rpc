<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Protocol;

final class Request
{
    public readonly bool $isNotification;

    public function __construct(
        public readonly string $method,
        public readonly string|int|float|null $id,
        public readonly array|null $params,
        public readonly bool $idProvided,
        public readonly string $jsonrpc = '2.0',
    ) {
        $this->isNotification = !$idProvided;
    }

    public static function fromArray(array $data): self
    {
        $params = array_key_exists('params', $data) ? $data['params'] : null;
        if (!is_array($params)) {
            $params = null;
        }

        $idProvided = array_key_exists('id', $data);
        $id = $idProvided ? self::sanitizeId($data['id']) : null;

        return new self(
            method: (string)($data['method'] ?? ''),
            id: $id,
            params: $params,
            idProvided: $idProvided,
            jsonrpc: (string)($data['jsonrpc'] ?? ''),
        );
    }

    public static function sanitizeId(mixed $id): string|int|float|null
    {
        if ($id === null || is_string($id) || is_int($id) || is_float($id)) {
            return $id;
        }
        return null;
    }

    public function toArray(): array
    {
        $arr = [
            'jsonrpc' => $this->jsonrpc,
            'method' => $this->method,
        ];
        if ($this->params !== null) {
            $arr['params'] = $this->params;
        }
        if ($this->idProvided) {
            $arr['id'] = $this->id;
        }
        return $arr;
    }
}
