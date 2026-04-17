<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Protocol;

final class RequestValidator
{
    public function __construct(
        private readonly bool $strict = true,
    ) {}

    public function validateArray(array $data): ?Error
    {
        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            return Error::invalidRequest('jsonrpc must be exactly "2.0"');
        }

        if (!isset($data['method']) || !is_string($data['method'])) {
            return Error::invalidRequest('method must be a string');
        }

        if ($data['method'] === '') {
            return Error::invalidRequest('method must not be empty');
        }

        if (array_key_exists('id', $data) && $data['id'] !== null && !is_string($data['id']) && !is_int($data['id']) && !is_float($data['id'])) {
            return Error::invalidRequest('id must be string, number, or null');
        }

        if (array_key_exists('params', $data)) {
            if (!is_array($data['params'])) {
                return Error::invalidRequest('params must be array or object');
            }
        }

        if ($this->strict) {
            $extraKeys = array_diff(array_keys($data), ['jsonrpc', 'method', 'params', 'id']);
            if (!empty($extraKeys)) {
                return Error::invalidRequest('unexpected members: ' . implode(', ', $extraKeys));
            }
        }

        return null;
    }

    public function validateRequest(Request $request): ?Error
    {
        if ($request->jsonrpc !== '2.0') {
            return Error::invalidRequest('jsonrpc must be exactly "2.0"');
        }

        if ($request->method === '') {
            return Error::invalidRequest('method must not be empty');
        }

        return null;
    }
}
