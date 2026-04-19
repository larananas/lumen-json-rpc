<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Middleware\MiddlewareInterface;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Protocol\Response;
use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\HookPoint;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class LogCollector
{
    public array $log = [];

    public function record(string $event): array
    {
        $this->log[] = $event;
        return [];
    }
}

final class LoggingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LogCollector $collector,
        private readonly string $prefix = '',
    ) {}

    public function process(Request $request, RequestContext $context, callable $next): ?Response
    {
        $this->collector->record("middleware:{$this->prefix}:before");
        $response = $next($request, $context);
        $this->collector->record("middleware:{$this->prefix}:after");
        return $response;
    }
}

final class LifecycleGuaranteeTest extends TestCase
{
    private function createConfig(): Config
    {
        return new Config([
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
        ]);
    }

    public function testFullRequestLifecycleOrder(): void
    {
        $collector = new LogCollector();

        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, function () use ($collector) {
            return $collector->record('hook:BEFORE_REQUEST');
        });
        $server->getHooks()->register(HookPoint::BEFORE_HANDLER, function () use ($collector) {
            return $collector->record('hook:BEFORE_HANDLER');
        });
        $server->getHooks()->register(HookPoint::AFTER_HANDLER, function () use ($collector) {
            return $collector->record('hook:AFTER_HANDLER');
        });
        $server->getHooks()->register(HookPoint::ON_RESPONSE, function () use ($collector) {
            return $collector->record('hook:ON_RESPONSE');
        });
        $server->getHooks()->register(HookPoint::AFTER_REQUEST, function () use ($collector) {
            return $collector->record('hook:AFTER_REQUEST');
        });

        $server->addMiddleware(new LoggingMiddleware($collector, 'default'));

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"system.health","id":1}'
        );

        $decoded = json_decode($result, true);
        $this->assertArrayHasKey('result', $decoded);

        $expectedOrder = [
            'hook:BEFORE_REQUEST',
            'hook:BEFORE_HANDLER',
            'middleware:default:before',
            'hook:AFTER_HANDLER',
            'middleware:default:after',
            'hook:ON_RESPONSE',
            'hook:AFTER_REQUEST',
        ];

        $this->assertSame($expectedOrder, $collector->log);
    }

    public function testMiddlewareReceivesParsedRequest(): void
    {
        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $captured = (object)['method' => null, 'params' => null];
        $server->addMiddleware(new class($captured) implements MiddlewareInterface {
            public function __construct(private readonly object $captured) {}
            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                $this->captured->method = $request->method;
                $this->captured->params = $request->params;
                return $next($request, $context);
            }
        });

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"system.health","id":1}'
        );

        $decoded = json_decode($result, true);
        $this->assertArrayHasKey('result', $decoded);
        $this->assertSame('system.health', $captured->method);
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $server->addMiddleware(new class implements MiddlewareInterface {
            public function process(Request $request, RequestContext $context, callable $next): ?Response
            {
                return Response::error($request->id, new \Lumen\JsonRpc\Protocol\Error(-32000, 'Blocked'));
            }
        });

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"system.health","id":1}'
        );

        $decoded = json_decode($result, true);
        $this->assertSame(-32000, $decoded['error']['code']);
        $this->assertSame('Blocked', $decoded['error']['message']);
    }

    public function testParseErrorFiresBeforeMiddleware(): void
    {
        $collector = new LogCollector();

        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, function () use ($collector) {
            return $collector->record('BEFORE_REQUEST');
        });

        $server->addMiddleware(new LoggingMiddleware($collector, 'check'));

        $result = $server->handleJson('{invalid json}');

        $decoded = json_decode($result, true);
        $this->assertSame(-32700, $decoded['error']['code']);

        $this->assertContains('BEFORE_REQUEST', $collector->log);
        $this->assertNotContains('middleware:check:before', $collector->log);
    }

    public function testMultipleMiddlewareExecutesInOrder(): void
    {
        $collector = new LogCollector();

        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $server->addMiddleware(new LoggingMiddleware($collector, 'A'));
        $server->addMiddleware(new LoggingMiddleware($collector, 'B'));

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"system.health","id":1}'
        );

        $decoded = json_decode($result, true);
        $this->assertArrayHasKey('result', $decoded);

        $mwEvents = array_values(array_filter($collector->log, fn(string $e) => str_starts_with($e, 'middleware:')));
        $this->assertSame([
            'middleware:A:before',
            'middleware:B:before',
            'middleware:B:after',
            'middleware:A:after',
        ], $mwEvents);
    }

    public function testAuthHooksFireInCorrectLifecyclePosition(): void
    {
        $collector = new LogCollector();

        $secret = 'test-secret-key-for-lifecycle';
        $config = new Config([
            'handlers' => [
                'paths' => [__DIR__ . '/../../examples/basic/handlers'],
                'namespace' => 'App\\Handlers\\',
            ],
            'logging' => ['enabled' => false],
            'health' => ['enabled' => false],
            'auth' => [
                'enabled' => true,
                'driver' => 'jwt',
                'protected_methods' => ['system.'],
                'jwt' => [
                    'secret' => $secret,
                    'algorithm' => 'HS256',
                ],
            ],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
            'compression' => ['response_gzip' => false],
        ]);

        $server = new JsonRpcServer($config);

        $hooks = [
            HookPoint::BEFORE_REQUEST,
            HookPoint::ON_AUTH_SUCCESS,
            HookPoint::ON_AUTH_FAILURE,
            HookPoint::BEFORE_HANDLER,
            HookPoint::AFTER_HANDLER,
            HookPoint::ON_RESPONSE,
            HookPoint::AFTER_REQUEST,
        ];
        foreach ($hooks as $point) {
            $name = $point->name;
            $server->getHooks()->register($point, function () use ($collector, $name) {
                return $collector->record("hook:{$name}");
            });
        }

        $headerJson = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payloadJson = json_encode(['sub' => 'user1', 'iat' => time(), 'exp' => time() + 3600]);
        $headerB64 = $this->base64UrlEncode($headerJson);
        $payloadB64 = $this->base64UrlEncode($payloadJson);
        $signature = hash_hmac('sha256', "$headerB64.$payloadB64", $secret, true);
        $signatureB64 = $this->base64UrlEncode($signature);
        $token = "$headerB64.$payloadB64.$signatureB64";

        $context = new RequestContext(
            correlationId: 'test-auth-lifecycle',
            headers: ['Authorization' => "Bearer $token"],
            clientIp: '127.0.0.1',
        );

        $server->handleJson(
            '{"jsonrpc":"2.0","method":"system.health","id":1}',
            $context,
        );

        $this->assertContains('hook:ON_AUTH_SUCCESS', $collector->log, 'ON_AUTH_SUCCESS must fire');
        $authIndex = array_search('hook:ON_AUTH_SUCCESS', $collector->log);
        $beforeHandlerIndex = array_search('hook:BEFORE_HANDLER', $collector->log);
        $beforeRequestIndex = array_search('hook:BEFORE_REQUEST', $collector->log);

        $this->assertGreaterThan($beforeRequestIndex, $authIndex,
            'ON_AUTH_SUCCESS must fire after BEFORE_REQUEST');
        $this->assertLessThan($beforeHandlerIndex, $authIndex,
            'ON_AUTH_SUCCESS must fire before BEFORE_HANDLER');
    }

    public function testAuthFailureHookFiresInCorrectPosition(): void
    {
        $collector = new LogCollector();

        $config = new Config([
            'handlers' => [
                'paths' => [__DIR__ . '/../../examples/basic/handlers'],
                'namespace' => 'App\\Handlers\\',
            ],
            'logging' => ['enabled' => false],
            'health' => ['enabled' => false],
            'auth' => [
                'enabled' => true,
                'driver' => 'jwt',
                'protected_methods' => ['system.'],
                'jwt' => [
                    'secret' => 'correct-secret',
                    'algorithm' => 'HS256',
                ],
            ],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
            'compression' => ['response_gzip' => false],
        ]);

        $server = new JsonRpcServer($config);

        $hooks = [
            HookPoint::BEFORE_REQUEST,
            HookPoint::ON_AUTH_SUCCESS,
            HookPoint::ON_AUTH_FAILURE,
            HookPoint::BEFORE_HANDLER,
            HookPoint::ON_ERROR,
            HookPoint::ON_RESPONSE,
            HookPoint::AFTER_REQUEST,
        ];
        foreach ($hooks as $point) {
            $name = $point->name;
            $server->getHooks()->register($point, function () use ($collector, $name) {
                return $collector->record("hook:{$name}");
            });
        }

        $headerJson = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payloadJson = json_encode(['sub' => 'user1', 'iat' => time(), 'exp' => time() + 3600]);
        $headerB64 = $this->base64UrlEncode($headerJson);
        $payloadB64 = $this->base64UrlEncode($payloadJson);
        $signature = hash_hmac('sha256', "$headerB64.$payloadB64", 'wrong-secret', true);
        $signatureB64 = $this->base64UrlEncode($signature);
        $token = "$headerB64.$payloadB64.$signatureB64";

        $context = new RequestContext(
            correlationId: 'test-auth-fail-lifecycle',
            headers: ['Authorization' => "Bearer $token"],
            clientIp: '127.0.0.1',
        );

        $server->handleJson(
            '{"jsonrpc":"2.0","method":"system.health","id":1}',
            $context,
        );

        $this->assertContains('hook:ON_AUTH_FAILURE', $collector->log);
        $this->assertNotContains('hook:ON_AUTH_SUCCESS', $collector->log);
        $this->assertContains('hook:ON_ERROR', $collector->log);
        $this->assertContains('hook:ON_RESPONSE', $collector->log);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
