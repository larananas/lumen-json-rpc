<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Dispatcher;

use Lumen\JsonRpc\Support\RequestContext;

interface HandlerFactoryInterface
{
    public function create(string $className, RequestContext $context): object;
}
