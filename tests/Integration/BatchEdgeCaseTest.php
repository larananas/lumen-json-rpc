<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Server\JsonRpcServer;
use PHPUnit\Framework\TestCase;

final class BatchEdgeCaseTest extends TestCase
{
    private function createConfig(int $maxItems = 100, array $overrides = []): Config
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
            'batch' => ['max_items' => $maxItems],
        ], $overrides));
    }

    public function testBatchLimitOfOne(): void
    {
        $server = new JsonRpcServer($this->createConfig(1));

        $items = [
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
        ];
        $result = $server->handleJson(json_encode($items));
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded[0]['id']);
        $this->assertArrayHasKey('result', $decoded[0]);
    }

    public function testBatchLimitOfOneExceeded(): void
    {
        $server = new JsonRpcServer($this->createConfig(1));

        $items = [
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 2],
        ];
        $result = $server->handleJson(json_encode($items));
        $decoded = json_decode($result, true);
        $this->assertSame(-32600, $decoded['error']['code']);
    }

    public function testBatchWithAllMalformedItems(): void
    {
        $server = new JsonRpcServer($this->createConfig());

        $items = [
            ['not-jsonrpc' => true],
            42,
            'string-item',
            null,
        ];
        $result = $server->handleJson(json_encode($items));
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        foreach ($decoded as $response) {
            $this->assertSame(-32600, $response['error']['code']);
        }
    }

    public function testBatchWithMixedValidInvalidAndNotifications(): void
    {
        $server = new JsonRpcServer($this->createConfig());

        $items = [
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'system.health'],
            ['invalid' => true, 'id' => 2],
            ['jsonrpc' => '2.0', 'method' => 'system.version', 'id' => 3],
        ];
        $result = $server->handleJson(json_encode($items));
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);

        $ids = array_column($decoded, 'id');
        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
        $this->assertContains(3, $ids);
        $this->assertNotContains(null, $ids);

        $foundError = false;
        foreach ($decoded as $response) {
            if (isset($response['error']) && $response['id'] === 2) {
                $foundError = true;
                $this->assertSame(-32600, $response['error']['code']);
            }
        }
        $this->assertTrue($foundError);
    }

    public function testSingleRequestNotAffectedByBatchLimit(): void
    {
        $server = new JsonRpcServer($this->createConfig(1));

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"system.health","id":1}'
        );
        $decoded = json_decode($result, true);
        $this->assertArrayHasKey('result', $decoded);
    }

    public function testEmptyBatchErrorHasNullId(): void
    {
        $server = new JsonRpcServer($this->createConfig());

        $result = $server->handleJson('[]');
        $decoded = json_decode($result, true);
        $this->assertNull($decoded['id']);
        $this->assertSame(-32600, $decoded['error']['code']);
    }

    public function testOversizedBatchErrorHasNullId(): void
    {
        $server = new JsonRpcServer($this->createConfig(2));

        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => $i];
        }
        $result = $server->handleJson(json_encode($items));
        $decoded = json_decode($result, true);
        $this->assertNull($decoded['id']);
        $this->assertSame(-32600, $decoded['error']['code']);
        $this->assertArrayHasKey('data', $decoded['error']);
    }
}
