<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Dispatcher\ProcedureDescriptor;
use Lumen\JsonRpc\Server\JsonRpcServer;
use PHPUnit\Framework\TestCase;

final class DescriptorEdgeCaseTest extends TestCase
{
    private function createServer(array $configOverrides = []): JsonRpcServer
    {
        $config = new Config(array_merge([
            'handlers' => [
                'paths' => [__DIR__ . '/../../examples/basic/handlers'],
                'namespace' => 'App\\Handlers\\',
            ],
            'logging' => ['enabled' => false],
            'health' => ['enabled' => false],
            'auth' => ['enabled' => false],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
        ], $configOverrides));

        return new JsonRpcServer($config);
    }

    public function testDescriptorWithNonExistentClassReturnsMethodNotFound(): void
    {
        $server = $this->createServer();
        $server->getRegistry()->register('test.missing', 'NonExistentClass', 'someMethod');

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"test.missing","id":1}'
        );

        $decoded = json_decode($result, true);
        $this->assertEquals(-32601, $decoded['error']['code']);
        $this->assertStringContainsString('not found', $decoded['error']['message']);
    }

    public function testDescriptorWithNonExistentMethodReturnsMethodNotFound(): void
    {
        $server = $this->createServer();
        $server->getRegistry()->register(
            'test.badmethod',
            'App\\Handlers\\System',
            'nonExistentMethod'
        );

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"test.badmethod","id":1}'
        );

        $decoded = json_decode($result, true);
        $this->assertEquals(-32601, $decoded['error']['code']);
    }

    public function testDescriptorWithErrorsMetadataUsedByDocGenerator(): void
    {
        $server = $this->createServer();
        $server->getRegistry()->registerDescriptor(
            new ProcedureDescriptor(
                method: 'test.errors',
                handlerClass: 'App\\Handlers\\System',
                handlerMethod: 'health',
                metadata: [
                    'description' => 'Test method with errors',
                    'errors' => [
                        ['code' => '32001', 'description' => 'Auth required'],
                        ['code' => '32002', 'message' => 'Rate limited', 'description' => 'Too many requests'],
                    ],
                ],
            ),
        );

        $docGenerator = new \Lumen\JsonRpc\Doc\DocGenerator($server->getRegistry());
        $docs = $docGenerator->generate();

        $testMethod = null;
        foreach ($docs as $doc) {
            if ($doc->name === 'test.errors') {
                $testMethod = $doc;
                break;
            }
        }

        $this->assertNotNull($testMethod);
        $this->assertEquals('Test method with errors', $testMethod->description);
        $this->assertCount(2, $testMethod->errors);
        $this->assertEquals('32001', $testMethod->errors[0]['code']);
        $this->assertEquals('Auth required', $testMethod->errors[0]['description']);
    }

    public function testDescriptorCollisionWithAutoDiscovery(): void
    {
        $server = $this->createServer();
        $server->getRegistry()->registerDescriptor(
            new ProcedureDescriptor(
                method: 'system.health',
                handlerClass: 'App\\Handlers\\User',
                handlerMethod: 'get',
                metadata: ['description' => 'Overridden health'],
            ),
        );

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"system.health","params":{"id":1},"id":1}'
        );

        $decoded = json_decode($result, true);
        $this->assertArrayHasKey('result', $decoded);
        $this->assertArrayHasKey('id', $decoded['result']);
    }

    public function testDescriptorOnlyModeNoHandlerPaths(): void
    {
        $server = $this->createServer([
            'handlers' => [
                'paths' => [],
                'namespace' => 'App\\Handlers\\',
            ],
        ]);

        $server->getRegistry()->registerDescriptor(
            new ProcedureDescriptor(
                method: 'test.hello',
                handlerClass: 'App\\Handlers\\System',
                handlerMethod: 'health',
            ),
        );

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"test.hello","id":1}'
        );

        $decoded = json_decode($result, true);
        $this->assertArrayHasKey('result', $decoded);
    }

    public function testAutoDiscoveredMethodNotAvailableWithoutPaths(): void
    {
        $server = $this->createServer([
            'handlers' => [
                'paths' => [],
                'namespace' => 'App\\Handlers\\',
            ],
        ]);

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"system.health","id":1}'
        );

        $decoded = json_decode($result, true);
        $this->assertEquals(-32601, $decoded['error']['code']);
    }
}
