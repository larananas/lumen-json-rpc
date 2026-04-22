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
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class JsonRpcEngineTest extends TestCase
{
    private string $handlerPath;

    protected function setUp(): void
    {
        $this->handlerPath = realpath(__DIR__ . '/../../../examples/basic/handlers') ?: __DIR__ . '/../../../examples/basic/handlers';
    }

    private function createEngine(array $overrides = []): JsonRpcEngine
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
        $handlerPaths = $this->handlerPaths($config);
        $handlerNamespace = $this->handlerNamespace($config);
        $handlerSeparator = $this->handlerSeparator($config);
        $registry = new HandlerRegistry(
            $handlerPaths,
            $handlerNamespace,
            $handlerSeparator,
        );
        $registry->discover();
        $resolver = new MethodResolver(
            $handlerPaths,
            $handlerNamespace,
            $handlerSeparator,
        );
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        return new JsonRpcEngine(
            $config, $logger, $hooks, $authManager, $rateLimitManager,
            $fingerprinter, $batchProcessor, $dispatcher, $registry,
        );
    }

    /**
     * @return list<string>
     */
    private function handlerPaths(Config $config): array
    {
        $paths = $config->get('handlers.paths', []);
        if (!is_array($paths)) {
            throw new \RuntimeException('handlers.paths must be an array');
        }

        $strings = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                throw new \RuntimeException('handlers.paths must contain only non-empty strings');
            }

            $strings[] = $path;
        }

        return $strings;
    }

    private function handlerNamespace(Config $config): string
    {
        $namespace = $config->get('handlers.namespace', 'App\\Handlers\\');
        if (!is_string($namespace)) {
            throw new \RuntimeException('handlers.namespace must be a string');
        }

        return $namespace;
    }

    private function handlerSeparator(Config $config): string
    {
        $separator = $config->get('handlers.method_separator', '.');
        if (!is_string($separator)) {
            throw new \RuntimeException('handlers.method_separator must be a string');
        }

        return $separator;
    }

    public function testSingleRequestReturnsResult(): void
    {
        $engine = $this->createEngine();
        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $engine->handleJson($json);
        $this->assertNotNull($result->json);
        $data = json_decode($result->json, true);
        $this->assertEquals('ok', $data['result']['status']);
        $this->assertEquals(200, $result->statusCode);
    }

    public function testBatchRequestReturnsArray(): void
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
    }

    public function testNotificationReturnsNoContent(): void
    {
        $engine = $this->createEngine();
        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health']);
        $result = $engine->handleJson($json);
        $this->assertTrue($result->isNoContent());
        $this->assertEquals(204, $result->statusCode);
    }

    public function testInvalidJsonReturnsParseError(): void
    {
        $engine = $this->createEngine();
        $result = $engine->handleJson('{invalid json');
        $data = json_decode($result->json, true);
        $this->assertEquals(-32700, $data['error']['code']);
    }

    public function testMethodNotFound(): void
    {
        $engine = $this->createEngine();
        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'nonexistent.method', 'id' => 1]);
        $result = $engine->handleJson($json);
        $data = json_decode($result->json, true);
        $this->assertEquals(-32601, $data['error']['code']);
    }

    public function testCustomRequestContext(): void
    {
        $engine = $this->createEngine();
        $context = new RequestContext(
            correlationId: 'custom-id',
            headers: ['X-Test' => 'value'],
            clientIp: '10.0.0.1',
        );
        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $engine->handleJson($json, $context);
        $data = json_decode($result->json, true);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testEmptyBatchReturnsError(): void
    {
        $engine = $this->createEngine();
        $result = $engine->handleJson('[]');
        $data = json_decode($result->json, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testEngineResultIsNoContentHelper(): void
    {
        $result = new \Lumen\JsonRpc\Core\EngineResult(null, 204);
        $this->assertTrue($result->isNoContent());
        $result2 = new \Lumen\JsonRpc\Core\EngineResult('{}', 200);
        $this->assertFalse($result2->isNoContent());
    }
}
