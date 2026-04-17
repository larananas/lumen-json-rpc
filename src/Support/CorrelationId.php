<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Support;

final class CorrelationId
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
