<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Middleware;

use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Protocol\Response;
use Lumen\JsonRpc\Support\RequestContext;

final class MiddlewarePipeline
{
    /** @var array<int, MiddlewareInterface> */
    private array $middlewares = [];

    public function add(MiddlewareInterface $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    public function process(Request $request, RequestContext $context, callable $finalHandler): ?Response
    {
        $pipeline = $finalHandler;

        for ($i = count($this->middlewares) - 1; $i >= 0; $i--) {
            $middleware = $this->middlewares[$i];
            $pipeline = $this->wrapMiddleware($middleware, $pipeline);
        }

        return $pipeline($request, $context);
    }

    public function isEmpty(): bool
    {
        return empty($this->middlewares);
    }

    private function wrapMiddleware(MiddlewareInterface $middleware, callable $next): callable
    {
        return function (Request $request, RequestContext $context) use ($middleware, $next): ?Response {
            return $middleware->process($request, $context, $next);
        };
    }
}
