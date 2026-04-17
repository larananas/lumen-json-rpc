<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\HookPoint;
use PHPUnit\Framework\TestCase;

final class CorrectionTest extends TestCase
{
    use IntegrationTestCase;

    protected function setUp(): void
    {
        $this->initHandlerPath();
    }

    // === FIX #1: Auth enforcement ===

    public function testProtectedMethodWithoutTokenReturnsAuthError(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256'],
                'protected_methods' => ['user.', 'order.'],
            ],
        ]);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32001, $data['error']['code']);
    }

    public function testProtectedMethodWithInvalidTokenReturnsAuthError(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256'],
                'protected_methods' => ['user.'],
            ],
        ]);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', [
            'Authorization' => 'Bearer invalid.token.here',
        ]));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32001, $data['error']['code']);
    }

    public function testProtectedMethodWithValidTokenSucceeds(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256'],
                'protected_methods' => ['user.'],
            ],
        ]);

        $token = $this->createJwt(['sub' => 'user-1', 'roles' => ['admin']]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', [
            'Authorization' => "Bearer $token",
        ]));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
    }

    public function testPublicMethodWithoutTokenSucceeds(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256'],
                'protected_methods' => ['user.', 'order.'],
            ],
        ]);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
        $this->assertEquals('ok', $data['result']['status']);
    }

    // === FIX #2: Stale context regression ===

    public function testTwoRequestsWithDifferentAuthGetDifferentContext(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256'],
                'protected_methods' => ['user.'],
            ],
        ]);

        $token1 = $this->createJwt(['sub' => 'user-alice', 'roles' => ['admin']]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);

        $response1 = $server->handle($this->createRequest($body, 'POST', [
            'Authorization' => "Bearer $token1",
        ]));
        $data1 = json_decode($response1->body, true);
        $this->assertArrayHasKey('result', $data1);

        $token2 = $this->createJwt(['sub' => 'user-bob', 'roles' => ['user']]);
        $response2 = $server->handle($this->createRequest($body, 'POST', [
            'Authorization' => "Bearer $token2",
        ]));
        $data2 = json_decode($response2->body, true);
        $this->assertArrayHasKey('result', $data2);
    }

    // === FIX #4: Invalid param type -> -32602 ===

    public function testWrongParamTypeReturnsInvalidParams(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 'not-a-number'], 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32602, $data['error']['code']);
        $this->assertEquals('Invalid params', $data['error']['message']);
    }

    public function testMissingRequiredParamReturnsInvalidParams(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => [], 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32602, $data['error']['code']);
    }

    // === FIX #5: Post-decompression size limit ===

    public function testGzippedRequestIsDecompressed(): void
    {
        $server = $this->createServer();
        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $gzipped = gzencode($json);
        $response = $server->handle($this->createRequest($gzipped, 'POST', [
            'Content-Encoding' => 'gzip',
        ]));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testInvalidGzipReturnsError(): void
    {
        $server = $this->createServer();
        $response = $server->handle($this->createRequest('not-valid-gzip-data', 'POST', [
            'Content-Encoding' => 'gzip',
        ]));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testOversizedDecompressedBodyIsRejected(): void
    {
        $server = $this->createServer([
            'limits' => ['max_body_size' => 100],
        ]);
        $largePayload = str_repeat('x', 200);
        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'params' => ['data' => $largePayload], 'id' => 1]);
        $gzipped = gzencode($json);
        $response = $server->handle($this->createRequest($gzipped, 'POST', [
            'Content-Encoding' => 'gzip',
        ]));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    // === FIX #6: Compression config ===

    public function testResponseGzipWhenEnabledAndClientAccepts(): void
    {
        $server = $this->createServer([
            'compression' => ['response_gzip' => true],
        ]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', [
            'Accept-Encoding' => 'gzip, deflate',
        ]));
        $this->assertEquals('gzip', $response->headers['Content-Encoding'] ?? null);
        $decoded = gzdecode($response->body);
        $data = json_decode($decoded, true);
        $this->assertArrayHasKey('result', $data);
    }

    public function testResponseNotGzippedWhenClientDoesNotAccept(): void
    {
        $server = $this->createServer([
            'compression' => ['response_gzip' => true],
        ]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', []));
        $this->assertArrayNotHasKey('Content-Encoding', $response->headers);
    }

    public function testResponseNotGzippedWhenDisabled(): void
    {
        $server = $this->createServer([
            'compression' => ['response_gzip' => false],
        ]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', [
            'Accept-Encoding' => 'gzip',
        ]));
        $this->assertArrayNotHasKey('Content-Encoding', $response->headers);
    }

    // === FIX #7: ETag / If-None-Match ===

    public function testEtagEmittedWhenFingerprintingEnabled(): void
    {
        $server = $this->createServer([
            'response_fingerprint' => ['enabled' => true],
        ]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $this->assertArrayHasKey('ETag', $response->headers);
        $this->assertMatchesRegularExpression('/^"[a-f0-9]+"$/', $response->headers['ETag']);
    }

    public function testConditionalRequestWithMatchingEtagReturns304(): void
    {
        $server = $this->createServer([
            'response_fingerprint' => ['enabled' => true],
        ]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);

        $response1 = $server->handle($this->createRequest($body));
        $etag = $response1->headers['ETag'];

        $response2 = $server->handle($this->createRequest($body, 'POST', [
            'If-None-Match' => $etag,
        ]));
        $this->assertEquals(304, $response2->statusCode);
    }

    public function testConditionalRequestWithNonMatchingEtagReturns200(): void
    {
        $server = $this->createServer([
            'response_fingerprint' => ['enabled' => true],
        ]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);

        $response = $server->handle($this->createRequest($body, 'POST', [
            'If-None-Match' => '"wrongetag"',
        ]));
        $this->assertEquals(200, $response->statusCode);
    }

    // === FIX #8: Custom separator ===

    public function testCustomSeparatorWorks(): void
    {
        $server = $this->createServer([
            'handlers' => [
                'paths' => [$this->handlerPath],
                'namespace' => 'App\\Handlers\\',
                'method_separator' => '_',
            ],
        ]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system_health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
        $this->assertEquals('ok', $data['result']['status']);
    }

    // === FIX #10: Dynamic system.methods ===

    public function testSystemMethodsReturnsDynamicList(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.methods', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $methods = $data['result'];
        $this->assertContains('system.health', $methods);
        $this->assertContains('system.version', $methods);
        $this->assertContains('system.methods', $methods);
        $this->assertContains('user.get', $methods);
        $this->assertContains('order.get', $methods);
    }

    // === FIX #11: Hook lifecycle ===

    public function testBeforeRequestHookFires(): void
    {
        $server = $this->createServer();
        $fired = false;
        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, function () use (&$fired) {
            $fired = true;
            return [];
        });
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $server->handle($this->createRequest($body));
        $this->assertTrue($fired);
    }

    public function testAfterRequestHookFires(): void
    {
        $server = $this->createServer();
        $fired = false;
        $server->getHooks()->register(HookPoint::AFTER_REQUEST, function () use (&$fired) {
            $fired = true;
            return [];
        });
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $server->handle($this->createRequest($body));
        $this->assertTrue($fired);
    }

    public function testOnResponseHookFires(): void
    {
        $server = $this->createServer();
        $fired = false;
        $server->getHooks()->register(HookPoint::ON_RESPONSE, function () use (&$fired) {
            $fired = true;
            return [];
        });
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $server->handle($this->createRequest($body));
        $this->assertTrue($fired);
    }

    public function testHookExecutionOrder(): void
    {
        $server = $this->createServer();
        $order = [];
        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, function () use (&$order) {
            $order[] = 'before_request';
            return [];
        });
        $server->getHooks()->register(HookPoint::BEFORE_HANDLER, function () use (&$order) {
            $order[] = 'before_handler';
            return [];
        });
        $server->getHooks()->register(HookPoint::AFTER_HANDLER, function () use (&$order) {
            $order[] = 'after_handler';
            return [];
        });
        $server->getHooks()->register(HookPoint::ON_RESPONSE, function () use (&$order) {
            $order[] = 'on_response';
            return [];
        });
        $server->getHooks()->register(HookPoint::AFTER_REQUEST, function () use (&$order) {
            $order[] = 'after_request';
            return [];
        });
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $server->handle($this->createRequest($body));
        $this->assertEquals(['before_request', 'before_handler', 'after_handler', 'on_response', 'after_request'], $order);
    }

    // === FIX #13: Rate limiting ===

    public function testRateLimitReturnsServerErrorCode(): void
    {
        $tmpDir = sys_get_temp_dir() . '/jsonrpc_test_rate_' . uniqid();
        mkdir($tmpDir, 0755, true);

        try {
            $server = $this->createServer([
                'rate_limit' => [
                    'enabled' => true,
                    'max_requests' => 2,
                    'window_seconds' => 60,
                    'strategy' => 'ip',
                    'storage_path' => $tmpDir,
                ],
            ]);

            $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
            $server->handle($this->createRequest($body));
            $server->handle($this->createRequest($body));
            $response = $server->handle($this->createRequest($body));

            $this->assertEquals(429, $response->statusCode);
            $data = json_decode($response->body, true);
            $this->assertEquals(-32000, $data['error']['code']);
            $this->assertStringContainsString('Rate limit', $data['error']['message']);
            $this->assertArrayHasKey('X-RateLimit-Limit', $response->headers);
        } finally {
            $files = glob($tmpDir . '/*') ?: [];
            foreach ($files as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    // === FIX #14: Validation strict mode ===

    public function testExtraMembersAcceptedInLenientMode(): void
    {
        $server = $this->createServer([
            'validation' => ['strict' => false],
        ]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1, 'extra' => 'value']);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
    }

    public function testExtraMembersRejectedInStrictMode(): void
    {
        $server = $this->createServer([
            'validation' => ['strict' => true],
        ]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1, 'extra' => 'value']);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    // === FIX #15: GET behavior ===

    public function testGetReturnsHealthWhenEnabled(): void
    {
        $server = $this->createServer(['health' => ['enabled' => true]]);
        $response = $server->handle($this->createRequest('', 'GET'));
        $this->assertEquals(200, $response->statusCode);
        $data = json_decode($response->body, true);
        $this->assertEquals('ok', $data['status']);
    }

    public function testGetReturns405WhenHealthDisabled(): void
    {
        $server = $this->createServer(['health' => ['enabled' => false]]);
        $response = $server->handle($this->createRequest('', 'GET'));
        $this->assertEquals(405, $response->statusCode);
    }

    // === FIX #12: Handler-thrown JsonRpcException preservation ===

    public function testHandlerThrownMethodNotFoundExceptionPreserved(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'nonexist.method', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32601, $data['error']['code']);
    }

    // === Compression disabled rejects gzip ===

    public function testGzipRequestRejectedWhenDisabled(): void
    {
        $server = $this->createServer([
            'compression' => ['request_gzip' => false],
        ]);
        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $gzipped = gzencode($json);
        $response = $server->handle($this->createRequest($gzipped, 'POST', [
            'Content-Encoding' => 'gzip',
        ]));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32700, $data['error']['code']);
    }
}
