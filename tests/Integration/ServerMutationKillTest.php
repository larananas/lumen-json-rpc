<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\HookPoint;
use PHPUnit\Framework\TestCase;

final class ServerMutationKillTest extends TestCase
{
    public function testBodyTooLargeReturnsError(): void
    {
        $server = new JsonRpcServer(new Config([
            'limits' => ['max_body_size' => 10],
        ]));
        $body = str_repeat('x', 20);
        $request = new HttpRequest($body, [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertSame(200, $response->statusCode);
        $data = json_decode($response->body, true);
        $this->assertNotNull($data);
        $this->assertSame(-32600, $data['error']['code']);
    }

    public function testBodyExactlyAtMaxIsAccepted(): void
    {
        $server = new JsonRpcServer(new Config([
            'limits' => ['max_body_size' => 100],
        ]));
        $json = '{"jsonrpc":"2.0","method":"test","id":1}';
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertSame(200, $response->statusCode);
    }

    public function testBodyOverMaxByOneIsRejected(): void
    {
        $server = new JsonRpcServer(new Config([
            'limits' => ['max_body_size' => 5],
        ]));
        $json = '{"jsonrpc":"2.0","method":"test","id":1}';
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $data = json_decode($response->body, true);
        $this->assertSame(-32600, $data['error']['code']);
    }

    public function testStrictContentTypeRejectsPlainText(): void
    {
        $server = new JsonRpcServer(new Config([
            'content_type' => ['strict' => true],
        ]));
        $json = '{"jsonrpc":"2.0","method":"test","id":1}';
        $request = new HttpRequest($json, ['Content-Type' => 'text/plain'], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $data = json_decode($response->body, true);
        $this->assertSame(-32600, $data['error']['code']);
        $this->assertSame('Content-Type must be application/json', $data['error']['data']);
    }

    public function testStrictContentTypeAcceptsJson(): void
    {
        $server = new JsonRpcServer(new Config([
            'content_type' => ['strict' => true],
        ]));
        $json = '{"jsonrpc":"2.0","method":"test","id":1}';
        $request = new HttpRequest($json, ['Content-Type' => 'application/json'], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertSame(200, $response->statusCode);
    }

    public function testNonStrictContentTypeAcceptsAnything(): void
    {
        $server = new JsonRpcServer(new Config([
            'content_type' => ['strict' => false],
        ]));
        $json = '{"jsonrpc":"2.0","method":"test","id":1}';
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertSame(200, $response->statusCode);
    }

    public function testValidationStrictModeAffectsRequestParsing(): void
    {
        $server = new JsonRpcServer(new Config([
            'validation' => ['strict' => true],
        ]));
        $json = '{"jsonrpc":"2.0","method":"test","id":1}';
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertSame(200, $response->statusCode);
    }

    public function testDefaultDebugIsFalseInResponses(): void
    {
        $server = new JsonRpcServer(new Config());
        $json = '{"jsonrpc":"2.0","method":"nonexistent","id":1}';
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $data = json_decode($response->body, true);
        $this->assertArrayNotHasKey('debug', $data['error']['data'] ?? []);
    }

    public function testDebugTrueIncludesTraceInErrors(): void
    {
        $server = new JsonRpcServer(new Config(['debug' => true]));
        $json = '{"jsonrpc":"2.0","method":"nonexistent","id":1}';
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('debug', $data['error']['data']);
    }

    public function testNotificationDisabledReturnsNoContent(): void
    {
        $server = new JsonRpcServer(new Config([
            'notifications' => ['enabled' => false],
        ]));
        $json = '{"jsonrpc":"2.0","method":"test"}';
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertSame(204, $response->statusCode);
    }

    public function testNotificationEnabledProcessesNotification(): void
    {
        $server = new JsonRpcServer(new Config([
            'notifications' => ['enabled' => true],
        ]));
        $json = '{"jsonrpc":"2.0","method":"nonexistent"}';
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertSame(204, $response->statusCode);
    }

    public function testNotificationLogDisabled(): void
    {
        $server = new JsonRpcServer(new Config([
            'notifications' => ['enabled' => true, 'log' => false],
        ]));
        $json = '{"jsonrpc":"2.0","method":"nonexistent"}';
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertSame(204, $response->statusCode);
    }

    public function testHooksDisabledSkipsHookCallbacks(): void
    {
        $hookCalled = false;
        $server = new JsonRpcServer(new Config([
            'hooks' => ['enabled' => false],
        ]));
        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, function () use (&$hookCalled) {
            $hookCalled = true;
            return [];
        });
        $json = '{"jsonrpc":"2.0","method":"test","id":1}';
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $server->handle($request);
        $this->assertFalse($hookCalled);
    }

    public function testHooksEnabledFiresHookCallbacks(): void
    {
        $hookCalled = false;
        $server = new JsonRpcServer(new Config([
            'hooks' => ['enabled' => true],
        ]));
        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, function (array $ctx) use (&$hookCalled) {
            $hookCalled = true;
            return $ctx;
        });
        $json = '{"jsonrpc":"2.0","method":"test","id":1}';
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $server->handle($request);
        $this->assertTrue($hookCalled);
    }

    public function testHooksIsolateExceptionsTrueDoesNotBreakRequest(): void
    {
        $server = new JsonRpcServer(new Config([
            'hooks' => ['enabled' => true, 'isolate_exceptions' => true],
        ]));
        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, function () {
            throw new \RuntimeException('hook error');
        });
        $json = '{"jsonrpc":"2.0","method":"test","id":1}';
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertSame(200, $response->statusCode);
    }

    public function testResponseFingerprintDisabledDoesNotSetEtag(): void
    {
        $server = new JsonRpcServer(new Config([
            'response_fingerprint' => ['enabled' => false],
        ]));
        $json = '{"jsonrpc":"2.0","method":"test","id":1}';
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertArrayNotHasKey('ETag', $response->headers);
    }

    public function testResponseFingerprintEnabledSetsEtag(): void
    {
        $server = new JsonRpcServer(new Config([
            'response_fingerprint' => ['enabled' => true, 'algorithm' => 'sha256'],
        ]));
        $server->getRegistry()->register('test', \Lumen\JsonRpc\Tests\Fixtures\HandlerNoConstructor::class, 'testMethod');
        $json = '{"jsonrpc":"2.0","method":"test","id":1}';
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertArrayHasKey('ETag', $response->headers);
    }

    public function testBatchLimitWithCustomMax(): void
    {
        $server = new JsonRpcServer(new Config([
            'batch' => ['max_items' => 2],
        ]));
        $items = [];
        for ($i = 0; $i < 3; $i++) {
            $items[] = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => $i + 1];
        }
        $json = json_encode($items);
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $data = json_decode($response->body, true);
        $this->assertSame(-32600, $data['error']['code']);
        $this->assertStringContainsString('2', $data['error']['data']);
    }

    public function testDefaultBatchLimitAllows100Items(): void
    {
        $server = new JsonRpcServer(new Config());
        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items[] = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => $i + 1];
        }
        $json = json_encode($items);
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertSame(200, $response->statusCode);
    }

    public function testDefaultBatchLimitRejects101Items(): void
    {
        $server = new JsonRpcServer(new Config());
        $items = [];
        for ($i = 0; $i < 101; $i++) {
            $items[] = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => $i + 1];
        }
        $json = json_encode($items);
        $request = new HttpRequest($json, [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $data = json_decode($response->body, true);
        $this->assertSame(-32600, $data['error']['code']);
    }
}
