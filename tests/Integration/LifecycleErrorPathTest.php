<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Exception\InvalidParamsException;
use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\HookPoint;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class LifecycleErrorPathTest extends TestCase
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

    public function testMethodNotFoundErrorFiresOnErrorHook(): void
    {
        $collected = (object)['errors' => []];

        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $server->getHooks()->register(HookPoint::ON_ERROR, function (array $ctx) use ($collected) {
            $collected->errors[] = $ctx['reason'] ?? 'unknown';
            return [];
        });

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"nonexistent.method","id":1}'
        );
        $decoded = json_decode($result, true);

        $this->assertSame(-32601, $decoded['error']['code']);
        $this->assertNotEmpty($collected->errors);
    }

    public function testInvalidParamsErrorFiresOnErrorHook(): void
    {
        $collected = (object)['errors' => []];

        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $server->getHooks()->register(HookPoint::ON_ERROR, function (array $ctx) use ($collected) {
            $collected->errors[] = $ctx['reason'] ?? 'unknown';
            return [];
        });

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"user.get","params":{"id":"not-a-number"},"id":1}'
        );
        $decoded = json_decode($result, true);

        $this->assertSame(-32602, $decoded['error']['code']);
        $this->assertNotEmpty($collected->errors);
    }

    public function testAuthFailureOnProtectedMethodFiresHooks(): void
    {
        $hookLog = [];

        $config = $this->createConfig([
            'auth' => [
                'enabled' => true,
                'driver' => 'api_key',
                'protected_methods' => ['user.'],
                'api_key' => [
                    'header' => 'X-API-Key',
                    'keys' => [
                        'valid-key' => ['user_id' => 'user1', 'roles' => ['user']],
                    ],
                ],
            ],
        ]);

        $server = new JsonRpcServer($config);

        $server->getHooks()->register(HookPoint::ON_ERROR, function (array $ctx) use (&$hookLog) {
            $hookLog[] = 'ON_ERROR:' . ($ctx['reason'] ?? 'unknown');
            return [];
        });

        $server->getHooks()->register(HookPoint::ON_AUTH_FAILURE, function () use (&$hookLog) {
            $hookLog[] = 'ON_AUTH_FAILURE';
            return [];
        });

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"user.get","params":{"id":1},"id":1}'
        );
        $decoded = json_decode($result, true);

        $this->assertSame(-32001, $decoded['error']['code']);
        $this->assertContains('ON_ERROR:auth_required', $hookLog);
    }

    public function testHandlerExceptionFiresOnErrorHook(): void
    {
        $collected = (object)['errors' => []];

        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $server->getHooks()->register(HookPoint::ON_ERROR, function (array $ctx) use ($collected) {
            $collected->errors[] = $ctx;
            return [];
        });

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"system.health","id":1}'
        );

        $decoded = json_decode($result, true);
        $this->assertArrayHasKey('result', $decoded);
        $this->assertEmpty($collected->errors);
    }

    public function testBeforeRequestFiresEvenOnError(): void
    {
        $hookLog = [];

        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, function () use (&$hookLog) {
            $hookLog[] = 'BEFORE_REQUEST';
            return [];
        });

        $server->getHooks()->register(HookPoint::AFTER_REQUEST, function () use (&$hookLog) {
            $hookLog[] = 'AFTER_REQUEST';
            return [];
        });

        $result = $server->handleJson('{invalid}');
        $decoded = json_decode($result, true);
        $this->assertSame(-32700, $decoded['error']['code']);

        $this->assertContains('BEFORE_REQUEST', $hookLog);
        $this->assertContains('AFTER_REQUEST', $hookLog);
    }
}
