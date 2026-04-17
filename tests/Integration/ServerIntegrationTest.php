<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\Server\JsonRpcServer;
use PHPUnit\Framework\TestCase;

final class ServerIntegrationTest extends TestCase
{
    private JsonRpcServer $server;
    private string $handlerPath;

    protected function setUp(): void
    {
        $this->handlerPath = realpath(__DIR__ . '/../../examples/basic/handlers') ?: __DIR__ . '/../../examples/basic/handlers';
        $config = new Config([
            'handlers' => [
                'paths' => [$this->handlerPath],
                'namespace' => 'App\\Handlers\\',
            ],
            'debug' => true,
            'logging' => ['enabled' => false],
            'auth' => ['enabled' => false],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
        ]);
        $this->server = new JsonRpcServer($config);
    }

    private function createRequest(string $body, string $method = 'POST'): HttpRequest
    {
        return new HttpRequest(
            body: $body,
            headers: ['Content-Type' => 'application/json'],
            method: $method,
            clientIp: '127.0.0.1',
            server: [],
        );
    }

    public function testValidRequest(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'system.health',
            'id' => 1,
        ]);
        $response = $this->server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals('2.0', $data['jsonrpc']);
        $this->assertEquals(1, $data['id']);
        $this->assertArrayHasKey('result', $data);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testInvalidJson(): void
    {
        $body = '{"jsonrpc": "2.0", "method": "foobar, "params": "bar", "baz]';
        $response = $this->server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32700, $data['error']['code']);
        $this->assertNull($data['id']);
    }

    public function testInvalidRequestObject(): void
    {
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 1, 'params' => 'bar']);
        $response = $this->server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testMethodNotFound(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'foobar',
            'id' => '1',
        ]);
        $response = $this->server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32601, $data['error']['code']);
        $this->assertEquals('1', $data['id']);
    }

    public function testMethodWithParams(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'system.methods',
            'id' => 5,
        ]);
        $response = $this->server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
        $this->assertIsArray($data['result']);
    }

    public function testNotificationReturnsNoContent(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'system.health',
        ]);
        $response = $this->server->handle($this->createRequest($body));
        $this->assertEquals(204, $response->statusCode);
        $this->assertEquals('', $response->body);
    }

    public function testBatchRequest(): void
    {
        $body = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'system.version', 'id' => 2],
            ['jsonrpc' => '2.0', 'method' => 'system.methods', 'id' => 3],
        ]);
        $response = $this->server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertIsArray($data);
        $this->assertCount(3, $data);
        $ids = array_column($data, 'id');
        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
        $this->assertContains(3, $ids);
    }

    public function testBatchWithMixedValidInvalid(): void
    {
        $body = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
            ['foo' => 'boo'],
            ['jsonrpc' => '2.0', 'method' => 'nonexist.method', 'id' => 5],
        ]);
        $response = $this->server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertIsArray($data);
        $this->assertCount(3, $data);

        $byId = [];
        foreach ($data as $item) {
            $byId[$item['id']] = $item;
        }

        $this->assertArrayHasKey('result', $byId[1]);
        $this->assertEquals(-32600, $byId[null]['error']['code']);
        $this->assertEquals(-32601, $byId[5]['error']['code']);
    }

    public function testBatchAllNotificationsReturnsNoContent(): void
    {
        $body = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health'],
            ['jsonrpc' => '2.0', 'method' => 'system.version'],
        ]);
        $response = $this->server->handle($this->createRequest($body));
        $this->assertEquals(204, $response->statusCode);
    }

    public function testEmptyBatchRequest(): void
    {
        $body = json_encode([]);
        $response = $this->server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
        $this->assertNull($data['id']);
    }

    public function testInvalidBatchItems(): void
    {
        $body = json_encode([1, 2, 3]);
        $response = $this->server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertCount(3, $data);
        foreach ($data as $item) {
            $this->assertEquals(-32600, $item['error']['code']);
        }
    }

    public function testGetRequestReturnsHealth(): void
    {
        $response = $this->server->handle($this->createRequest('', 'GET'));
        $this->assertEquals(200, $response->statusCode);
        $data = json_decode($response->body, true);
        $this->assertEquals('ok', $data['status']);
    }

    public function testReservedRpcMethodPrefix(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'rpc.reserved',
            'id' => 1,
        ]);
        $response = $this->server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32601, $data['error']['code']);
    }

    public function testResponseHasNoResultAndErrorSimultaneously(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'system.health',
            'id' => 1,
        ]);
        $response = $this->server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $hasResult = array_key_exists('result', $data);
        $hasError = array_key_exists('error', $data);
        $this->assertTrue($hasResult xor $hasError);
    }
}
