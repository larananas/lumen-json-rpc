<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Core;

use Lumen\JsonRpc\Auth\AuthManager;
use Lumen\JsonRpc\Auth\RequestAuthenticatorInterface;
use Lumen\JsonRpc\Auth\UserContext;
use Lumen\JsonRpc\Cache\ResponseFingerprinter;
use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Core\JsonRpcEngine;
use Lumen\JsonRpc\Dispatcher\HandlerDispatcher;
use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Dispatcher\MethodResolver;
use Lumen\JsonRpc\Dispatcher\ParameterBinder;
use Lumen\JsonRpc\Log\Logger;
use Lumen\JsonRpc\Middleware\MiddlewareInterface;
use Lumen\JsonRpc\Protocol\BatchProcessor;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Protocol\RequestValidator;
use Lumen\JsonRpc\RateLimit\RateLimitManager;
use Lumen\JsonRpc\RateLimit\InMemoryRateLimiter;
use Lumen\JsonRpc\Support\HookManager;
use Lumen\JsonRpc\Support\HookPoint;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class JsonRpcEngineExtendedTest extends TestCase
{
    private string $handlerPath;

    protected function setUp(): void
    {
        $this->handlerPath = realpath(__DIR__ . '/../../../examples/basic/handlers') ?: __DIR__ . '/../../../examples/basic/handlers';
    }

    private function createEngine(array $overrides = [], ?RequestAuthenticatorInterface $requestAuth = null): JsonRpcEngine
    {
        $defaults = [
            'handlers' => [
                'paths' => [$this->handlerPath],
                'namespace' => 'App\\Handlers\\',
            ],
            'debug' => true,
            'logging' => ['enabled' => false],
            'auth' => ['enabled' => false],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
        ];
        $config = new Config(array_merge($defaults, $overrides));
        $hooks = new HookManager();
        $logger = new Logger('', 'none');
        $authManager = new AuthManager(false);
        $rateLimitManager = new RateLimitManager(false);
        $fingerprinter = new ResponseFingerprinter(false, 'sha256');
        $validator = new RequestValidator(true);
        $batchProcessor = new BatchProcessor($validator, 100);
        $registry = new HandlerRegistry(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.',
        );
        $registry->discover();
        $resolver = new MethodResolver(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.',
        );
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        return new JsonRpcEngine(
            $config, $logger, $hooks, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
            $requestAuth,
        );
    }

    public function testGetLoggerReturnsLogger(): void
    {
        $engine = $this->createEngine();
        $this->assertInstanceOf(Logger::class, $engine->getLogger());
    }

    public function testGetHooksReturnsHookManager(): void
    {
        $engine = $this->createEngine();
        $this->assertInstanceOf(HookManager::class, $engine->getHooks());
    }

    public function testGetRegistryReturnsHandlerRegistry(): void
    {
        $engine = $this->createEngine();
        $this->assertInstanceOf(HandlerRegistry::class, $engine->getRegistry());
    }

    public function testGetAuthManagerReturnsAuthManager(): void
    {
        $engine = $this->createEngine();
        $this->assertInstanceOf(AuthManager::class, $engine->getAuthManager());
    }

    public function testGetFingerprinterReturnsFingerprinter(): void
    {
        $engine = $this->createEngine();
        $this->assertInstanceOf(ResponseFingerprinter::class, $engine->getFingerprinter());
    }

    public function testGetRateLimitManagerReturnsManager(): void
    {
        $engine = $this->createEngine();
        $this->assertInstanceOf(RateLimitManager::class, $engine->getRateLimitManager());
    }

    public function testGetConfigReturnsConfig(): void
    {
        $engine = $this->createEngine();
        $this->assertInstanceOf(Config::class, $engine->getConfig());
    }

    public function testGetMiddlewarePipelineReturnsPipeline(): void
    {
        $engine = $this->createEngine();
        $this->assertTrue($engine->getMiddlewarePipeline()->isEmpty());
    }

    public function testAddMiddlewareReturnsSelf(): void
    {
        $engine = $this->createEngine();
        $middleware = new class implements MiddlewareInterface {
            public function process(Request $request, RequestContext $context, callable $next): ?\Lumen\JsonRpc\Protocol\Response
            {
                return $next($request, $context);
            }
        };
        $result = $engine->addMiddleware($middleware);
        $this->assertSame($engine, $result);
    }

    public function testSetRequestAuthenticatorReturnsSelf(): void
    {
        $engine = $this->createEngine();
        $auth = new class implements RequestAuthenticatorInterface {
            public function authenticateFromHeaders(array $headers): ?UserContext
            {
                return null;
            }
        };
        $result = $engine->setRequestAuthenticator($auth);
        $this->assertSame($engine, $result);
    }

    public function testHandleJsonWithEmptyStringReturnsInvalidRequest(): void
    {
        $engine = $this->createEngine();
        $result = $engine->handleJson('');
        $data = json_decode($result->json, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testHandleJsonWithNonObjectReturnsInvalidRequest(): void
    {
        $engine = $this->createEngine();
        $result = $engine->handleJson('"just a string"');
        $data = json_decode($result->json, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testHandleJsonWithNumberReturnsInvalidRequest(): void
    {
        $engine = $this->createEngine();
        $result = $engine->handleJson('42');
        $data = json_decode($result->json, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testHandleJsonWithTrueReturnsInvalidRequest(): void
    {
        $engine = $this->createEngine();
        $result = $engine->handleJson('true');
        $data = json_decode($result->json, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testHandleJsonWithNullReturnsInvalidRequest(): void
    {
        $engine = $this->createEngine();
        $result = $engine->handleJson('null');
        $data = json_decode($result->json, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testHandleJsonWithValidBatch(): void
    {
        $engine = $this->createEngine();
        $json = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'system.version', 'id' => 2],
        ]);
        $result = $engine->handleJson($json);
        $data = json_decode($result->json, true);
        $this->assertIsArray($data);
        $this->assertCount(2, $data);
        $this->assertEquals(200, $result->statusCode);
    }

    public function testHandleJsonWithAllNotificationsReturns204(): void
    {
        $engine = $this->createEngine();
        $json = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health'],
            ['jsonrpc' => '2.0', 'method' => 'system.version'],
        ]);
        $result = $engine->handleJson($json);
        $this->assertEquals(204, $result->statusCode);
        $this->assertNull($result->json);
    }

    public function testHandleJsonWithNotificationsDisabledReturnsNull(): void
    {
        $engine = $this->createEngine(['notifications' => ['enabled' => false]]);
        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health']);
        $result = $engine->handleJson($json);
        $this->assertEquals(204, $result->statusCode);
    }

    public function testHandleJsonWithHookFired(): void
    {
        $hookManager = new HookManager();
        $fired = [];
        $hookManager->register(HookPoint::BEFORE_REQUEST, function (array $ctx) use (&$fired): array {
            $fired[] = 'before';
            return $ctx;
        });
        $hookManager->register(HookPoint::AFTER_REQUEST, function (array $ctx) use (&$fired): array {
            $fired[] = 'after';
            return $ctx;
        });

        $config = new Config([
            'handlers' => ['paths' => [$this->handlerPath], 'namespace' => 'App\\Handlers\\'],
            'debug' => true,
            'logging' => ['enabled' => false],
            'auth' => ['enabled' => false],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
            'hooks' => ['enabled' => true],
        ]);
        $logger = new Logger('', 'none');
        $authManager = new AuthManager(false);
        $rateLimitManager = new RateLimitManager(false);
        $fingerprinter = new ResponseFingerprinter(false, 'sha256');
        $validator = new RequestValidator(true);
        $batchProcessor = new BatchProcessor($validator, 100);
        $registry = new HandlerRegistry([$this->handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$this->handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $engine = new JsonRpcEngine(
            $config, $logger, $hookManager, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
        );

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $engine->handleJson($json);

        $this->assertContains('before', $fired);
        $this->assertContains('after', $fired);
    }

    public function testAuthenticateFromHeadersWithAuthEnabled(): void
    {
        $requestAuth = new class implements RequestAuthenticatorInterface {
            public function authenticateFromHeaders(array $headers): ?UserContext
            {
                if (isset($headers['Authorization'])) {
                    return new UserContext('user-1', ['source' => 'test'], ['user']);
                }
                return null;
            }
        };

        $config = new Config([
            'handlers' => ['paths' => [$this->handlerPath], 'namespace' => 'App\\Handlers\\'],
            'debug' => true,
            'logging' => ['enabled' => false],
            'auth' => ['enabled' => true],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
        ]);
        $hooks = new HookManager();
        $logger = new Logger('', 'none');
        $authManager = new AuthManager(true);
        $rateLimitManager = new RateLimitManager(false);
        $fingerprinter = new ResponseFingerprinter(false, 'sha256');
        $validator = new RequestValidator(true);
        $batchProcessor = new BatchProcessor($validator, 100);
        $registry = new HandlerRegistry([$this->handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$this->handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $engine = new JsonRpcEngine(
            $config, $logger, $hooks, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
            $requestAuth,
        );

        $context = new RequestContext('corr-1', ['Authorization' => 'Bearer token'], '127.0.0.1');
        $result = $engine->authenticateFromHeaders(['Authorization' => 'Bearer token'], $context);

        $this->assertTrue($result->hasAuth());
        $this->assertEquals('user-1', $result->authUserId);
    }

    public function testAuthenticateFromHeadersFailureReturnsSameContext(): void
    {
        $requestAuth = new class implements RequestAuthenticatorInterface {
            public function authenticateFromHeaders(array $headers): ?UserContext
            {
                return null;
            }
        };

        $config = new Config([
            'handlers' => ['paths' => [$this->handlerPath], 'namespace' => 'App\\Handlers\\'],
            'auth' => ['enabled' => true],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
        ]);
        $hooks = new HookManager();
        $logger = new Logger('', 'none');
        $authManager = new AuthManager(true);
        $rateLimitManager = new RateLimitManager(false);
        $fingerprinter = new ResponseFingerprinter(false, 'sha256');
        $validator = new RequestValidator(true);
        $batchProcessor = new BatchProcessor($validator, 100);
        $registry = new HandlerRegistry([$this->handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$this->handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $engine = new JsonRpcEngine(
            $config, $logger, $hooks, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
            $requestAuth,
        );

        $context = new RequestContext('corr-1', [], '127.0.0.1');
        $result = $engine->authenticateFromHeaders([], $context);

        $this->assertFalse($result->hasAuth());
    }

    public function testAuthenticateFromHeadersWithAuthDisabledReturnsSameContext(): void
    {
        $engine = $this->createEngine(['auth' => ['enabled' => false]]);
        $context = new RequestContext('corr-1', ['Authorization' => 'Bearer token'], '127.0.0.1');
        $result = $engine->authenticateFromHeaders(['Authorization' => 'Bearer token'], $context);
        $this->assertFalse($result->hasAuth());
    }

    public function testProcessRequestWithAuthProtectedMethod(): void
    {
        $config = new Config([
            'handlers' => ['paths' => [$this->handlerPath], 'namespace' => 'App\\Handlers\\'],
            'debug' => true,
            'logging' => ['enabled' => false],
            'auth' => [
                'enabled' => true,
                'protected_methods' => ['user.'],
            ],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
        ]);
        $hooks = new HookManager();
        $logger = new Logger('', 'none');
        $authManager = new AuthManager(true);
        $rateLimitManager = new RateLimitManager(false);
        $fingerprinter = new ResponseFingerprinter(false, 'sha256');
        $validator = new RequestValidator(true);
        $batchProcessor = new BatchProcessor($validator, 100);
        $registry = new HandlerRegistry([$this->handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$this->handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $engine = new JsonRpcEngine(
            $config, $logger, $hooks, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
        );

        $context = new RequestContext('corr-1', [], '127.0.0.1');
        $request = new Request('user.get', 1, ['id' => 1], true);
        $response = $engine->processRequest($request, $context);

        $this->assertNotNull($response);
        $data = json_decode($response->toJson(), true);
        $this->assertEquals(-32001, $data['error']['code']);
        $this->assertEquals('Authentication required', $data['error']['message']);
    }

    public function testProcessRequestWithProtectedNotificationReturnsNull(): void
    {
        $config = new Config([
            'handlers' => ['paths' => [$this->handlerPath], 'namespace' => 'App\\Handlers\\'],
            'auth' => [
                'enabled' => true,
                'protected_methods' => ['user.'],
            ],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
        ]);
        $hooks = new HookManager();
        $logger = new Logger('', 'none');
        $authManager = new AuthManager(true);
        $rateLimitManager = new RateLimitManager(false);
        $fingerprinter = new ResponseFingerprinter(false, 'sha256');
        $validator = new RequestValidator(true);
        $batchProcessor = new BatchProcessor($validator, 100);
        $registry = new HandlerRegistry([$this->handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$this->handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $engine = new JsonRpcEngine(
            $config, $logger, $hooks, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
        );

        $context = new RequestContext('corr-1', [], '127.0.0.1');
        $request = new Request('user.get', null, ['id' => 1], false);
        $response = $engine->processRequest($request, $context);

        $this->assertNull($response);
    }

    public function testProcessRequestNotificationWithDebugEnabled(): void
    {
        $engine = $this->createEngine(['debug' => true]);
        $request = new Request('system.health', null, null, false);
        $context = new RequestContext('corr-1', [], '127.0.0.1');
        $response = $engine->processRequest($request, $context);
        $this->assertNull($response);
    }

    public function testProcessRequestHandlerErrorWithDebug(): void
    {
        $engine = $this->createEngine(['debug' => true]);
        $request = new Request('user.get', 1, ['id' => 'not-int'], true);
        $context = new RequestContext('corr-1', [], '127.0.0.1');
        $response = $engine->processRequest($request, $context);

        $this->assertNotNull($response);
        $data = json_decode($response->toJson(), true);
        $this->assertEquals(-32602, $data['error']['code']);
        $this->assertArrayHasKey('debug', $data['error']['data']);
    }

    public function testProcessRequestHandlerErrorWithoutDebug(): void
    {
        $engine = $this->createEngine(['debug' => false]);
        $request = new Request('user.get', 1, ['id' => 'not-int'], true);
        $context = new RequestContext('corr-1', [], '127.0.0.1');
        $response = $engine->processRequest($request, $context);

        $this->assertNotNull($response);
        $data = json_decode($response->toJson(), true);
        $this->assertEquals(-32602, $data['error']['code']);
    }

    public function testProcessRequestNotificationErrorReturnsNull(): void
    {
        $engine = $this->createEngine();
        $request = new Request('user.get', null, ['id' => 'not-int'], false);
        $context = new RequestContext('corr-1', [], '127.0.0.1');
        $response = $engine->processRequest($request, $context);
        $this->assertNull($response);
    }

    public function testHandleJsonWithRateLimitExceeded(): void
    {
        $config = new Config([
            'handlers' => ['paths' => [$this->handlerPath], 'namespace' => 'App\\Handlers\\'],
            'rate_limit' => [
                'enabled' => true,
                'max_requests' => 1,
                'window_seconds' => 60,
            ],
            'auth' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
        ]);
        $hooks = new HookManager();
        $logger = new Logger('', 'none');
        $authManager = new AuthManager(false);
        $rateLimitManager = new RateLimitManager(true, 'ip', 1);
        $limiter = new InMemoryRateLimiter(1, 60);
        $rateLimitManager->setLimiter($limiter);
        $fingerprinter = new ResponseFingerprinter(false, 'sha256');
        $validator = new RequestValidator(true);
        $batchProcessor = new BatchProcessor($validator, 100);
        $registry = new HandlerRegistry([$this->handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$this->handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $engine = new JsonRpcEngine(
            $config, $logger, $hooks, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
        );

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $engine->handleJson($json);

        $result = $engine->handleJson($json);
        $this->assertEquals(429, $result->statusCode);
        $data = json_decode($result->json, true);
        $this->assertEquals(-32000, $data['error']['code']);
        $this->assertArrayHasKey('X-RateLimit-Limit', $result->headers);
    }

    public function testHandleJsonWithProtectedMethodAuthRequired(): void
    {
        $config = new Config([
            'handlers' => ['paths' => [$this->handlerPath], 'namespace' => 'App\\Handlers\\'],
            'auth' => [
                'enabled' => true,
                'protected_methods' => ['user.'],
            ],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
        ]);
        $hooks = new HookManager();
        $logger = new Logger('', 'none');
        $authManager = new AuthManager(true);
        $rateLimitManager = new RateLimitManager(false);
        $fingerprinter = new ResponseFingerprinter(false, 'sha256');
        $validator = new RequestValidator(true);
        $batchProcessor = new BatchProcessor($validator, 100);
        $registry = new HandlerRegistry([$this->handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$this->handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $engine = new JsonRpcEngine(
            $config, $logger, $hooks, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
        );

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'id' => 1, 'params' => ['id' => 1]]);
        $result = $engine->handleJson($json);
        $data = json_decode($result->json, true);
        $this->assertEquals(-32001, $data['error']['code']);
    }

    public function testMiddlewareExecutesOnRequest(): void
    {
        $engine = $this->createEngine();
        $middleware = new class implements MiddlewareInterface {
            public bool $called = false;
            public function process(Request $request, RequestContext $context, callable $next): ?\Lumen\JsonRpc\Protocol\Response
            {
                $this->called = true;
                return $next($request, $context);
            }
        };

        $engine->addMiddleware($middleware);
        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $engine->handleJson($json);

        $this->assertTrue($middleware->called);
    }

    public function testHookIsolationCatchesException(): void
    {
        $hooks = new HookManager();
        $hooks->register(HookPoint::BEFORE_REQUEST, function (array $ctx): array {
            throw new \RuntimeException('Hook failed');
        });

        $config = new Config([
            'handlers' => ['paths' => [$this->handlerPath], 'namespace' => 'App\\Handlers\\'],
            'hooks' => ['enabled' => true, 'isolate_exceptions' => true],
            'auth' => ['enabled' => false],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
        ]);
        $logger = new Logger('', 'none');
        $authManager = new AuthManager(false);
        $rateLimitManager = new RateLimitManager(false);
        $fingerprinter = new ResponseFingerprinter(false, 'sha256');
        $validator = new RequestValidator(true);
        $batchProcessor = new BatchProcessor($validator, 100);
        $registry = new HandlerRegistry([$this->handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$this->handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $engine = new JsonRpcEngine(
            $config, $logger, $hooks, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
        );

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $engine->handleJson($json);
        $data = json_decode($result->json, true);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testProcessRequestDispatchesSuccessfully(): void
    {
        $engine = $this->createEngine();
        $request = new Request('system.health', 1, null, true);
        $context = new RequestContext('corr-1', [], '127.0.0.1');
        $response = $engine->processRequest($request, $context);

        $this->assertNotNull($response);
        $data = json_decode($response->toJson(), true);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testHandleJsonWithInvalidJsonReturnsParseError(): void
    {
        $engine = $this->createEngine();
        $result = $engine->handleJson('{invalid json');
        $data = json_decode($result->json, true);
        $this->assertEquals(-32700, $data['error']['code']);
    }

    public function testHandleJsonWithContextAuthFromHeaders(): void
    {
        $requestAuth = new class implements RequestAuthenticatorInterface {
            public function authenticateFromHeaders(array $headers): ?UserContext
            {
                if (isset($headers['Authorization'])) {
                    return new UserContext('auth-user', [], []);
                }
                return null;
            }
        };

        $config = new Config([
            'handlers' => ['paths' => [$this->handlerPath], 'namespace' => 'App\\Handlers\\'],
            'auth' => ['enabled' => true],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
        ]);
        $hooks = new HookManager();
        $logger = new Logger('', 'none');
        $authManager = new AuthManager(true);
        $rateLimitManager = new RateLimitManager(false);
        $fingerprinter = new ResponseFingerprinter(false, 'sha256');
        $validator = new RequestValidator(true);
        $batchProcessor = new BatchProcessor($validator, 100);
        $registry = new HandlerRegistry([$this->handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$this->handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $engine = new JsonRpcEngine(
            $config, $logger, $hooks, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
            $requestAuth,
        );

        $context = new RequestContext('corr-1', ['Authorization' => 'Bearer token'], '127.0.0.1');
        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $engine->handleJson($json, $context);
        $data = json_decode($result->json, true);
        $this->assertEquals('ok', $data['result']['status']);
    }
}
