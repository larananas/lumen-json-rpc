<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Middleware;

use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Protocol\Response;
use Lumen\JsonRpc\Support\RequestContext;

interface MiddlewareInterface
{
    public function process(Request $request, RequestContext $context, callable $next): ?Response;
}
