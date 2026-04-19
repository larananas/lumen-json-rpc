<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\RateLimit\RateLimiterInterface;
use Lumen\JsonRpc\RateLimit\RateLimitResult;
use Lumen\JsonRpc\Server\JsonRpcServer;
use PHPUnit\Framework\TestCase;

final class CustomRateLimiterTest extends TestCase
{
    private function createConfig(array $overrides = []): Config
    {
        return new Config(array_merge([
            'handlers' => [
                'paths' => [__DIR__ . '/../../examples/basic/handlers'],
                'namespace' => 'App\\Handlers\\',
            ],
            'logging' => ['enabled' => false],
            'health' => ['enabled' => false],
            'auth' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
            'compression' => ['response_gzip' => false],
            'rate_limit' => [
                'enabled' => true,
                'max_requests' => 100,
                'window_seconds' => 60,
                'strategy' => 'ip',
                'storage_path' => sys_get_temp_dir() . '/lumen_test_rl_' . uniqid(),
                'batch_weight' => 1,
            ],
        ], $overrides));
    }

    public function testCustomBackendReplacesDefaultBehavior(): void
    {
        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $callCount = (object)['count' => 0];
        $customLimiter = new class ($callCount) implements RateLimiterInterface {
            public function __construct(private object $state) {}

            public function check(string $key): RateLimitResult
            {
                return $this->checkAndConsume($key, 1);
            }

            public function checkAndConsume(string $key, int $weight): RateLimitResult
            {
                $this->state->count++;
                return RateLimitResult::denied(time() + 60, 100);
            }
        };

        $server->setRateLimiter($customLimiter);

        $request = new HttpRequest(
            '{"jsonrpc":"2.0","method":"system.health","id":1}',
            ['Content-Type' => 'application/json'],
            'POST',
            '127.0.0.1',
            []
        );

        $response = $server->handle($request);

        $this->assertEquals(429, $response->statusCode);
        $this->assertEquals(1, $callCount->count);
    }

    public function testCustomBackendAllowsWhenPermitted(): void
    {
        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $customLimiter = new class implements RateLimiterInterface {
            public function check(string $key): RateLimitResult
            {
                return RateLimitResult::allowed(99, time() + 60, 100);
            }

            public function checkAndConsume(string $key, int $weight): RateLimitResult
            {
                return RateLimitResult::allowed(99, time() + 60, 100);
            }
        };

        $server->setRateLimiter($customLimiter);

        $request = new HttpRequest(
            '{"jsonrpc":"2.0","method":"system.health","id":1}',
            ['Content-Type' => 'application/json'],
            'POST',
            '127.0.0.1',
            []
        );

        $response = $server->handle($request);

        $this->assertEquals(200, $response->statusCode);
        $decoded = json_decode($response->body, true);
        $this->assertEquals('ok', $decoded['result']['status']);
    }

    public function testSwapPathViaEngineGetter(): void
    {
        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $customLimiter = new class implements RateLimiterInterface {
            private bool $called = false;

            public function check(string $key): RateLimitResult
            {
                return $this->checkAndConsume($key, 1);
            }

            public function checkAndConsume(string $key, int $weight): RateLimitResult
            {
                $this->called = true;
                return RateLimitResult::allowed(99, time() + 60, 100);
            }
        };

        $server->getEngine()->getRateLimitManager()->setLimiter($customLimiter);

        $json = $server->handleJson(
            '{"jsonrpc":"2.0","method":"system.health","id":1}'
        );

        $decoded = json_decode($json, true);
        $this->assertEquals('ok', $decoded['result']['status']);
    }
}
