<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Exception\InvalidParamsException;
use Lumen\JsonRpc\Exception\JsonRpcException;
use Lumen\JsonRpc\Middleware\MiddlewareInterface;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Protocol\Response;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class MiddlewareExceptionTest extends TestCase
{
    use IntegrationTestCase;

    protected function setUp(): void
    {
        $this->initHandlerPath();
    }

    public function testMiddlewareThrowingJsonRpcExceptionProducesCleanResponse(): void
    {
        $mw = new class implements MiddlewareInterface {
            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                throw new InvalidParamsException('Custom validation failure');
            }
        };

        $server = $this->createServer();
        $server->addMiddleware($mw);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);

        $this->assertEquals(-32602, $data['error']['code']);
        $this->assertEquals('Invalid params', $data['error']['message']);
        $this->assertEquals(1, $data['id']);
    }

    public function testMiddlewareThrowingRuntimeExceptionProducesInternalError(): void
    {
        $mw = new class implements MiddlewareInterface {
            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                throw new \RuntimeException('Something unexpected broke');
            }
        };

        $server = $this->createServer();
        $server->addMiddleware($mw);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 2]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);

        $this->assertEquals(-32603, $data['error']['code']);
        $this->assertEquals('Internal error', $data['error']['message']);
        $this->assertEquals(2, $data['id']);
    }

    public function testMiddlewareThrowingGenericThrowableProducesInternalError(): void
    {
        $mw = new class implements MiddlewareInterface {
            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                throw new \ErrorException('Generic error');
            }
        };

        $server = $this->createServer();
        $server->addMiddleware($mw);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 3]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);

        $this->assertEquals(-32603, $data['error']['code']);
        $this->assertEquals('Internal error', $data['error']['message']);
        $this->assertEquals(3, $data['id']);
    }

    public function testMiddlewareExceptionInBatchDoesNotBreakOtherRequests(): void
    {
        $failingMethods = ['system.health'];

        $mw = new class($failingMethods) implements MiddlewareInterface {
            public function __construct(private array $failingMethods) {}

            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                if (in_array($request->method, $this->failingMethods, true)) {
                    throw new \RuntimeException('Middleware failure');
                }
                return $next($request, $context);
            }
        };

        $server = $this->createServer();
        $server->addMiddleware($mw);

        $body = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'system.version', 'id' => 2],
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);

        $this->assertCount(2, $data);

        $byId = [];
        foreach ($data as $item) {
            $byId[$item['id']] = $item;
        }

        $this->assertEquals(-32603, $byId[1]['error']['code']);
        $this->assertArrayHasKey('result', $byId[2]);
    }

    public function testMiddlewareExceptionOnNotificationReturnsNullNoCrash(): void
    {
        $mw = new class implements MiddlewareInterface {
            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                throw new \RuntimeException('Fail silently');
            }
        };

        $server = $this->createServer();
        $server->addMiddleware($mw);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health']);
        $response = $server->handle($this->createRequest($body));

        $this->assertEquals(204, $response->statusCode);
    }
}
