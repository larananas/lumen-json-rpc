<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Protocol;

final class Request
{
    public readonly bool $isNotification;

    /**
     * @param array<string, mixed>|array<int, mixed>|null $params
     */
    public function __construct(
        public readonly string $method,
        public readonly string|int|null $id,
        public readonly array|null $params,
        public readonly bool $idProvided,
        public readonly string $jsonrpc = '2.0',
    ) {
        $this->isNotification = !$idProvided;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $params = array_key_exists('params', $data) ? $data['params'] : null;
        if (!is_array($params)) {
            $params = null;
        }
        /** @var array<int|string, mixed>|null $params */

        $idProvided = array_key_exists('id', $data);
        $id = $idProvided ? self::sanitizeId($data['id']) : null;
        $method = $data['method'] ?? '';
        $jsonrpc = $data['jsonrpc'] ?? '';

        return new self(
            method: is_string($method) ? $method : '',
            id: $id,
            params: $params,
            idProvided: $idProvided,
            jsonrpc: is_string($jsonrpc) ? $jsonrpc : '',
        );
    }

    public static function sanitizeId(mixed $id): string|int|null
    {
        if ($id === null || is_string($id) || is_int($id)) {
            return $id;
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
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
