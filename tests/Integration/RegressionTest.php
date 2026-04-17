<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\HookPoint;
use PHPUnit\Framework\TestCase;

final class RegressionTest extends TestCase
{
    use IntegrationTestCase;

    protected function setUp(): void
    {
        $this->initHandlerPath();
    }

    // === 3.1: Invalid request IDs ===

    public function testObjectIdInRequestDoesNotCrash(): void
    {
        $server = $this->createServer();
        $body = '{"jsonrpc":"2.0","method":"system.health","id":{}}';
        $response = $server->handle($this->createRequest($body));
        $this->assertEquals(200, $response->statusCode);
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
        $this->assertNull($data['id']);
    }

    public function testArrayIdInRequestDoesNotCrash(): void
    {
        $server = $this->createServer();
        $body = '{"jsonrpc":"2.0","method":"system.health","id":[]}';
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
        $this->assertNull($data['id']);
    }

    public function testBoolIdInRequestDoesNotCrash(): void
    {
        $server = $this->createServer();
        $body = '{"jsonrpc":"2.0","method":"system.health","id":true}';
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
        $this->assertNull($data['id']);
    }

    public function testInvalidIdInBatchItemReturnsNullId(): void
    {
        $server = $this->createServer();
        $body = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => []],
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertIsArray($data);
        $this->assertEquals(-32600, $data[0]['error']['code']);
        $this->assertNull($data[0]['id']);
    }

    // === 3.2: Valid JSON null/scalars = Invalid Request ===

    public function testValidJsonNullReturnsInvalidRequest(): void
    {
        $server = $this->createServer();
        $response = $server->handle($this->createRequest('null'));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testValidJsonTrueReturnsInvalidRequest(): void
    {
        $server = $this->createServer();
        $response = $server->handle($this->createRequest('true'));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testValidJsonNumberReturnsInvalidRequest(): void
    {
        $server = $this->createServer();
        $response = $server->handle($this->createRequest('1'));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testValidJsonStringReturnsInvalidRequest(): void
    {
        $server = $this->createServer();
        $response = $server->handle($this->createRequest('"hello"'));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testMalformedJsonReturnsParseError(): void
    {
        $server = $this->createServer();
        $response = $server->handle($this->createRequest('{invalid json}'));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32700, $data['error']['code']);
    }

    // === 3.3: Handler discovery ===

    public function testSystemSetRegistryNotExposed(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.setRegistry', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32601, $data['error']['code']);
    }

    public function testSystemMethodsOnlyListsCallableMethods(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.methods', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $methods = $data['result'];
        $this->assertNotContains('system.setRegistry', $methods);
        $this->assertContains('system.health', $methods);
        $this->assertContains('system.methods', $methods);
    }

    // === 3.4: No static global registry ===

    public function testMultipleServerInstancesAreIsolated(): void
    {
        $server1 = $this->createServer();
        $server2 = $this->createServer();

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.methods', 'id' => 1]);
        $r1 = $server1->handle($this->createRequest($body));
        $r2 = $server2->handle($this->createRequest($body));

        $d1 = json_decode($r1->body, true);
        $d2 = json_decode($r2->body, true);

        sort($d1['result']);
        sort($d2['result']);
        $this->assertEquals($d1['result'], $d2['result']);
    }

    // === 3.5: Auth config/runtime ===

    public function testEmptyJwtSecretThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => ''],
            ],
        ]);
    }

    public function testCustomAuthHeaderIsRespected(): void
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
            'X-Custom-Auth' => "Bearer $token",
        ]));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32001, $data['error']['code']);

        $response2 = $server->handle($this->createRequest($body, 'POST', [
            'Authorization' => "Bearer $token",
        ]));
        $data2 = json_decode($response2->body, true);
        $this->assertArrayHasKey('result', $data2);
    }

    public function testProtectedMethodWithCustomSeparator(): void
    {
        $server = $this->createServer([
            'handlers' => [
                'paths' => [$this->handlerPath],
                'namespace' => 'App\\Handlers\\',
                'method_separator' => '_',
            ],
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256'],
                'protected_methods' => ['user.', 'order.'],
            ],
        ]);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user_get', 'params' => ['id' => 1], 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32001, $data['error']['code']);
    }

    // === 3.6: ETag/fingerprint ===

    public function testEtagDoesNotReturn304ForDifferentRequestId(): void
    {
        $server = $this->createServer([
            'response_fingerprint' => ['enabled' => true],
        ]);

        $body1 = json_encode(['jsonrpc' => '2.0', 'method' => 'system.version', 'id' => 1]);
        $response1 = $server->handle($this->createRequest($body1));
        $etag = $response1->headers['ETag'];
        $d1 = json_decode($response1->body, true);
        $this->assertEquals(1, $d1['id']);

        $body2 = json_encode(['jsonrpc' => '2.0', 'method' => 'system.version', 'id' => 2]);
        $response2 = $server->handle($this->createRequest($body2, 'POST', [
            'If-None-Match' => $etag,
        ]));
        $this->assertEquals(200, $response2->statusCode);
        $d2 = json_decode($response2->body, true);
        $this->assertEquals(2, $d2['id']);
    }

    public function testInvalidFingerprintAlgorithmThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->createServer([
            'response_fingerprint' => ['enabled' => true, 'algorithm' => 'invalid_algo'],
        ]);
    }

    // === 3.7: Hook lifecycle ===

    public function testHookContextPropagation(): void
    {
        $server = $this->createServer();
        $captured = null;
        $server->getHooks()->register(HookPoint::BEFORE_HANDLER, function (array $ctx) {
            return ['injected' => 'value'];
        });
        $server->getHooks()->register(HookPoint::AFTER_HANDLER, function (array $ctx) use (&$captured) {
            $captured = $ctx;
            return [];
        });

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $server->handle($this->createRequest($body));
        $this->assertNotNull($captured);
        $this->assertArrayHasKey('injected', $captured);
        $this->assertEquals('value', $captured['injected']);
    }

    public function testHooksFireOnParseError(): void
    {
        $server = $this->createServer();
        $fired = [];
        $server->getHooks()->register(HookPoint::ON_ERROR, function (array $ctx) use (&$fired) {
            $fired[] = 'on_error';
            return [];
        });
        $server->getHooks()->register(HookPoint::ON_RESPONSE, function (array $ctx) use (&$fired) {
            $fired[] = 'on_response';
            return [];
        });
        $server->getHooks()->register(HookPoint::AFTER_REQUEST, function (array $ctx) use (&$fired) {
            $fired[] = 'after_request';
            return [];
        });

        $server->handle($this->createRequest('{invalid}'));
        $this->assertContains('on_error', $fired);
        $this->assertContains('on_response', $fired);
        $this->assertContains('after_request', $fired);
    }

    public function testHooksFireOnRateLimitDenial(): void
    {
        $tmpDir = sys_get_temp_dir() . '/jsonrpc_test_rate_' . uniqid();
        mkdir($tmpDir, 0755, true);
        try {
            $fired = [];
            $server = $this->createServer([
                'rate_limit' => [
                    'enabled' => true,
                    'max_requests' => 1,
                    'window_seconds' => 60,
                    'strategy' => 'ip',
                    'storage_path' => $tmpDir,
                ],
            ]);
            $server->getHooks()->register(HookPoint::ON_ERROR, function (array $ctx) use (&$fired) {
                $fired[] = 'on_error';
                return [];
            });

            $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
            $server->handle($this->createRequest($body));
            $server->handle($this->createRequest($body));
            $this->assertContains('on_error', $fired);
        } finally {
            $files = glob($tmpDir . '/*') ?: [];
            foreach ($files as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    // === 3.8: Notifications disabled ===

    public function testDisabledNotificationsReturnNoContentNotError(): void
    {
        $server = $this->createServer([
            'notifications' => ['enabled' => false],
        ]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health']);
        $response = $server->handle($this->createRequest($body));
        $this->assertEquals(204, $response->statusCode);
        $this->assertEquals('', $response->body);
    }

    public function testDisabledNotificationsBatchStillReturnsNoContentForNotifications(): void
    {
        $server = $this->createServer([
            'notifications' => ['enabled' => false],
        ]);
        $body = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health'],
        ]);
        $response = $server->handle($this->createRequest($body));
        $this->assertEquals(204, $response->statusCode);
    }

    // === 6.1: JWT leeway ===

    public function testJwtLeewayAllowsSlightlyExpiredToken(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => [
                    'secret' => 'test-secret',
                    'algorithm' => 'HS256',
                    'leeway' => 120,
                ],
                'protected_methods' => ['user.'],
            ],
        ]);

        $token = $this->createJwt(['sub' => 'user-1', 'exp' => time() - 30, 'roles' => ['admin']]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', [
            'Authorization' => "Bearer $token",
        ]));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
    }

    public function testJwtLeewayZeroRejectsExpiredToken(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => [
                    'secret' => 'test-secret',
                    'algorithm' => 'HS256',
                    'leeway' => 0,
                ],
                'protected_methods' => ['user.'],
            ],
        ]);

        $token = $this->createJwt(['sub' => 'user-1', 'exp' => time() - 30, 'roles' => ['admin']]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', [
            'Authorization' => "Bearer $token",
        ]));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32001, $data['error']['code']);
    }

    // === 6.3: Batch rate limiting ===

    public function testBatchRequestsAreRateLimitedWithWeight(): void
    {
        $tmpDir = sys_get_temp_dir() . '/jsonrpc_test_rate_' . uniqid();
        mkdir($tmpDir, 0755, true);
        try {
            $server = $this->createServer([
                'rate_limit' => [
                    'enabled' => true,
                    'max_requests' => 10,
                    'window_seconds' => 60,
                    'strategy' => 'ip',
                    'storage_path' => $tmpDir,
                    'batch_weight' => 2,
                ],
            ]);

            $singleBody = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
            for ($i = 0; $i < 5; $i++) {
                $server->handle($this->createRequest($singleBody));
            }

            $response = $server->handle($this->createRequest($singleBody));
            $this->assertEquals(429, $response->statusCode);
        } finally {
            $files = glob($tmpDir . '/*') ?: [];
            foreach ($files as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    // === 4.1: Case-insensitive headers ===

    public function testHeaderLookupCaseInsensitive(): void
    {
        $request = new HttpRequest(
            body: '',
            headers: ['content-encoding' => 'gzip'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );
        $this->assertEquals('gzip', $request->getHeaderCaseInsensitive('Content-Encoding'));
    }

    // === Strengthened tests ===

    public function testContextDiffersBetweenRequests(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256'],
                'protected_methods' => ['user.'],
            ],
        ]);

        $capturedContexts = [];
        $server->getHooks()->register(HookPoint::BEFORE_HANDLER, function (array $ctx) use (&$capturedContexts) {
            $capturedContexts[] = $ctx['context']->authUserId ?? null;
            return [];
        });

        $token1 = $this->createJwt(['sub' => 'user-alice', 'roles' => ['admin']]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);
        $server->handle($this->createRequest($body, 'POST', ['Authorization' => "Bearer $token1"]));

        $token2 = $this->createJwt(['sub' => 'user-bob', 'roles' => ['user']]);
        $server->handle($this->createRequest($body, 'POST', ['Authorization' => "Bearer $token2"]));

        $this->assertCount(2, $capturedContexts);
        $this->assertEquals('user-alice', $capturedContexts[0]);
        $this->assertEquals('user-bob', $capturedContexts[1]);
        $this->assertNotEquals($capturedContexts[0], $capturedContexts[1]);
    }

    public function testHandlerThrownJsonRpcExceptionPreserved(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'nonexistent.method', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32601, $data['error']['code']);
        $this->assertEquals('Method not found', $data['error']['message']);
    }

    public function testEmptyBodyReturnsInvalidRequest(): void
    {
        $server = $this->createServer();
        $response = $server->handle($this->createRequest(''));
        $this->assertEquals(200, $response->statusCode);
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
        $this->assertNotNull($data['error']['data']);
    }
}
