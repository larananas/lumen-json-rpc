<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\HookPoint;
use Lumen\JsonRpc\Config\Config;
use PHPUnit\Framework\TestCase;

final class ServerHookMutationKillTest extends TestCase
{
    private array $capturedHooks = [];

    private function createServer(array $config = []): JsonRpcServer
    {
        $this->capturedHooks = [];
        $server = new JsonRpcServer(new Config($config));
        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, function (array $ctx): array {
            $this->capturedHooks[] = ['point' => 'BEFORE_REQUEST', 'ctx' => $ctx];
            return $ctx;
        });
        $server->getHooks()->register(HookPoint::ON_RESPONSE, function (array $ctx): array {
            $this->capturedHooks[] = ['point' => 'ON_RESPONSE', 'ctx' => $ctx];
            return $ctx;
        });
        $server->getHooks()->register(HookPoint::AFTER_REQUEST, function (array $ctx): array {
            $this->capturedHooks[] = ['point' => 'AFTER_REQUEST', 'ctx' => $ctx];
            return $ctx;
        });
        $server->getHooks()->register(HookPoint::ON_ERROR, function (array $ctx): array {
            $this->capturedHooks[] = ['point' => 'ON_ERROR', 'ctx' => $ctx];
            return $ctx;
        });
        return $server;
    }

    public function testHealthEndpointHookContexts(): void
    {
        $server = $this->createServer(['health' => ['enabled' => true]]);
        $request = new HttpRequest('', [], 'GET', '127.0.0.1', []);
        $server->handle($request);

        $this->assertCount(3, $this->capturedHooks);
        $this->assertSame('BEFORE_REQUEST', $this->capturedHooks[0]['point']);
        $this->assertArrayHasKey('correlationId', $this->capturedHooks[0]['ctx']);
        $this->assertTrue($this->capturedHooks[0]['ctx']['health']);

        $this->assertSame('ON_RESPONSE', $this->capturedHooks[1]['point']);
        $this->assertSame(200, $this->capturedHooks[1]['ctx']['status']);
        $this->assertArrayHasKey('correlationId', $this->capturedHooks[1]['ctx']);
        $this->assertTrue($this->capturedHooks[1]['ctx']['health']);

        $this->assertSame('AFTER_REQUEST', $this->capturedHooks[2]['point']);
        $this->assertArrayHasKey('correlationId', $this->capturedHooks[2]['ctx']);
        $this->assertTrue($this->capturedHooks[2]['ctx']['health']);
    }

    public function testEmptyBodyHookContexts(): void
    {
        $server = $this->createServer();
        $request = new HttpRequest('', [], 'POST', '127.0.0.1', []);
        $server->handle($request);

        $beforeHook = array_filter($this->capturedHooks, fn($h) => $h['point'] === 'BEFORE_REQUEST');
        $beforeHook = array_values($beforeHook);
        $this->assertNotEmpty($beforeHook);
        $this->assertArrayHasKey('correlationId', $beforeHook[0]['ctx']);

        $errorHook = array_filter($this->capturedHooks, fn($h) => $h['point'] === 'ON_ERROR');
        $errorHook = array_values($errorHook);
        $this->assertNotEmpty($errorHook);
        $this->assertSame('empty_body', $errorHook[0]['ctx']['reason']);

        $responseHook = array_filter($this->capturedHooks, fn($h) => $h['point'] === 'ON_RESPONSE');
        $responseHook = array_values($responseHook);
        $this->assertNotEmpty($responseHook);
        $this->assertSame(200, $responseHook[0]['ctx']['status']);
    }

    public function testContentTypeStrictHookContexts(): void
    {
        $server = $this->createServer(['content_type' => ['strict' => true]]);
        $request = new HttpRequest('{}', ['Content-Type' => 'text/plain'], 'POST', '127.0.0.1', []);
        $server->handle($request);

        $errorHook = array_filter($this->capturedHooks, fn($h) => $h['point'] === 'ON_ERROR');
        $errorHook = array_values($errorHook);
        $this->assertNotEmpty($errorHook);
        $this->assertSame('invalid_content_type', $errorHook[0]['ctx']['reason']);
    }

    public function testJsonParseErrorHookContexts(): void
    {
        $server = $this->createServer();
        $request = new HttpRequest('not-json', [], 'POST', '127.0.0.1', []);
        $server->handle($request);

        $errorHook = array_filter($this->capturedHooks, fn($h) => $h['point'] === 'ON_ERROR');
        $errorHook = array_values($errorHook);
        $this->assertNotEmpty($errorHook);

        $responseHook = array_filter($this->capturedHooks, fn($h) => $h['point'] === 'ON_RESPONSE');
        $responseHook = array_values($responseHook);
        $this->assertSame(200, $responseHook[0]['ctx']['status']);
    }

    public function testHealthDisabledReturns405(): void
    {
        $server = $this->createServer(['health' => ['enabled' => false]]);
        $request = new HttpRequest('', [], 'GET', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertSame(405, $response->statusCode);
    }

    public function testHealthEnabledReturns200WithGet(): void
    {
        $server = $this->createServer(['health' => ['enabled' => true]]);
        $request = new HttpRequest('', [], 'GET', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertSame(200, $response->statusCode);
        $data = json_decode($response->body, true);
        $this->assertSame('ok', $data['status']);
        $this->assertSame('Lumen JSON-RPC', $data['server']);
        $this->assertSame('1.0.0', $data['version']);
    }

    public function testHealthEndpointCustomServerName(): void
    {
        $server = $this->createServer(['server' => ['name' => 'Custom', 'version' => '2.0.0']]);
        $request = new HttpRequest('', [], 'GET', '127.0.0.1', []);
        $response = $server->handle($request);
        $data = json_decode($response->body, true);
        $this->assertSame('Custom', $data['server']);
        $this->assertSame('2.0.0', $data['version']);
    }

    public function testDeleteMethodReturns405(): void
    {
        $server = $this->createServer();
        $request = new HttpRequest('', [], 'DELETE', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertSame(405, $response->statusCode);
    }

    public function testHealthDisabledAllowHeaderIsPostOnly(): void
    {
        $server = $this->createServer(['health' => ['enabled' => false]]);
        $request = new HttpRequest('', [], 'DELETE', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertSame('POST', $response->headers['Allow']);
    }

    public function testHealthEnabledAllowHeaderIncludesGet(): void
    {
        $server = $this->createServer(['health' => ['enabled' => true]]);
        $request = new HttpRequest('', [], 'DELETE', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertSame('POST, GET', $response->headers['Allow']);
    }

    public function testNotificationProcessingHooks(): void
    {
        $server = $this->createServer([
            'handlers' => ['paths' => [__DIR__ . '/../../tests/Fixtures/handlers']],
            'handlers.namespace' => 'Lumen\\JsonRpc\\Tests\\Fixtures\\handlers\\',
        ]);
        $server->getRegistry()->register('notify', UserHandler::class, 'getList');
        $request = new HttpRequest('{"jsonrpc":"2.0","method":"notify"}', [], 'POST', '127.0.0.1', []);
        $response = $server->handle($request);
        $this->assertSame(204, $response->statusCode);
    }
}
