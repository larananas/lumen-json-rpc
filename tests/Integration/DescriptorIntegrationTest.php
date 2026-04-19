<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Dispatcher\ProcedureDescriptor;
use Lumen\JsonRpc\Doc\DocGenerator;
use Lumen\JsonRpc\Doc\OpenRpcGenerator;
use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class DescriptorIntegrationTest extends TestCase
{
    private function createConfig(array $overrides = []): Config
    {
        return new Config(array_merge([
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
        ], $overrides));
    }

    public function testDescriptorOverridesAutoDiscoveryForSameMethod(): void
    {
        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $registry = $server->getRegistry();
        $handlers = $registry->getHandlers();
        $this->assertArrayHasKey('system.health', $handlers);

        $registry->register('system.health', DescriptorFixtureHandler::class, 'health', [
            'description' => 'Overridden health check',
        ]);

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"system.health","id":1}'
        );
        $decoded = json_decode($result, true);

        $this->assertSame('descriptor-health', $decoded['result']['status']);
    }

    public function testDescriptorOnlyModeWithNoHandlerPaths(): void
    {
        $config = $this->createConfig([
            'handlers' => [
                'paths' => [],
                'namespace' => 'App\\Handlers\\',
            ],
        ]);

        $server = new JsonRpcServer($config);

        $registry = $server->getRegistry();
        $registry->register('math.add', DescriptorFixtureHandler::class, 'add');

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"math.add","params":{"a":3,"b":4},"id":1}'
        );
        $decoded = json_decode($result, true);

        $this->assertSame(7, $decoded['result']);
    }

    public function testMixedModeAutoDiscoveryAndDescriptors(): void
    {
        $config = $this->createConfig();
        $server = new JsonRpcServer($config);

        $registry = $server->getRegistry();
        $registry->register('math.add', DescriptorFixtureHandler::class, 'add');

        $result1 = $server->handleJson(
            '{"jsonrpc":"2.0","method":"math.add","params":{"a":2,"b":3},"id":1}'
        );
        $decoded1 = json_decode($result1, true);
        $this->assertSame(5, $decoded1['result']);

        $result2 = $server->handleJson(
            '{"jsonrpc":"2.0","method":"order.list","params":{},"id":2}'
        );
        $decoded2 = json_decode($result2, true);
        $this->assertArrayHasKey('orders', $decoded2['result']);
    }

    public function testDescriptorWithProtectedMethodAndAuth(): void
    {
        $config = $this->createConfig([
            'auth' => [
                'enabled' => true,
                'driver' => 'api_key',
                'protected_methods' => ['math.'],
                'api_key' => [
                    'header' => 'X-API-Key',
                    'keys' => [
                        'test-key' => ['user_id' => 'test-user', 'roles' => ['user']],
                    ],
                ],
            ],
        ]);

        $server = new JsonRpcServer($config);

        $registry = $server->getRegistry();
        $registry->register('math.add', DescriptorFixtureHandler::class, 'add');

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1}'
        );
        $decoded = json_decode($result, true);
        $this->assertSame(-32001, $decoded['error']['code']);
        $this->assertSame('Authentication required', $decoded['error']['message']);
    }

    public function testDescriptorMetadataUsedByDocGenerator(): void
    {
        $config = $this->createConfig([
            'handlers' => [
                'paths' => [],
                'namespace' => 'App\\Handlers\\',
            ],
        ]);

        $server = new JsonRpcServer($config);
        $registry = $server->getRegistry();
        $registry->register('math.add', DescriptorFixtureHandler::class, 'add', [
            'description' => 'Add two numbers together',
            'params' => [
                'a' => ['type' => 'int', 'description' => 'First number', 'required' => true],
                'b' => ['type' => 'int', 'description' => 'Second number', 'required' => true],
            ],
            'returnType' => 'int',
            'requiresAuth' => false,
        ]);

        $docGenerator = new DocGenerator($registry);
        $docs = $docGenerator->generate();

        $this->assertCount(1, $docs);
        $this->assertSame('math.add', $docs[0]->name);
        $this->assertSame('Add two numbers together', $docs[0]->description);
        $this->assertFalse($docs[0]->requiresAuth);
    }

    public function testDescriptorMetadataConsumedByOpenRpcGenerator(): void
    {
        $config = $this->createConfig([
            'handlers' => [
                'paths' => [],
                'namespace' => 'App\\Handlers\\',
            ],
        ]);

        $server = new JsonRpcServer($config);
        $registry = $server->getRegistry();
        $registry->register('math.add', DescriptorFixtureHandler::class, 'add', [
            'description' => 'Add two numbers',
            'params' => [
                'a' => ['type' => 'int', 'description' => 'First', 'required' => true],
                'b' => ['type' => 'int', 'description' => 'Second', 'required' => true],
            ],
            'returnType' => 'int',
            'returnDescription' => 'Sum of a and b',
            'requiresAuth' => true,
        ]);

        $docGenerator = new DocGenerator($registry);
        $docs = $docGenerator->generate();

        $openRpc = new OpenRpcGenerator();
        $output = $openRpc->generate($docs, 'Test API', '1.0.0');
        $data = json_decode($output, true);

        $method = $data['methods'][0];
        $this->assertSame('math.add', $method['name']);
        $this->assertSame('Add two numbers', $method['description']);
        $this->assertTrue($method['x-requiresAuth']);
        $this->assertCount(2, $method['params']);
        $this->assertTrue($method['params'][0]['required']);
        $this->assertSame('Sum of a and b', $method['result']['description']);
    }

    public function testDuplicateRegistrationOverwritesSilently(): void
    {
        $config = $this->createConfig([
            'handlers' => ['paths' => [], 'namespace' => 'App\\Handlers\\'],
        ]);
        $server = new JsonRpcServer($config);

        $registry = $server->getRegistry();
        $registry->register('test.method', DescriptorFixtureHandler::class, 'add');
        $registry->register('test.method', DescriptorFixtureHandler::class, 'health');

        $result = $server->handleJson(
            '{"jsonrpc":"2.0","method":"test.method","id":1}'
        );
        $decoded = json_decode($result, true);

        $this->assertSame('descriptor-health', $decoded['result']['status']);
    }
}

class DescriptorFixtureHandler
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function health(): array
    {
        return ['status' => 'descriptor-health'];
    }
}
