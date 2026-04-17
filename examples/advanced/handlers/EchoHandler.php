<?php

declare(strict_types=1);

namespace App\Handlers\Advanced;

use Lumen\JsonRpc\Support\RequestContext;

class EchoHandler
{
    public function ping(RequestContext $context): array
    {
        return ['pong' => true, 'correlationId' => $context->correlationId];
    }
}
