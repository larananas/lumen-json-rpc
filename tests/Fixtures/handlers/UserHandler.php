<?php

declare(strict_types=1);

namespace App\Handlers\SchemaTest;

use Lumen\JsonRpc\Support\RequestContext;
use Lumen\JsonRpc\Validation\RpcSchemaProviderInterface;

class UserHandler implements RpcSchemaProviderInterface
{
    public static function rpcValidationSchemas(): array
    {
        return [
            'create' => [
                'type' => 'object',
                'required' => ['name', 'email'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1],
                    'email' => ['type' => 'string'],
                    'age' => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    public function create(string $name, string $email, int $age = 0): array
    {
        return ['created' => true, 'name' => $name, 'email' => $email];
    }

    public function ping(): array
    {
        return ['pong' => true];
    }
}
