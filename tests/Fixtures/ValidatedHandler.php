<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Fixtures;

use Lumen\JsonRpc\Validation\RpcSchemaProviderInterface;

final class ValidatedHandler implements RpcSchemaProviderInterface
{
    public static function rpcValidationSchemas(): array
    {
        return [
            'create' => [
                'type' => 'object',
                'required' => ['email', 'roles'],
                'properties' => [
                    'email' => ['type' => 'string'],
                    'roles' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'minItems' => 1,
                    ],
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    public function create(string $email, array $roles): array
    {
        return ['status' => 'created', 'email' => $email];
    }
}
