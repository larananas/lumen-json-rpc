<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\HookPoint;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ServerBehaviorTest extends TestCase
{
    use IntegrationTestCase;

    protected function setUp(): void
    {
        $this->initHandlerPath();
    }

    public function testHttpMethodNotAllowedReturns405(): void
    {
        $server = $this->createServer(['health' => ['enabled' => false]]);
        $response = $server->handle($this->createRequest('', 'PUT'));
        $this->assertEquals(405, $response->statusCode);
        $this->assertArrayHasKey('Allow', $response->headers);
        $this->assertEquals('POST', $response->headers['Allow']);
    }

    public function testHttpMethodNotAllowedIncludesGetWhenHealthEnabled(): void
    {
        $server = $this->createServer(['health' => ['enabled' => true]]);
        $response = $server->handle($this->createRequest('', 'PUT'));

        $this->assertEquals(405, $response->statusCode);
        $this->assertArrayHasKey('Allow', $response->headers);
        $this->assertEquals('POST, GET', $response->headers['Allow']);
    }

    public function testDeleteMethodReturns405(): void
    {
        $server = $this->createServer(['health' => ['enabled' => false]]);
        $response = $server->handle($this->createRequest('', 'DELETE'));
        $this->assertEquals(405, $response->statusCode);
    }

    public function testPatchMethodReturns405(): void
    {
        $server = $this->createServer(['health' => ['enabled' => false]]);
        $response = $server->handle($this->createRequest('', 'PATCH'));
        $this->assertEquals(405, $response->statusCode);
    }

    public function testDebugModeIncludesTraceInInternalError(): void
    {
        $server = $this->createServer(['debug' => true]);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'nonexist.method', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);

        $this->assertEquals(-32601, $data['error']['code']);
        $this->assertArrayHasKey('data', $data['error']);
    }

    public function testProductionModeOmitsDebugData(): void
    {
        $server = $this->createServer(['debug' => false]);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'nonexist.method', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);

        $this->assertEquals(-32601, $data['error']['code']);
    }

    public function testAllErrorResponsesIncludeJsonrpcVersion(): void
    {
        $server = $this->createServer();
        $requests = [
            'parse error' => '{invalid}',
            'invalid request' => '{}',
            'method not found' => json_encode(['jsonrpc' => '2.0', 'method' => 'nonexist.method', 'id' => 1]),
            'empty body' => '',
        ];

        foreach ($requests as $label => $body) {
            $response = $server->handle($this->createRequest($body));
            if ($response->body === '') {
                continue;
            }
            $data = json_decode($response->body, true);
            if ($data === null) {
                continue;
            }
            if (isset($data[0])) {
                foreach ($data as $item) {
                    $this->assertEquals('2.0', $item['jsonrpc'] ?? null, "Failed for: $label (batch item)");
                }
            } else {
                $this->assertEquals('2.0', $data['jsonrpc'] ?? null, "Failed for: $label");
            }
        }
    }

    public function testSuccessfulResponseAlwaysIncludesJsonrpcVersion(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals('2.0', $data['jsonrpc']);
    }

    public function testResponseContainsEitherResultOrErrorNeverBoth(): void
    {
        $server = $this->createServer();

        $cases = [
            'success' => json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]),
            'error' => json_encode(['jsonrpc' => '2.0', 'method' => 'nonexist.method', 'id' => 1]),
            'parse error' => '{bad}',
            'invalid request' => json_encode(['jsonrpc' => '1.0', 'method' => 'test', 'id' => 1]),
        ];

        foreach ($cases as $label => $body) {
            $response = $server->handle($this->createRequest($body));
            if ($response->body === '') continue;
            $data = json_decode($response->body, true);
            if ($data === null) continue;
            $items = $data[0] ?? [$data];
            foreach ($items as $item) {
                $hasResult = array_key_exists('result', $item);
                $hasError = array_key_exists('error', $item);
                $this->assertTrue($hasResult XOR $hasError, "Failed for: $label - result and error must be mutually exclusive");
            }
        }
    }

    public function testBatchResponsePreservesRequestIds(): void
    {
        $server = $this->createServer();
        $body = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 42],
            ['jsonrpc' => '2.0', 'method' => 'system.version', 'id' => 'string-id'],
            ['jsonrpc' => '2.0', 'method' => 'nonexist', 'id' => 99],
            ['jsonrpc' => '2.0', 'method' => 'system.methods', 'id' => null],
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);

        $ids = array_column($data, 'id');
        $this->assertContains(42, $ids);
        $this->assertContains('string-id', $ids);
        $this->assertContains(99, $ids);
        $this->assertContains(null, $ids);
    }

    public function testBatchWithSingleValidAndSingleInvalidReturnsTwoResponses(): void
    {
        $server = $this->createServer();
        $body = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
            ['foo' => 'bar'],
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertCount(2, $data);
    }

    public function testHookContextPropagatesAcrossLifecycle(): void
    {
        $server = $this->createServer();
        $captured = [];

        $server->getHooks()->register(HookPoint::BEFORE_HANDLER, function (array $ctx) {
            return ['trace' => ['before_handler']];
        });
        $server->getHooks()->register(HookPoint::AFTER_HANDLER, function (array $ctx) use (&$captured) {
            $captured = $ctx;
            return [];
        });

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $server->handle($this->createRequest($body));

        $this->assertArrayHasKey('trace', $captured);
        $this->assertEquals(['before_handler'], $captured['trace']);
        $this->assertArrayHasKey('method', $captured);
        $this->assertEquals('system.health', $captured['method']);
        $this->assertArrayHasKey('result', $captured);
    }

    public function testOnErrorHookReceivesExceptionOnMethodNotFound(): void
    {
        $server = $this->createServer();
        $captured = null;

        $server->getHooks()->register(HookPoint::ON_ERROR, function (array $ctx) use (&$captured) {
            $captured = $ctx;
            return [];
        });

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'nonexist.method', 'id' => 1]);
        $server->handle($this->createRequest($body));

        $this->assertNotNull($captured);
        $this->assertEquals('nonexist.method', $captured['method'] ?? null);
        $this->assertArrayHasKey('exception', $captured);
    }

    public function testAuthHookFiresOnSuccessAndFailure(): void
    {
        $events = [];
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256'],
                'protected_methods' => ['user.'],
            ],
        ]);

        $server->getHooks()->register(HookPoint::ON_AUTH_SUCCESS, function () use (&$events) {
            $events[] = 'success';
            return [];
        });
        $server->getHooks()->register(HookPoint::ON_AUTH_FAILURE, function () use (&$events) {
            $events[] = 'failure';
            return [];
        });

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);

        $server->handle($this->createRequest($body, 'POST', [
            'Authorization' => 'Bearer invalid.token.here',
        ]));
        $this->assertContains('failure', $events);

        $token = $this->createJwt(['sub' => 'user-1', 'roles' => ['admin']]);
        $server->handle($this->createRequest($body, 'POST', [
            'Authorization' => "Bearer $token",
        ]));
        $this->assertContains('success', $events);
    }

    public function testHooksDisabledViaConfig(): void
    {
        $server = $this->createServer(['hooks' => ['enabled' => false]]);
        $fired = false;

        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, function () use (&$fired) {
            $fired = true;
            return [];
        });

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $server->handle($this->createRequest($body));

        $this->assertFalse($fired, 'Hooks should not fire when disabled via config');
    }

    public function testHandleJsonIsolatesHookExceptionsByDefault(): void
    {
        $server = $this->createServer();
        $continued = false;

        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, static function (): array {
            throw new RuntimeException('before request hook failed');
        });
        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, function () use (&$continued): array {
            $continued = true;
            return [];
        });

        $result = $server->handleJson(json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]));
        $data = json_decode($result, true);

        $this->assertTrue($continued);
        $this->assertSame('2.0', $data['jsonrpc']);
        $this->assertArrayHasKey('result', $data);
    }

    public function testHandleJsonCanPropagateHookExceptionsWhenIsolationDisabled(): void
    {
        $server = $this->createServer(['hooks' => ['isolate_exceptions' => false]]);

        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, static function (): array {
            throw new RuntimeException('before request hook failed');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('before request hook failed');

        $server->handleJson(json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]));
    }

    public function testHandleCanPropagateHookExceptionsWhenIsolationDisabled(): void
    {
        $server = $this->createServer(['hooks' => ['isolate_exceptions' => false]]);

        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, static function (): array {
            throw new RuntimeException('http hook failed');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('http hook failed');

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $server->handle($this->createRequest($body));
    }

    public function testFloatIdRejectedAsInvalidRequest(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1.5]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testNullIdInRequestPreservedInResponse(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => null]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertNull($data['id']);
        $this->assertArrayHasKey('result', $data);
    }

    public function testResponseIsNotGzippedByDefault(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', [
            'Accept-Encoding' => 'gzip',
        ]));
        $this->assertArrayNotHasKey('Content-Encoding', $response->headers);
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
    }

    public function testOversizedBodyRejectedBeforeParsing(): void
    {
        $server = $this->createServer(['limits' => ['max_body_size' => 50]]);
        $body = str_repeat('x', 100);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testNotificationDoesNotReturnResponseEvenOnError(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'nonexist.method']);
        $response = $server->handle($this->createRequest($body));
        $this->assertEquals(204, $response->statusCode);
        $this->assertEquals('', $response->body);
    }

    public function testBatchExceedingMaxItemsReturnsSingleError(): void
    {
        $server = $this->createServer(['batch' => ['max_items' => 2]]);
        $body = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 2],
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 3],
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
        $this->assertStringContainsString('maximum', $data['error']['data'] ?? $data['error']['message'] ?? '');
    }

    public function testRequestWithOmittedParamsReceivesNull(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testBatchWithMixOfNotificationsAndRequestsOnlyReturnsNonNotificationResponses(): void
    {
        $server = $this->createServer();
        $body = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'system.health'],
            ['jsonrpc' => '2.0', 'method' => 'system.version', 'id' => 2],
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertCount(2, $data);
        $ids = array_column($data, 'id');
        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
    }

    public function testGetHealthResponseIncludesServerName(): void
    {
        $server = $this->createServer([
            'server' => ['name' => 'Test Server', 'version' => '2.0.0'],
        ]);
        $response = $server->handle($this->createRequest('', 'GET'));
        $data = json_decode($response->body, true);
        $this->assertEquals('Test Server', $data['server']);
        $this->assertEquals('2.0.0', $data['version']);
    }
}
