<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\RateLimit\InMemoryRateLimiter;
use Lumen\JsonRpc\Server\JsonRpcServer;
use PHPUnit\Framework\TestCase;

final class BatchSecurityInteractionTest extends TestCase
{
    private function createServer(array $overrides = []): JsonRpcServer
    {
        return new JsonRpcServer(new Config(array_merge([
            'handlers' => [
                'paths' => [__DIR__ . '/../../examples/basic/handlers'],
                'namespace' => 'App\\Handlers\\',
            ],
            'logging' => ['enabled' => false],
            'health' => ['enabled' => false],
            'auth' => ['enabled' => false],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
            'compression' => ['response_gzip' => false],
        ], $overrides)));
    }

    public function testBatchRateLimitInteraction(): void
    {
        $tmpDir = sys_get_temp_dir() . '/lumen_test_batch_rl_' . uniqid();
        $server = $this->createServer([
            'rate_limit' => [
                'enabled' => true,
                'max_requests' => 5,
                'window_seconds' => 60,
                'strategy' => 'ip',
                'storage_path' => $tmpDir,
                'batch_weight' => 1,
            ],
        ]);

        $memoryLimiter = new InMemoryRateLimiter(5, 60);
        $server->setRateLimiter($memoryLimiter);

        $batch = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 2],
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 3],
        ]);

        $request = new HttpRequest(
            $batch,
            ['Content-Type' => 'application/json'],
            'POST',
            '127.0.0.1',
            []
        );

        $response = $server->handle($request);
        $this->assertEquals(200, $response->statusCode);

        $response2 = $server->handle($request);
        $this->assertEquals(429, $response2->statusCode);
    }

    public function testContentTypeEnforcementRejectsNonJson(): void
    {
        $server = $this->createServer([
            'content_type' => ['strict' => true],
        ]);

        $request = new HttpRequest(
            '{"jsonrpc":"2.0","method":"system.health","id":1}',
            ['Content-Type' => 'text/plain'],
            'POST',
            '127.0.0.1',
            []
        );

        $response = $server->handle($request);
        $decoded = json_decode($response->body, true);

        $this->assertEquals(200, $response->statusCode);
        $this->assertEquals(-32600, $decoded['error']['code']);
        $this->assertStringContainsString('application/json', $decoded['error']['data']);
    }

    public function testContentTypeAcceptsJson(): void
    {
        $server = $this->createServer([
            'content_type' => ['strict' => true],
        ]);

        $request = new HttpRequest(
            '{"jsonrpc":"2.0","method":"system.health","id":1}',
            ['Content-Type' => 'application/json'],
            'POST',
            '127.0.0.1',
            []
        );

        $response = $server->handle($request);
        $decoded = json_decode($response->body, true);

        $this->assertEquals('ok', $decoded['result']['status']);
    }

    public function testContentTypeWithCharsetStillAccepted(): void
    {
        $server = $this->createServer([
            'content_type' => ['strict' => true],
        ]);

        $request = new HttpRequest(
            '{"jsonrpc":"2.0","method":"system.health","id":1}',
            ['Content-Type' => 'application/json; charset=utf-8'],
            'POST',
            '127.0.0.1',
            []
        );

        $response = $server->handle($request);
        $decoded = json_decode($response->body, true);

        $this->assertEquals('ok', $decoded['result']['status']);
    }

    public function testGetRequestReturnsHealthWhenEnabled(): void
    {
        $server = $this->createServer(['health' => ['enabled' => true]]);

        $request = new HttpRequest(
            '',
            [],
            'GET',
            '127.0.0.1',
            []
        );

        $response = $server->handle($request);
        $decoded = json_decode($response->body, true);

        $this->assertEquals(200, $response->statusCode);
        $this->assertEquals('ok', $decoded['status']);
    }

    public function testGetRequestReturns405WhenHealthDisabled(): void
    {
        $server = $this->createServer(['health' => ['enabled' => false]]);

        $request = new HttpRequest(
            '',
            [],
            'GET',
            '127.0.0.1',
            []
        );

        $response = $server->handle($request);
        $this->assertEquals(405, $response->statusCode);
    }

    public function testNotificationInBatchProducesNoResponse(): void
    {
        $server = $this->createServer();

        $batch = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health'],
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 2],
        ]);

        $json = $server->handleJson($batch);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertEquals(2, $decoded[0]['id']);
    }

    public function testBatchOfOnlyNotificationsReturnsNull(): void
    {
        $server = $this->createServer();

        $batch = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health'],
            ['jsonrpc' => '2.0', 'method' => 'system.version'],
        ]);

        $json = $server->handleJson($batch);
        $this->assertNull($json);
    }

    public function testEmptyBatchReturnsSingleError(): void
    {
        $server = $this->createServer();

        $json = $server->handleJson('[]');
        $decoded = json_decode($json, true);

        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals(-32600, $decoded['error']['code']);
        $this->assertNull($decoded['id']);
    }

    public function testBatchWithAllMalformedItems(): void
    {
        $server = $this->createServer();

        $batch = json_encode([1, 'hello', true, null]);
        $json = $server->handleJson($batch);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertCount(4, $decoded);
        foreach ($decoded as $item) {
            $this->assertEquals(-32600, $item['error']['code']);
        }
    }
}
