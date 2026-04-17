<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Spec;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\Http\HttpResponse;
use Lumen\JsonRpc\Server\JsonRpcServer;
use PHPUnit\Framework\TestCase;

final class SpecComplianceTest extends TestCase
{
    private JsonRpcServer $server;

    protected function setUp(): void
    {
        $handlerPath = realpath(__DIR__ . '/../../examples/basic/handlers') ?: __DIR__ . '/../../examples/basic/handlers';
        $config = new Config([
            'handlers' => [
                'paths' => [$handlerPath],
                'namespace' => 'App\\Handlers\\',
            ],
            'debug' => false,
            'logging' => ['enabled' => false],
            'auth' => ['enabled' => false],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
        ]);
        $this->server = new JsonRpcServer($config);
    }

    private function request(string $body): HttpRequest
    {
        return new HttpRequest(
            body: $body,
            headers: ['Content-Type' => 'application/json'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );
    }

    private function parseResponse(HttpResponse $response): array
    {
        return json_decode($response->body, true);
    }

    public function testSpecExampleRpcCallWithInvalidJson(): void
    {
        $body = '{"jsonrpc": "2.0", "method": "foobar, "params": "bar", "baz]';
        $response = $this->server->handle($this->request($body));
        $data = $this->parseResponse($response);
        $this->assertEquals('2.0', $data['jsonrpc']);
        $this->assertEquals(-32700, $data['error']['code']);
        $this->assertEquals('Parse error', $data['error']['message']);
        $this->assertNull($data['id']);
    }

    public function testSpecExampleInvalidRequestObject(): void
    {
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 1, 'params' => 'bar']);
        $response = $this->server->handle($this->request($body));
        $data = $this->parseResponse($response);
        $this->assertEquals('2.0', $data['jsonrpc']);
        $this->assertEquals(-32600, $data['error']['code']);
        $this->assertEquals('Invalid Request', $data['error']['message']);
        $this->assertNull($data['id']);
    }

    public function testSpecExampleMethodNotFound(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'nonexist.method',
            'id' => '1',
        ]);
        $response = $this->server->handle($this->request($body));
        $data = $this->parseResponse($response);
        $this->assertEquals('2.0', $data['jsonrpc']);
        $this->assertEquals(-32601, $data['error']['code']);
        $this->assertEquals('Method not found', $data['error']['message']);
        $this->assertEquals('1', $data['id']);
    }

    public function testSpecExampleBatchInvalidJson(): void
    {
        $body = '[{"jsonrpc": "2.0", "method": "sum", "params": [1,2,4], "id": "1"},{"jsonrpc": "2.0", "method"';
        $response = $this->server->handle($this->request($body));
        $data = $this->parseResponse($response);
        $this->assertEquals('2.0', $data['jsonrpc']);
        $this->assertEquals(-32700, $data['error']['code']);
        $this->assertNull($data['id']);
    }

    public function testSpecExampleEmptyArray(): void
    {
        $body = json_encode([]);
        $response = $this->server->handle($this->request($body));
        $data = $this->parseResponse($response);
        $this->assertEquals('2.0', $data['jsonrpc']);
        $this->assertEquals(-32600, $data['error']['code']);
        $this->assertNull($data['id']);
    }

    public function testSpecExampleInvalidBatchSingleItem(): void
    {
        $body = json_encode([1]);
        $response = $this->server->handle($this->request($body));
        $data = $this->parseResponse($response);
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertEquals(-32600, $data[0]['error']['code']);
        $this->assertNull($data[0]['id']);
    }

    public function testSpecExampleInvalidBatchMultipleItems(): void
    {
        $body = json_encode([1, 2, 3]);
        $response = $this->server->handle($this->request($body));
        $data = $this->parseResponse($response);
        $this->assertCount(3, $data);
        foreach ($data as $item) {
            $this->assertEquals(-32600, $item['error']['code']);
            $this->assertNull($item['id']);
        }
    }

    public function testSpecBatchMixedValidInvalidNotifications(): void
    {
        $body = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => '1'],
            ['jsonrpc' => '2.0', 'method' => 'system.version', 'params' => [7]],
            ['jsonrpc' => '2.0', 'method' => 'system.version', 'id' => '2'],
            ['foo' => 'boo'],
            ['jsonrpc' => '2.0', 'method' => 'nonexist.get', 'params' => ['name' => 'myself'], 'id' => '5'],
            ['jsonrpc' => '2.0', 'method' => 'system.methods', 'id' => '9'],
        ]);
        $response = $this->server->handle($this->request($body));
        $data = $this->parseResponse($response);

        $ids = array_filter(array_column($data, 'id'), fn($id) => $id !== null);
        sort($ids);
        $this->assertContains('1', $ids);
        $this->assertContains('2', $ids);
        $this->assertContains('5', $ids);
        $this->assertContains('9', $ids);

        $hasInvalidRequest = false;
        foreach ($data as $item) {
            if (isset($item['error']) && $item['error']['code'] === -32600 && $item['id'] === null) {
                $hasInvalidRequest = true;
            }
        }
        $this->assertTrue($hasInvalidRequest);
    }

    public function testSpecBatchAllNotificationsReturnsNothing(): void
    {
        $body = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'params' => [1, 2, 4]],
            ['jsonrpc' => '2.0', 'method' => 'system.version', 'params' => [7]],
        ]);
        $response = $this->server->handle($this->request($body));
        $this->assertEquals(204, $response->statusCode);
        $this->assertEquals('', $response->body);
    }

    public function testSpecNotificationProducesNoResponse(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'system.health',
            'params' => [1, 2, 3, 4, 5],
        ]);
        $response = $this->server->handle($this->request($body));
        $this->assertEquals(204, $response->statusCode);
    }

    public function testSpecResponseHasEitherResultOrError(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'system.health',
            'id' => 1,
        ]);
        $response = $this->server->handle($this->request($body));
        $data = $this->parseResponse($response);
        $hasResult = array_key_exists('result', $data);
        $hasError = array_key_exists('error', $data);
        $this->assertTrue($hasResult !== $hasError);
    }

    public function testSpecResponseIncludesJsonrpcVersion(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'system.health',
            'id' => 1,
        ]);
        $response = $this->server->handle($this->request($body));
        $data = $this->parseResponse($response);
        $this->assertEquals('2.0', $data['jsonrpc']);
    }

    public function testSpecResponseIdMatchesRequestId(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'system.health',
            'id' => 42,
        ]);
        $response = $this->server->handle($this->request($body));
        $data = $this->parseResponse($response);
        $this->assertEquals(42, $data['id']);
    }

    public function testSpecStringIdIsPreserved(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'system.health',
            'id' => 'abc-123',
        ]);
        $response = $this->server->handle($this->request($body));
        $data = $this->parseResponse($response);
        $this->assertEquals('abc-123', $data['id']);
    }


}
