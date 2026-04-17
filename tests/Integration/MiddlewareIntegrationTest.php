<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Middleware\MiddlewareInterface;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Protocol\Response;
use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class MiddlewareIntegrationTest extends TestCase
{
    use IntegrationTestCase;

    protected function setUp(): void
    {
        $this->initHandlerPath();
    }

    public function testMiddlewareExecutionOrder(): void
    {
        $order = [];

        $mw1 = new class($order) implements MiddlewareInterface {
            public function __construct(private array &$order) {}
            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                $this->order[] = 'mw1';
                return $next($request, $context);
            }
        };

        $mw2 = new class($order) implements MiddlewareInterface {
            public function __construct(private array &$order) {}
            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                $this->order[] = 'mw2';
                return $next($request, $context);
            }
        };

        $server = $this->createServer();
        $server->addMiddleware($mw1);
        $server->addMiddleware($mw2);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $server->handle($this->createRequest($body));

        $this->assertEquals(['mw1', 'mw2'], $order);
    }

    public function testMiddlewareShortCircuit(): void
    {
        $mw = new class implements MiddlewareInterface {
            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                return Response::error($request->id, new \Lumen\JsonRpc\Protocol\Error(-32000, 'Blocked by middleware'));
            }
        };

        $server = $this->createServer();
        $server->addMiddleware($mw);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);

        $this->assertEquals(-32000, $data['error']['code']);
        $this->assertEquals('Blocked by middleware', $data['error']['message']);
    }

    public function testMiddlewareExecutedPerRequestInBatch(): void
    {
        $count = 0;

        $mw = new class($count) implements MiddlewareInterface {
            public function __construct(private int &$count) {}
            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                $this->count++;
                return $next($request, $context);
            }
        };

        $server = $this->createServer();
        $server->addMiddleware($mw);

        $body = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'system.version', 'id' => 2],
            ['jsonrpc' => '2.0', 'method' => 'system.methods', 'id' => 3],
        ]);
        $server->handle($this->createRequest($body));

        $this->assertEquals(3, $count);
    }

    public function testPassThroughMiddlewarePreservesResult(): void
    {
        $mw = new class implements MiddlewareInterface {
            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                return $next($request, $context);
            }
        };

        $server = $this->createServer();
        $server->addMiddleware($mw);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals('ok', $data['result']['status']);
    }
}
