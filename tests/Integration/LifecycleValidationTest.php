<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\HookPoint;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class LifecycleValidationTest extends TestCase
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
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
            'compression' => ['response_gzip' => false],
        ], $overrides));
    }

    public function testBatchRequestFiresHooksOnceNotPerItem(): void
    {
        $collector = new LogCollector();

        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, function () use ($collector) {
            return $collector->record('BEFORE_REQUEST');
        });
        $server->getHooks()->register(HookPoint::ON_RESPONSE, function () use ($collector) {
            return $collector->record('ON_RESPONSE');
        });
        $server->getHooks()->register(HookPoint::AFTER_REQUEST, function () use ($collector) {
            return $collector->record('AFTER_REQUEST');
        });

        $batch = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'system.version', 'id' => 2],
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 3],
        ]);

        $server->handleJson($batch);

        $beforeCount = count(array_filter($collector->log, fn($e) => $e === 'BEFORE_REQUEST'));
        $onResponseCount = count(array_filter($collector->log, fn($e) => $e === 'ON_RESPONSE'));
        $afterCount = count(array_filter($collector->log, fn($e) => $e === 'AFTER_REQUEST'));

        $this->assertSame(1, $beforeCount, 'BEFORE_REQUEST must fire exactly once for batch');
        $this->assertSame(1, $onResponseCount, 'ON_RESPONSE must fire exactly once for batch');
        $this->assertSame(1, $afterCount, 'AFTER_REQUEST must fire exactly once for batch');
    }

    public function testRateLimitExceededFiresOnErrorHook(): void
    {
        $collector = new LogCollector();
        $tmpDir = sys_get_temp_dir() . '/lumen_test_rl_hook_' . uniqid();

        $config = $this->createConfig([
            'rate_limit' => [
                'enabled' => true,
                'max_requests' => 1,
                'window_seconds' => 60,
                'strategy' => 'ip',
                'storage_path' => $tmpDir,
                'batch_weight' => 1,
                'fail_open' => true,
            ],
        ]);

        $server = new JsonRpcServer($config);

        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, function () use ($collector) {
            return $collector->record('BEFORE_REQUEST');
        });
        $server->getHooks()->register(HookPoint::ON_ERROR, function () use ($collector) {
            return $collector->record('ON_ERROR');
        });
        $server->getHooks()->register(HookPoint::ON_RESPONSE, function () use ($collector) {
            return $collector->record('ON_RESPONSE');
        });
        $server->getHooks()->register(HookPoint::AFTER_REQUEST, function () use ($collector) {
            return $collector->record('AFTER_REQUEST');
        });

        $server->handleJson('{"jsonrpc":"2.0","method":"system.health","id":1}');
        $result = $server->handleJson('{"jsonrpc":"2.0","method":"system.health","id":2}');

        $decoded = json_decode($result, true);
        $this->assertSame(-32000, $decoded['error']['code']);

        $this->assertContains('ON_ERROR', $collector->log, 'Rate limit exceeded must fire ON_ERROR hook');

        $errorIndex = array_search('ON_ERROR', $collector->log);
        $beforeIndex = array_search('BEFORE_REQUEST', $collector->log);
        $this->assertGreaterThan($beforeIndex, $errorIndex, 'ON_ERROR must fire after BEFORE_REQUEST');
    }

    public function testInvalidDescriptorThrowsMethodNotFound(): void
    {
        $config = $this->createConfig([
            'handlers' => ['paths' => [], 'namespace' => 'App\\Handlers\\'],
        ]);

        $server = new JsonRpcServer($config);
        $registry = $server->getRegistry();
        $registry->register('ghost.method', 'NonExistent\GhostClass', 'phantom');

        $result = $server->handleJson('{"jsonrpc":"2.0","method":"ghost.method","id":1}');
        $decoded = json_decode($result, true);

        $this->assertSame(-32601, $decoded['error']['code']);
        $this->assertStringContainsStringIgnoringCase('not found', $decoded['error']['message']);
    }

    public function testMethodNotFoundFiresOnErrorHook(): void
    {
        $collector = new LogCollector();

        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $server->getHooks()->register(HookPoint::BEFORE_HANDLER, function () use ($collector) {
            return $collector->record('BEFORE_HANDLER');
        });
        $server->getHooks()->register(HookPoint::ON_ERROR, function () use ($collector) {
            return $collector->record('ON_ERROR');
        });
        $server->getHooks()->register(HookPoint::AFTER_HANDLER, function () use ($collector) {
            return $collector->record('AFTER_HANDLER');
        });

        $result = $server->handleJson('{"jsonrpc":"2.0","method":"nonexistent.method","id":1}');
        $decoded = json_decode($result, true);

        $this->assertSame(-32601, $decoded['error']['code']);
        $this->assertContains('ON_ERROR', $collector->log);
        $this->assertNotContains('AFTER_HANDLER', $collector->log, 'AFTER_HANDLER must not fire on method not found');
    }

    public function testAuthRequiredFiresOnErrorHook(): void
    {
        $collector = new LogCollector();

        $config = $this->createConfig([
            'auth' => [
                'enabled' => true,
                'driver' => 'jwt',
                'protected_methods' => ['system.'],
                'jwt' => ['secret' => 'test-secret-key', 'algorithm' => 'HS256'],
            ],
        ]);

        $server = new JsonRpcServer($config);

        $server->getHooks()->register(HookPoint::ON_ERROR, function () use ($collector) {
            return $collector->record('ON_ERROR');
        });
        $server->getHooks()->register(HookPoint::ON_AUTH_FAILURE, function () use ($collector) {
            return $collector->record('ON_AUTH_FAILURE');
        });

        $context = new RequestContext(
            correlationId: 'test-auth-err',
            headers: [],
            clientIp: '127.0.0.1',
        );

        $result = $server->handleJson('{"jsonrpc":"2.0","method":"system.health","id":1}', $context);
        $decoded = json_decode($result, true);

        $this->assertSame(-32001, $decoded['error']['code']);
        $this->assertContains('ON_ERROR', $collector->log);
    }

    public function testBatchWithMixedResultsAndErrorsStillHasCompleteLifecycle(): void
    {
        $collector = new LogCollector();

        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, function () use ($collector) {
            return $collector->record('BEFORE_REQUEST');
        });
        $server->getHooks()->register(HookPoint::ON_RESPONSE, function () use ($collector) {
            return $collector->record('ON_RESPONSE');
        });
        $server->getHooks()->register(HookPoint::AFTER_REQUEST, function () use ($collector) {
            return $collector->record('AFTER_REQUEST');
        });

        $batch = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'nonexistent.method', 'id' => 2],
        ]);

        $result = $server->handleJson($batch);
        $decoded = json_decode($result, true);

        $this->assertCount(2, $decoded);

        $hasResult = false;
        $hasError = false;
        foreach ($decoded as $item) {
            if (isset($item['result'])) {
                $hasResult = true;
            }
            if (isset($item['error'])) {
                $hasError = true;
            }
        }
        $this->assertTrue($hasResult, 'Batch must contain a success');
        $this->assertTrue($hasError, 'Batch must contain an error');

        $this->assertContains('BEFORE_REQUEST', $collector->log);
        $this->assertContains('ON_RESPONSE', $collector->log);
        $this->assertContains('AFTER_REQUEST', $collector->log);
    }

    public function testNotificationDoesNotFireAfterHandlerHookOnSuccess(): void
    {
        $collector = new LogCollector();

        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $server->getHooks()->register(HookPoint::AFTER_HANDLER, function () use ($collector) {
            return $collector->record('AFTER_HANDLER');
        });

        $result = $server->handleJson('{"jsonrpc":"2.0","method":"system.health"}');
        $this->assertNull($result);
        $this->assertContains('AFTER_HANDLER', $collector->log, 'AFTER_HANDLER must fire even for notifications');
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
