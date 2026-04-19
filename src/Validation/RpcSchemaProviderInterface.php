<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Validation;

interface RpcSchemaProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public static function rpcValidationSchemas(): array;
}
