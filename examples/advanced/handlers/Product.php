<?php

declare(strict_types=1);

namespace App\Handlers\Advanced;

use Lumen\JsonRpc\Support\RequestContext;
use Lumen\JsonRpc\Validation\RpcSchemaProviderInterface;

class Product implements RpcSchemaProviderInterface
{
    public static function rpcValidationSchemas(): array
    {
        return [
            'create' => [
                'type' => 'object',
                'required' => ['name', 'price'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1],
                    'price' => ['type' => 'number'],
                    'tags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'maxItems' => 10,
                    ],
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    public function create(RequestContext $context, string $name, float $price, array $tags = []): array
    {
        return [
            'status' => 'created',
            'product' => [
                'id' => bin2hex(random_bytes(8)),
                'name' => $name,
                'price' => $price,
                'tags' => $tags,
            ],
        ];
    }

    public function list(RequestContext $context): array
    {
        return [
            'products' => [
                ['id' => 'abc123', 'name' => 'Widget', 'price' => 9.99],
            ],
        ];
    }
}
