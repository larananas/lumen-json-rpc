<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Validation;

interface RpcSchemaProviderInterface
{
    public static function rpcValidationSchemas(): array;
}
