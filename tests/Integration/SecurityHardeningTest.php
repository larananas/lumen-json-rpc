<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\Server\JsonRpcServer;
use PHPUnit\Framework\TestCase;

final class SecurityHardeningTest extends TestCase
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

    private function createRequest(string $body, array $headers = []): HttpRequest
    {
        return new HttpRequest(
            body: $body,
            headers: array_merge(['Content-Type' => 'application/json'], $headers),
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );
    }

    public function testBodySizeLimitRejectsOversizedBody(): void
    {
        $config = $this->createConfig([
            'limits' => ['max_body_size' => 100, 'max_json_depth' => 64],
        ]);

        $server = new JsonRpcServer($config);

        $oversizedBody = str_repeat('{"jsonrpc":"2.0","method":"system.health","id":', 10);
        $request = $this->createRequest($oversizedBody);

        $response = $server->handle($request);
        $decoded = json_decode($response->body, true);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(-32600, $decoded['error']['code']);
        $this->assertStringContainsStringIgnoringCase('too large', $decoded['error']['data'] ?? $decoded['error']['message']);
    }

    public function testBodySizeLimitAllowsSmallerBody(): void
    {
        $config = $this->createConfig([
            'limits' => ['max_body_size' => 1_048_576, 'max_json_depth' => 64],
        ]);

        $server = new JsonRpcServer($config);

        $body = '{"jsonrpc":"2.0","method":"system.health","id":1}';
        $request = $this->createRequest($body);

        $response = $server->handle($request);
        $decoded = json_decode($response->body, true);

        $this->assertSame(200, $response->statusCode);
        $this->assertArrayHasKey('result', $decoded);
    }

    public function testJsonDepthLimitRejectsDeeplyNestedJson(): void
    {
        $config = $this->createConfig([
            'limits' => ['max_body_size' => 1_048_576, 'max_json_depth' => 5],
        ]);

        $server = new JsonRpcServer($config);

        $deepBody = '{"jsonrpc":"2.0","method":"system.health","id":1,"params":{"a":{"b":{"c":{"d":{"e":{"f":"too deep"}}}}}}}';
        $result = $server->handleJson($deepBody);
        $decoded = json_decode($result, true);

        $this->assertSame(-32700, $decoded['error']['code']);
        $this->assertSame('Parse error', $decoded['error']['message']);
    }

    public function testJsonDepthLimitAllowsShallowJson(): void
    {
        $config = $this->createConfig([
            'limits' => ['max_body_size' => 1_048_576, 'max_json_depth' => 64],
        ]);

        $server = new JsonRpcServer($config);

        $result = $server->handleJson('{"jsonrpc":"2.0","method":"system.health","id":1,"params":{"a":{"b":"ok"}}}');
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('result', $decoded);
    }

    public function testEmptyPostBodyReturnsInvalidRequest(): void
    {
        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $request = $this->createRequest('');
        $response = $server->handle($request);

        $decoded = json_decode($response->body, true);
        $this->assertSame(-32600, $decoded['error']['code']);
        $this->assertStringContainsStringIgnoringCase('empty', $decoded['error']['data'] ?? $decoded['error']['message']);
    }

    public function testGzipBombDecompressionSizeLimit(): void
    {
        $config = $this->createConfig([
            'limits' => ['max_body_size' => 200],
            'compression' => ['request_gzip' => true],
        ]);

        $server = new JsonRpcServer($config);

        $largePayload = str_repeat('A', 10000);
        $smallJson = '{"jsonrpc":"2.0","method":"system.health","id":1}';
        $gzipSmall = gzencode($smallJson);

        $request = new HttpRequest(
            body: $gzipSmall,
            headers: ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );

        $response = $server->handle($request);
        $decoded = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $decoded);
    }

    public function testRateLimitWithBatchWeightCountsItems(): void
    {
        $tmpDir = sys_get_temp_dir() . '/lumen_test_rl_' . uniqid();

        $config = $this->createConfig([
            'rate_limit' => [
                'enabled' => true,
                'max_requests' => 3,
                'window_seconds' => 60,
                'strategy' => 'ip',
                'storage_path' => $tmpDir,
                'batch_weight' => 1,
                'fail_open' => true,
            ],
        ]);

        $server = new JsonRpcServer($config);

        $singleRequest = '{"jsonrpc":"2.0","method":"system.health","id":1}';

        $response1 = $server->handle($this->createRequest($singleRequest));
        $this->assertSame(200, $response1->statusCode);

        $response2 = $server->handle($this->createRequest($singleRequest));
        $this->assertSame(200, $response2->statusCode);

        $response3 = $server->handle($this->createRequest($singleRequest));
        $this->assertSame(200, $response3->statusCode);

        $response4 = $server->handle($this->createRequest($singleRequest));
        $this->assertSame(429, $response4->statusCode);
        $decoded4 = json_decode($response4->body, true);
        $this->assertSame(-32000, $decoded4['error']['code']);
        $this->assertArrayHasKey('Retry-After', $response4->headers);
    }

    public function testRateLimitBatchConsumesMultipleSlots(): void
    {
        $tmpDir = sys_get_temp_dir() . '/lumen_test_rl_batch_' . uniqid();

        $config = $this->createConfig([
            'rate_limit' => [
                'enabled' => true,
                'max_requests' => 10,
                'window_seconds' => 60,
                'strategy' => 'ip',
                'storage_path' => $tmpDir,
                'batch_weight' => 1,
                'fail_open' => true,
            ],
        ]);

        $server = new JsonRpcServer($config);

        $batchRequest = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 2],
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 3],
        ]);

        $response = $server->handle($this->createRequest($batchRequest));
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertCount(3, $decoded);
    }

    public function testInvalidConfigMaxBodySizeZeroThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        new JsonRpcServer($this->createConfig([
            'limits' => ['max_body_size' => 0, 'max_json_depth' => 64],
        ]));
    }

    public function testReservedRpcMethodPrefixBlocked(): void
    {
        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $result = $server->handleJson('{"jsonrpc":"2.0","method":"rpc.reserved","id":1}');
        $decoded = json_decode($result, true);

        $this->assertSame(-32600, $decoded['error']['code']);
    }
}
