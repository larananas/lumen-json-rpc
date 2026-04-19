<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Http\HttpRequest;
use PHPUnit\Framework\TestCase;

final class BatchLimitTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = new Config([
            'handlers' => [
                'paths' => [__DIR__ . '/../../examples/basic/handlers'],
                'namespace' => 'App\\Handlers\\',
            ],
            'logging' => ['enabled' => false],
            'hooks' => ['enabled' => false],
            'health' => ['enabled' => false],
            'auth' => ['enabled' => false],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
            'compression' => ['response_gzip' => false],
        ]);
    }

    public function testBatchUnderLimitSucceeds(): void
    {
        $config = new Config(array_merge($this->config->all(), [
            'batch' => ['max_items' => 5],
        ]));

        $server = new JsonRpcServer($config);

        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $items[] = [
                'jsonrpc' => '2.0',
                'method' => 'system.health',
                'id' => $i,
            ];
        }

        $result = $server->handleJson(json_encode($items));
        $decoded = json_decode($result, true);

        $this->assertIsArray($decoded);
        $this->assertCount(3, $decoded);
    }

    public function testBatchExactlyAtLimitSucceeds(): void
    {
        $config = new Config(array_merge($this->config->all(), [
            'batch' => ['max_items' => 3],
        ]));

        $server = new JsonRpcServer($config);

        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $items[] = [
                'jsonrpc' => '2.0',
                'method' => 'system.health',
                'id' => $i,
            ];
        }

        $result = $server->handleJson(json_encode($items));
        $decoded = json_decode($result, true);

        $this->assertIsArray($decoded);
        $this->assertCount(3, $decoded);
    }

    public function testBatchOverLimitReturnsInvalidRequest(): void
    {
        $config = new Config(array_merge($this->config->all(), [
            'batch' => ['max_items' => 2],
        ]));

        $server = new JsonRpcServer($config);

        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $items[] = [
                'jsonrpc' => '2.0',
                'method' => 'system.health',
                'id' => $i,
            ];
        }

        $result = $server->handleJson(json_encode($items));
        $decoded = json_decode($result, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertSame(-32600, $decoded['error']['code']);
        $this->assertStringContainsString('2', $decoded['error']['data'] ?? '');
    }

    public function testEmptyBatchReturnsInvalidRequest(): void
    {
        $server = new JsonRpcServer($this->config);

        $result = $server->handleJson('[]');
        $decoded = json_decode($result, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertSame(-32600, $decoded['error']['code']);
    }

    public function testBatchWithNotifications(): void
    {
        $config = new Config(array_merge($this->config->all(), [
            'batch' => ['max_items' => 5],
        ]));

        $server = new JsonRpcServer($config);

        $items = [
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'system.health'],
        ];

        $result = $server->handleJson(json_encode($items));
        $decoded = json_decode($result, true);

        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertSame(1, $decoded[0]['id']);
    }

    public function testBatchOfOnlyNotificationsReturnsNull(): void
    {
        $server = new JsonRpcServer($this->config);

        $items = [
            ['jsonrpc' => '2.0', 'method' => 'system.health'],
            ['jsonrpc' => '2.0', 'method' => 'system.health'],
        ];

        $result = $server->handleJson(json_encode($items));
        $this->assertNull($result);
    }

    public function testInvalidConfigMaxItemsZeroThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        new JsonRpcServer(new Config(array_merge($this->config->all(), [
            'batch' => ['max_items' => 0],
        ])));
    }
}
