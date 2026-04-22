<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class CoreDirectUsageTest extends TestCase
{
    use IntegrationTestCase;

    protected function setUp(): void
    {
        $this->initHandlerPath();
    }

    public function testDirectJsonCallWithoutHttp(): void
    {
        $server = $this->createServer();
        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handleJson($json);

        $this->assertNotNull($response);
        $data = json_decode($response, true);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testDirectBatchWithoutHttp(): void
    {
        $server = $this->createServer();
        $json = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'system.version', 'id' => 2],
        ]);
        $response = $server->handleJson($json);

        $this->assertNotNull($response);
        $data = json_decode($response, true);
        $this->assertCount(2, $data);
    }

    public function testDirectNotificationReturnsNull(): void
    {
        $server = $this->createServer();
        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health']);
        $response = $server->handleJson($json);
        $this->assertNull($response);
    }

    public function testDirectJsonUsageWithCustomContext(): void
    {
        $server = $this->createServer();

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $context = new RequestContext(
            correlationId: 'direct-test',
            headers: [],
            clientIp: '127.0.0.1',
        );
        $response = $server->handleJson($json, $context);

        $this->assertNotNull($response);
        $data = json_decode($response, true);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testDirectJsonUsagePreservesCustomContextAttributes(): void
    {
        $server = $this->createServer();

        $context = new RequestContext(
            correlationId: 'test-ctx',
            headers: ['X-Custom' => 'value'],
            clientIp: '10.0.0.1',
            attributes: ['custom' => 'data'],
        );

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $server->handleJson($json, $context);

        $this->assertNotNull($result);
    }
}
