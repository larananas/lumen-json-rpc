<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Middleware;

use Lumen\JsonRpc\Middleware\MiddlewareInterface;
use Lumen\JsonRpc\Middleware\MiddlewarePipeline;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Protocol\Response;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class MiddlewarePipelineTest extends TestCase
{
    private RequestContext $context;

    protected function setUp(): void
    {
        $this->context = new RequestContext(
            correlationId: 'test',
            headers: [],
            clientIp: '127.0.0.1',
        );
    }

    private function createRequest(string $method = 'test.method'): Request
    {
        return new Request(
            method: $method,
            id: 1,
            params: [],
            idProvided: true,
        );
    }

    public function testEmptyPipelineCallsFinalHandler(): void
    {
        $pipeline = new MiddlewarePipeline();
        $called = false;
        $result = $pipeline->process(
            $this->createRequest(),
            $this->context,
            function () use (&$called): ?Response {
                $called = true;
                return Response::success(1, 'ok');
            },
        );
        $this->assertTrue($called);
        $this->assertNotNull($result);
    }

    public function testMiddlewareExecutionOrder(): void
    {
        $order = [];

        $mw1 = new class($order) implements MiddlewareInterface {
            public function __construct(private array &$order) {}
            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                $this->order[] = 'mw1-before';
                $response = $next($request, $context);
                $this->order[] = 'mw1-after';
                return $response;
            }
        };

        $mw2 = new class($order) implements MiddlewareInterface {
            public function __construct(private array &$order) {}
            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                $this->order[] = 'mw2-before';
                $response = $next($request, $context);
                $this->order[] = 'mw2-after';
                return $response;
            }
        };

        $pipeline = new MiddlewarePipeline();
        $pipeline->add($mw1);
        $pipeline->add($mw2);

        $pipeline->process(
            $this->createRequest(),
            $this->context,
            function () use (&$order): ?Response {
                $order[] = 'handler';
                return Response::success(1, 'ok');
            },
        );

        $this->assertEquals(
            ['mw1-before', 'mw2-before', 'handler', 'mw2-after', 'mw1-after'],
            $order,
        );
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $handlerCalled = false;

        $mw = new class implements MiddlewareInterface {
            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                return Response::error($request->id, new \Lumen\JsonRpc\Protocol\Error(-32000, 'Blocked'));
            }
        };

        $pipeline = new MiddlewarePipeline();
        $pipeline->add($mw);

        $result = $pipeline->process(
            $this->createRequest(),
            $this->context,
            function () use (&$handlerCalled): ?Response {
                $handlerCalled = true;
                return Response::success(1, 'ok');
            },
        );

        $this->assertFalse($handlerCalled);
        $this->assertNotNull($result);
        $this->assertEquals(-32000, $result->error->code);
    }

    public function testMiddlewarePassThrough(): void
    {
        $mw = new class implements MiddlewareInterface {
            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                return $next($request, $context);
            }
        };

        $pipeline = new MiddlewarePipeline();
        $pipeline->add($mw);

        $result = $pipeline->process(
            $this->createRequest(),
            $this->context,
            fn() => Response::success(1, ['status' => 'ok']),
        );

        $this->assertNotNull($result);
        $this->assertEquals(['status' => 'ok'], $result->result);
    }

    public function testIsEmpty(): void
    {
        $pipeline = new MiddlewarePipeline();
        $this->assertTrue($pipeline->isEmpty());
        $pipeline->add(new class implements MiddlewareInterface {
            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                return $next($request, $context);
            }
        });
        $this->assertFalse($pipeline->isEmpty());
    }
}
