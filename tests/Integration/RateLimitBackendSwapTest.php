<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\RateLimit\InMemoryRateLimiter;
use Lumen\JsonRpc\Server\JsonRpcServer;
use PHPUnit\Framework\TestCase;

final class RateLimitBackendSwapTest extends TestCase
{
    private function createConfigWithRateLimit(): Config
    {
        return new Config([
            'handlers' => [
                'paths' => [__DIR__ . '/../../examples/basic/handlers'],
                'namespace' => 'App\\Handlers\\',
            ],
            'logging' => ['enabled' => false],
            'health' => ['enabled' => false],
            'auth' => ['enabled' => false],
            'rate_limit' => [
                'enabled' => true,
                'max_requests' => 100,
                'window_seconds' => 60,
                'strategy' => 'ip',
                'storage_path' => sys_get_temp_dir() . '/lumen_test_rl_' . uniqid(),
                'batch_weight' => 1,
                'fail_open' => true,
            ],
            'response_fingerprint' => ['enabled' => false],
            'compression' => ['response_gzip' => false],
        ]);
    }

    public function testDefaultBackendIsFileBased(): void
    {
        $config = $this->createConfigWithRateLimit();
        $server = new JsonRpcServer($config);

        $request = new HttpRequest(
            body: '{"jsonrpc":"2.0","method":"system.health","id":1}',
            headers: ['Content-Type' => 'application/json'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );

        $response = $server->handle($request);
        $this->assertSame(200, $response->statusCode);
    }

    public function testSwapToInMemoryBackendAtRuntime(): void
    {
        $config = $this->createConfigWithRateLimit();
        $server = new JsonRpcServer($config);

        $memoryLimiter = new InMemoryRateLimiter(2, 60);
        $server->setRateLimiter($memoryLimiter);

        $request = new HttpRequest(
            body: '{"jsonrpc":"2.0","method":"system.health","id":1}',
            headers: ['Content-Type' => 'application/json'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );

        $response1 = $server->handle($request);
        $this->assertSame(200, $response1->statusCode);

        $response2 = $server->handle($request);
        $this->assertSame(200, $response2->statusCode);

        $response3 = $server->handle($request);
        $this->assertSame(429, $response3->statusCode);
    }

    public function testSwapViaStableServerApi(): void
    {
        $config = $this->createConfigWithRateLimit();
        $server = new JsonRpcServer($config);

        $memoryLimiter = new InMemoryRateLimiter(1, 60);
        $server->setRateLimiter($memoryLimiter);

        $request = new HttpRequest(
            body: '{"jsonrpc":"2.0","method":"system.health","id":1}',
            headers: ['Content-Type' => 'application/json'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );

        $response1 = $server->handle($request);
        $this->assertSame(200, $response1->statusCode);

        $response2 = $server->handle($request);
        $this->assertSame(429, $response2->statusCode);
    }

    public function testInMemoryBackendResetAllowsNewRequests(): void
    {
        $config = $this->createConfigWithRateLimit();
        $server = new JsonRpcServer($config);

        $memoryLimiter = new InMemoryRateLimiter(1, 60);
        $server->setRateLimiter($memoryLimiter);

        $request = new HttpRequest(
            body: '{"jsonrpc":"2.0","method":"system.health","id":1}',
            headers: ['Content-Type' => 'application/json'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );

        $server->handle($request);
        $response = $server->handle($request);
        $this->assertSame(429, $response->statusCode);

        $memoryLimiter->reset('127.0.0.1');

        $response = $server->handle($request);
        $this->assertSame(200, $response->statusCode);
    }
}
