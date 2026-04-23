<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Core;

use Lumen\JsonRpc\Auth\AuthManager;
use Lumen\JsonRpc\Cache\ResponseFingerprinter;
use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Core\JsonRpcEngine;
use Lumen\JsonRpc\Dispatcher\HandlerDispatcher;
use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Dispatcher\MethodResolver;
use Lumen\JsonRpc\Dispatcher\ParameterBinder;
use Lumen\JsonRpc\Log\Logger;
use Lumen\JsonRpc\Protocol\BatchProcessor;
use Lumen\JsonRpc\Protocol\RequestValidator;
use Lumen\JsonRpc\RateLimit\RateLimitManager;
use Lumen\JsonRpc\Support\HookManager;
use Lumen\JsonRpc\Support\HookPoint;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class EngineMutationKillTest extends TestCase
{
    private function createEngine(array $configOverrides = [], ?HookManager &$outHooks = null): JsonRpcEngine
    {
        $handlerPath = realpath(__DIR__ . '/../../../examples/basic/handlers');
        $config = new Config(array_merge([
            'handlers' => [
                'paths' => [$handlerPath],
                'namespace' => 'App\\Handlers\\',
            ],
            'debug' => false,
            'logging' => ['enabled' => false],
            'auth' => ['enabled' => false],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
            'hooks' => ['enabled' => true, 'isolate_exceptions' => false],
            'notifications' => ['enabled' => true],
        ], $configOverrides));

        $hooks = new HookManager();
        $outHooks = $hooks;
        $logger = new Logger('', 'none');
        $authManager = new AuthManager(false);
        $rateLimitManager = new RateLimitManager(false);
        $fingerprinter = new ResponseFingerprinter(false, 'sha256');
        $validator = new RequestValidator(true);
        $batchProcessor = new BatchProcessor($validator, 100);
        $registry = new HandlerRegistry([$handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        return new JsonRpcEngine(
            $config, $logger, $hooks, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
        );
    }

    public function testParseErrorHookContextAndStatusCode(): void
    {
        $hooks = null;
        $engine = $this->createEngine([], $hooks);
        $captured = [];
        $hooks->register(HookPoint::BEFORE_REQUEST, function (array $ctx) use (&$captured): array {
            $captured['BEFORE_REQUEST'] = $ctx;
            return $ctx;
        });
        $hooks->register(HookPoint::ON_ERROR, function (array $ctx) use (&$captured): array {
            $captured['ON_ERROR'] = $ctx;
            return $ctx;
        });
        $hooks->register(HookPoint::ON_RESPONSE, function (array $ctx) use (&$captured): array {
            $captured['ON_RESPONSE'] = $ctx;
            return $ctx;
        });
        $hooks->register(HookPoint::AFTER_REQUEST, function (array $ctx) use (&$captured): array {
            $captured['AFTER_REQUEST'] = $ctx;
            return $ctx;
        });

        $result = $engine->handleJson('{invalid json');

        $this->assertSame(200, $result->statusCode);
        $this->assertArrayHasKey('correlationId', $captured['BEFORE_REQUEST']);
        $this->assertSame('json_parse_error', $captured['ON_ERROR']['reason']);
        $this->assertSame(200, $captured['ON_RESPONSE']['status']);
        $this->assertArrayHasKey('correlationId', $captured['ON_RESPONSE']);
        $this->assertArrayHasKey('correlationId', $captured['AFTER_REQUEST']);
    }

    public function testInvalidTypeHookContextAndStatusCode(): void
    {
        $hooks = null;
        $engine = $this->createEngine([], $hooks);
        $captured = [];
        $hooks->register(HookPoint::ON_ERROR, function (array $ctx) use (&$captured): array {
            $captured['ON_ERROR'] = $ctx;
            return $ctx;
        });
        $hooks->register(HookPoint::ON_RESPONSE, function (array $ctx) use (&$captured): array {
            $captured['ON_RESPONSE'] = $ctx;
            return $ctx;
        });
        $hooks->register(HookPoint::AFTER_REQUEST, function (array $ctx) use (&$captured): array {
            $captured['AFTER_REQUEST'] = $ctx;
            return $ctx;
        });

        $result = $engine->handleJson('"just a string"');

        $this->assertSame(200, $result->statusCode);
        $this->assertSame('invalid_request_type', $captured['ON_ERROR']['reason']);
        $this->assertSame(200, $captured['ON_RESPONSE']['status']);
        $this->assertArrayHasKey('correlationId', $captured['ON_RESPONSE']);
        $this->assertArrayHasKey('correlationId', $captured['AFTER_REQUEST']);
    }

    public function testSingleSuccessfulResponseHookContext(): void
    {
        $hooks = null;
        $engine = $this->createEngine([], $hooks);
        $captured = [];
        $hooks->register(HookPoint::BEFORE_REQUEST, function (array $ctx) use (&$captured): array {
            $captured['BEFORE_REQUEST'] = $ctx;
            return $ctx;
        });
        $hooks->register(HookPoint::ON_RESPONSE, function (array $ctx) use (&$captured): array {
            $captured['ON_RESPONSE'] = $ctx;
            return $ctx;
        });
        $hooks->register(HookPoint::AFTER_REQUEST, function (array $ctx) use (&$captured): array {
            $captured['AFTER_REQUEST'] = $ctx;
            return $ctx;
        });

        $json = '{"jsonrpc":"2.0","method":"system.health","id":1}';
        $result = $engine->handleJson($json);

        $this->assertSame(200, $result->statusCode);
        $this->assertArrayHasKey('correlationId', $captured['BEFORE_REQUEST']);
        $this->assertSame(200, $captured['ON_RESPONSE']['status']);
        $this->assertArrayHasKey('correlationId', $captured['ON_RESPONSE']);
        $this->assertArrayHasKey('correlationId', $captured['AFTER_REQUEST']);
    }

    public function testBatchResponseHookContextAndStatusCode(): void
    {
        $hooks = null;
        $engine = $this->createEngine([], $hooks);
        $captured = [];
        $hooks->register(HookPoint::ON_RESPONSE, function (array $ctx) use (&$captured): array {
            $captured['ON_RESPONSE'] = $ctx;
            return $ctx;
        });

        $json = '[{"jsonrpc":"2.0","method":"system.health","id":1},{"jsonrpc":"2.0","method":"system.health","id":2}]';
        $result = $engine->handleJson($json);

        $this->assertSame(200, $result->statusCode);
        $this->assertSame(200, $captured['ON_RESPONSE']['status']);
        $this->assertStringStartsWith('[', $result->json);
    }

    public function testEmptyResponse204HookContext(): void
    {
        $hooks = null;
        $engine = $this->createEngine([
            'notifications' => ['enabled' => true, 'log' => false],
        ], $hooks);
        $captured = [];
        $hooks->register(HookPoint::ON_RESPONSE, function (array $ctx) use (&$captured): array {
            $captured['ON_RESPONSE'] = $ctx;
            return $ctx;
        });
        $hooks->register(HookPoint::AFTER_REQUEST, function (array $ctx) use (&$captured): array {
            $captured['AFTER_REQUEST'] = $ctx;
            return $ctx;
        });

        $json = '{"jsonrpc":"2.0","method":"system.health"}';
        $result = $engine->handleJson($json);

        $this->assertSame(204, $result->statusCode);
        $this->assertSame(204, $captured['ON_RESPONSE']['status']);
        $this->assertArrayHasKey('correlationId', $captured['ON_RESPONSE']);
        $this->assertArrayHasKey('correlationId', $captured['AFTER_REQUEST']);
    }

    public function testRateLimitExceededHookContextAndStatusCode(): void
    {
        $handlerPath = realpath(__DIR__ . '/../../../examples/basic/handlers');
        $config = new Config([
            'handlers' => ['paths' => [$handlerPath], 'namespace' => 'App\\Handlers\\'],
            'debug' => false,
            'logging' => ['enabled' => false],
            'auth' => ['enabled' => false],
            'rate_limit' => ['enabled' => true, 'max_requests' => 1, 'window' => 60, 'strategy' => 'ip'],
            'response_fingerprint' => ['enabled' => false],
            'hooks' => ['enabled' => true, 'isolate_exceptions' => false],
        ]);
        $hooks = new HookManager();
        $captured = [];
        $hooks->register(HookPoint::ON_ERROR, function (array $ctx) use (&$captured): array {
            $captured['ON_ERROR'] = $ctx;
            return $ctx;
        });
        $hooks->register(HookPoint::ON_RESPONSE, function (array $ctx) use (&$captured): array {
            $captured['ON_RESPONSE'] = $ctx;
            return $ctx;
        });
        $hooks->register(HookPoint::AFTER_REQUEST, function (array $ctx) use (&$captured): array {
            $captured['AFTER_REQUEST'] = $ctx;
            return $ctx;
        });

        $logger = new Logger('', 'none');
        $authManager = new AuthManager(false);
        $rateLimitManager = new RateLimitManager(true, 'ip', 1);
        $limiter = new \Lumen\JsonRpc\RateLimit\InMemoryRateLimiter(1, 60);
        $rateLimitManager->setLimiter($limiter);
        $fingerprinter = new ResponseFingerprinter(false, 'sha256');
        $validator = new RequestValidator(true);
        $batchProcessor = new BatchProcessor($validator, 100);
        $registry = new HandlerRegistry([$handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $engine = new JsonRpcEngine(
            $config, $logger, $hooks, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
        );

        $ctx = new RequestContext(correlationId: 'test-1', headers: [], clientIp: '127.0.0.1');
        $engine->handleJson('{"jsonrpc":"2.0","method":"system.health","id":1}', $ctx);
        $result = $engine->handleJson('{"jsonrpc":"2.0","method":"system.health","id":2}', $ctx);

        $this->assertSame(429, $result->statusCode);
        $this->assertSame('rate_limit_exceeded', $captured['ON_ERROR']['reason']);
        $this->assertSame(429, $captured['ON_RESPONSE']['status']);
        $this->assertArrayHasKey('correlationId', $captured['ON_RESPONSE']);
        $this->assertArrayHasKey('correlationId', $captured['AFTER_REQUEST']);

        $data = json_decode($result->json, true);
        $this->assertSame(-32000, $data['error']['code']);
        $this->assertArrayHasKey('X-RateLimit-Limit', $result->headers);
        $this->assertArrayHasKey('Retry-After', $result->headers);
    }

    public function testSchemaValidationEnabledCreatesValidator(): void
    {
        $handlerPath = realpath(__DIR__ . '/../../../examples/basic/handlers');
        $config = new Config([
            'handlers' => ['paths' => [$handlerPath], 'namespace' => 'App\\Handlers\\'],
            'debug' => false,
            'logging' => ['enabled' => false],
            'auth' => ['enabled' => false],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
            'validation' => ['schema' => ['enabled' => true]],
            'hooks' => ['enabled' => true, 'isolate_exceptions' => false],
        ]);

        $hooks = new HookManager();
        $logger = new Logger('', 'none');
        $authManager = new AuthManager(false);
        $rateLimitManager = new RateLimitManager(false);
        $fingerprinter = new ResponseFingerprinter(false, 'sha256');
        $validator = new RequestValidator(true);
        $batchProcessor = new BatchProcessor($validator, 100);
        $registry = new HandlerRegistry([$handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $engine = new JsonRpcEngine(
            $config, $logger, $hooks, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
        );

        $ref = new \ReflectionProperty($engine, 'schemaValidator');
        $ref->setAccessible(true);
        $this->assertNotNull($ref->getValue($engine));
    }

    public function testAuthEnabledWithProtectedMethodRequiresAuth(): void
    {
        $handlerPath = realpath(__DIR__ . '/../../../examples/basic/handlers');
        $config = new Config([
            'handlers' => ['paths' => [$handlerPath], 'namespace' => 'App\\Handlers\\'],
            'debug' => false,
            'logging' => ['enabled' => false],
            'auth' => ['enabled' => true, 'protected_methods' => ['user.']],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
            'hooks' => ['enabled' => true, 'isolate_exceptions' => false],
        ]);

        $hooks = new HookManager();
        $captured = [];
        $hooks->register(HookPoint::ON_ERROR, function (array $ctx) use (&$captured): array {
            $captured[] = $ctx;
            return $ctx;
        });
        $logger = new Logger('', 'none');
        $authManager = new AuthManager(true);
        $rateLimitManager = new RateLimitManager(false);
        $fingerprinter = new ResponseFingerprinter(false, 'sha256');
        $validator = new RequestValidator(true);
        $batchProcessor = new BatchProcessor($validator, 100);
        $registry = new HandlerRegistry([$handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $engine = new JsonRpcEngine(
            $config, $logger, $hooks, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
        );

        $json = '{"jsonrpc":"2.0","method":"user.create","params":{"name":"a","email":"b"},"id":1}';
        $ctx = new RequestContext(correlationId: 'auth-test', headers: ['Authorization' => 'Bearer invalid'], clientIp: '127.0.0.1');
        $result = $engine->handleJson($json, $ctx);

        $data = json_decode($result->json, true);
        $this->assertSame(-32001, $data['error']['code']);
        $authErrorFound = false;
        foreach ($captured as $c) {
            if (($c['reason'] ?? '') === 'auth_required') {
                $authErrorFound = true;
                $this->assertSame('user.create', $c['method']);
            }
        }
        $this->assertTrue($authErrorFound);
    }

    public function testRawIsObjectDetection(): void
    {
        $hooks = null;
        $engine = $this->createEngine([], $hooks);

        $json = '{"jsonrpc":"2.0","method":"system.health","id":1}';
        $result = $engine->handleJson($json);
        $data = json_decode($result->json, true);
        $this->assertFalse(isset($data[0]));
    }

    public function testAuthFromHeadersWithEnabledAuth(): void
    {
        $handlerPath = realpath(__DIR__ . '/../../../examples/basic/handlers');
        $config = new Config([
            'handlers' => ['paths' => [$handlerPath], 'namespace' => 'App\\Handlers\\'],
            'debug' => false,
            'logging' => ['enabled' => false],
            'auth' => ['enabled' => true],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
            'hooks' => ['enabled' => true, 'isolate_exceptions' => false],
        ]);

        $hooks = new HookManager();
        $logger = new Logger('', 'none');
        $authManager = new AuthManager(true);
        $rateLimitManager = new RateLimitManager(false);
        $fingerprinter = new ResponseFingerprinter(false, 'sha256');
        $validator = new RequestValidator(true);
        $batchProcessor = new BatchProcessor($validator, 100);
        $registry = new HandlerRegistry([$handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $engine = new JsonRpcEngine(
            $config, $logger, $hooks, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
        );

        $context = new RequestContext(correlationId: 'test', headers: [], clientIp: '127.0.0.1');
        $result = $engine->authenticateFromHeaders([], $context);
        $this->assertFalse($result->hasAuth());
    }

    public function testProcessRequestNotificationDisabledReturnsNull(): void
    {
        $hooks = null;
        $engine = $this->createEngine([
            'notifications' => ['enabled' => false],
        ], $hooks);

        $context = new RequestContext(correlationId: 'test', headers: [], clientIp: '127.0.0.1');
        $request = new \Lumen\JsonRpc\Protocol\Request(
            method: 'system.health',
            id: null,
            params: null,
            idProvided: false,
        );

        $reflection = new \ReflectionMethod($engine, 'processRequest');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($engine, $request, $context);
        $this->assertNull($result);
    }

    public function testBeforeHandlerHookContextContainsMethodAndParams(): void
    {
        $hooks = null;
        $engine = $this->createEngine([], $hooks);
        $captured = [];
        $hooks->register(HookPoint::BEFORE_HANDLER, function (array $ctx) use (&$captured): array {
            $captured = $ctx;
            return $ctx;
        });

        $json = '{"jsonrpc":"2.0","method":"system.health","id":1}';
        $engine->handleJson($json);

        $this->assertSame('system.health', $captured['method']);
        $this->assertNull($captured['params']);
        $this->assertInstanceOf(RequestContext::class, $captured['context']);
    }

    public function testAfterHandlerHookContextContainsMethodAndResult(): void
    {
        $hooks = null;
        $engine = $this->createEngine([], $hooks);
        $captured = [];
        $hooks->register(HookPoint::AFTER_HANDLER, function (array $ctx) use (&$captured): array {
            $captured = $ctx;
            return $ctx;
        });

        $json = '{"jsonrpc":"2.0","method":"system.health","id":1}';
        $engine->handleJson($json);

        $this->assertSame('system.health', $captured['method']);
        $this->assertIsArray($captured['result']);
        $this->assertSame('ok', $captured['result']['status']);
    }

    public function testEmptyJsonReturnsInvalidRequest(): void
    {
        $hooks = null;
        $engine = $this->createEngine([], $hooks);

        $result = $engine->handleJson('');
        $this->assertSame(200, $result->statusCode);

        $result2 = $engine->handleJson('null');
        $data = json_decode($result2->json, true);
        $this->assertSame(-32600, $data['error']['code']);
    }

    public function testMaxJsonDepthConfigUsed(): void
    {
        $hooks = null;
        $engine = $this->createEngine([
            'limits' => ['max_json_depth' => 1],
        ], $hooks);

        $result = $engine->handleJson('{"jsonrpc":"2.0","method":"system.health","id":1}');
        $this->assertSame(200, $result->statusCode);
    }

    public function testBeforeRequestCorrelationIdFromContext(): void
    {
        $hooks = null;
        $engine = $this->createEngine([], $hooks);
        $captured = [];
        $hooks->register(HookPoint::BEFORE_REQUEST, function (array $ctx) use (&$captured): array {
            $captured = $ctx;
            return $ctx;
        });

        $ctx = new RequestContext(correlationId: 'custom-id-123', headers: [], clientIp: '127.0.0.1');
        $engine->handleJson('{"jsonrpc":"2.0","method":"system.health","id":1}', $ctx);

        $this->assertSame('custom-id-123', $captured['correlationId']);
    }

    public function testUnwrapArrayMergeInBatchProcessing(): void
    {
        $hooks = null;
        $engine = $this->createEngine([], $hooks);

        $json = '[{"jsonrpc":"2.0","method":"nonexistent","id":1}]';
        $result = $engine->handleJson($json);
        $data = json_decode($result->json, true);
        $this->assertIsArray($data);
        $this->assertTrue(count($data) === 1);
        $this->assertSame(-32601, $data[0]['error']['code']);
    }

    public function testMethodNotFoundErrorHookContext(): void
    {
        $hooks = null;
        $engine = $this->createEngine([], $hooks);
        $captured = [];
        $hooks->register(HookPoint::ON_ERROR, function (array $ctx) use (&$captured): array {
            $captured[] = $ctx;
            return $ctx;
        });

        $json = '{"jsonrpc":"2.0","method":"nonexistent","id":1}';
        $result = $engine->handleJson($json);

        $data = json_decode($result->json, true);
        $this->assertSame(-32601, $data['error']['code']);

        $found = false;
        foreach ($captured as $c) {
            if (isset($c['method']) && $c['method'] === 'nonexistent') {
                $found = true;
                $this->assertInstanceOf(\Throwable::class, $c['exception']);
            }
        }
        $this->assertTrue($found);
    }

    public function testNotificationLogEnabledCallsLogger(): void
    {
        $handlerPath = realpath(__DIR__ . '/../../../examples/basic/handlers');
        $config = new Config([
            'handlers' => ['paths' => [$handlerPath], 'namespace' => 'App\\Handlers\\'],
            'debug' => false,
            'logging' => ['enabled' => true, 'path' => sys_get_temp_dir() . '/test_engine_' . uniqid() . '.log'],
            'auth' => ['enabled' => false],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
            'hooks' => ['enabled' => true, 'isolate_exceptions' => false],
            'notifications' => ['enabled' => true, 'log' => true],
        ]);

        $logPath = $config->get('logging.path');
        $hooks = new HookManager();
        $logger = new Logger($logPath, 'info');
        $authManager = new AuthManager(false);
        $rateLimitManager = new RateLimitManager(false);
        $fingerprinter = new ResponseFingerprinter(false, 'sha256');
        $validator = new RequestValidator(true);
        $batchProcessor = new BatchProcessor($validator, 100);
        $registry = new HandlerRegistry([$handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $engine = new JsonRpcEngine(
            $config, $logger, $hooks, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
        );

        $json = '{"jsonrpc":"2.0","method":"system.health"}';
        $result = $engine->handleJson($json);
        $this->assertSame(204, $result->statusCode);

        $logContent = file_exists($logPath) ? file_get_contents($logPath) : '';
        @unlink($logPath);
        $this->assertStringContainsString('Notification processed', $logContent);
    }
}
