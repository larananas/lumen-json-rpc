<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Dispatcher\ProcedureDescriptor;
use Lumen\JsonRpc\Doc\DocGenerator;
use Lumen\JsonRpc\Doc\OpenRpcGenerator;
use Lumen\JsonRpc\Server\JsonRpcServer;
use PHPUnit\Framework\TestCase;

final class OpenRpcDescriptorIntegrationTest extends TestCase
{
    private JsonRpcServer $server;

    protected function setUp(): void
    {
        $config = new Config([
            'handlers' => [
                'paths' => [__DIR__ . '/../../examples/basic/handlers'],
                'namespace' => 'App\\Handlers\\',
            ],
            'logging' => ['enabled' => false],
            'health' => ['enabled' => false],
            'auth' => ['enabled' => false],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
        ]);

        $this->server = new JsonRpcServer($config);
    }

    public function testAutoDiscoveredMethodsAppearInOpenRpc(): void
    {
        $docGenerator = new DocGenerator($this->server->getRegistry());
        $docs = $docGenerator->generate();
        $openRpc = new OpenRpcGenerator();
        $json = $openRpc->generate($docs, 'Test API', '2.0.0');
        $spec = json_decode($json, true);

        $this->assertSame('1.3.2', $spec['openrpc']);
        $this->assertNotEmpty($spec['methods']);

        $methodNames = array_column($spec['methods'], 'name');
        $this->assertContains('system.health', $methodNames);
    }

    public function testAutoDiscoveredDocblockResultSchemaFlowsIntoOpenRpc(): void
    {
        $docs = (new DocGenerator($this->server->getRegistry()))->generate();
        $spec = json_decode((new OpenRpcGenerator())->generate($docs, 'Test API', '2.0.0'), true);

        $healthMethod = null;
        foreach ($spec['methods'] as $method) {
            if ($method['name'] === 'system.health') {
                $healthMethod = $method;
                break;
            }
        }

        $this->assertNotNull($healthMethod);
        $this->assertSame('object', $healthMethod['result']['schema']['type']);
        $this->assertFalse($healthMethod['result']['schema']['additionalProperties']);
        $this->assertSame('string', $healthMethod['result']['schema']['properties']['status']['type']);
        $this->assertSame('string', $healthMethod['result']['schema']['properties']['timestamp']['type']);
    }

    public function testDescriptorMethodsAppearInOpenRpc(): void
    {
        $this->server->getRegistry()->registerDescriptor(
            new ProcedureDescriptor(
                method: 'math.add',
                handlerClass: 'App\Handlers\System',
                handlerMethod: 'health',
                metadata: [
                    'description' => 'Add two numbers',
                    'params' => [
                        'a' => ['type' => 'int', 'description' => 'First number', 'required' => true, 'default' => null],
                        'b' => ['type' => 'int', 'description' => 'Second number', 'required' => true, 'default' => null],
                    ],
                    'returnType' => 'int',
                    'returnDescription' => 'Sum of a and b',
                    'resultSchema' => [
                        'type' => 'object',
                        'required' => ['sum'],
                        'properties' => [
                            'sum' => ['type' => 'integer'],
                            'traceId' => ['type' => 'string'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'requiresAuth' => false,
                    'errors' => [
                        ['code' => -32602, 'description' => 'Invalid parameters'],
                    ],
                ],
            ),
        );

        $docGenerator = new DocGenerator($this->server->getRegistry());
        $docs = $docGenerator->generate();
        $openRpc = new OpenRpcGenerator();
        $json = $openRpc->generate($docs, 'Test API', '1.0.0');
        $spec = json_decode($json, true);

        $mathMethod = null;
        foreach ($spec['methods'] as $method) {
            if ($method['name'] === 'math.add') {
                $mathMethod = $method;
                break;
            }
        }

        $this->assertNotNull($mathMethod, 'Descriptor method must appear in OpenRPC output');
        $this->assertSame('Add two numbers', $mathMethod['description']);
        $this->assertCount(2, $mathMethod['params']);
        $this->assertSame('a', $mathMethod['params'][0]['name']);
        $this->assertSame('integer', $mathMethod['params'][0]['schema']['type']);
        $this->assertTrue($mathMethod['params'][0]['required']);
        $this->assertSame('b', $mathMethod['params'][1]['name']);
        $this->assertArrayHasKey('result', $mathMethod);
        $this->assertSame('object', $mathMethod['result']['schema']['type']);
        $this->assertSame('integer', $mathMethod['result']['schema']['properties']['sum']['type']);
        $this->assertFalse($mathMethod['result']['schema']['additionalProperties']);
        $this->assertArrayHasKey('errors', $mathMethod);
        $this->assertSame(-32602, $mathMethod['errors'][0]['code']);
        $this->assertSame('Invalid parameters', $mathMethod['errors'][0]['message']);
    }

    public function testOpenRpcSpecVersionIsCorrect(): void
    {
        $docGenerator = new DocGenerator($this->server->getRegistry());
        $docs = $docGenerator->generate();
        $openRpc = new OpenRpcGenerator();
        $json = $openRpc->generate($docs);
        $spec = json_decode($json, true);

        $this->assertSame('1.3.2', $spec['openrpc']);
    }

    public function testOpenRpcOmitsServersBlockWhenNoServerUrlProvided(): void
    {
        $docGenerator = new DocGenerator($this->server->getRegistry());
        $docs = $docGenerator->generate();
        $openRpc = new OpenRpcGenerator();
        $json = $openRpc->generate($docs, 'Test API', '1.0.0');
        $spec = json_decode($json, true);

        $this->assertArrayNotHasKey('servers', $spec);
    }

    public function testOpenRpcInfoBlockHasRequiredFields(): void
    {
        $docGenerator = new DocGenerator($this->server->getRegistry());
        $docs = $docGenerator->generate();
        $openRpc = new OpenRpcGenerator();
        $json = $openRpc->generate($docs, 'Custom Title', '3.0.0', 'Custom description', 'https://api.example.test/rpc');
        $spec = json_decode($json, true);

        $this->assertSame('Custom Title', $spec['info']['title']);
        $this->assertSame('3.0.0', $spec['info']['version']);
        $this->assertSame('Custom description', $spec['info']['description']);
        $this->assertSame('Custom Title', $spec['servers'][0]['name']);
        $this->assertSame('https://api.example.test/rpc', $spec['servers'][0]['url']);
    }

    public function testMixedAutoDiscoveryAndDescriptors(): void
    {
        $this->server->getRegistry()->registerDescriptor(
            new ProcedureDescriptor(
                method: 'custom.method',
                handlerClass: 'App\Handlers\System',
                handlerMethod: 'health',
                metadata: ['description' => 'Custom descriptor method'],
            ),
        );

        $docGenerator = new DocGenerator($this->server->getRegistry());
        $docs = $docGenerator->generate();
        $openRpc = new OpenRpcGenerator();
        $json = $openRpc->generate($docs);
        $spec = json_decode($json, true);

        $methodNames = array_column($spec['methods'], 'name');
        $this->assertContains('system.health', $methodNames);
        $this->assertContains('custom.method', $methodNames);
    }

    public function testEachMethodHasRequiredOpenRpcFields(): void
    {
        $docGenerator = new DocGenerator($this->server->getRegistry());
        $docs = $docGenerator->generate();
        $openRpc = new OpenRpcGenerator();
        $json = $openRpc->generate($docs);
        $spec = json_decode($json, true);

        foreach ($spec['methods'] as $method) {
            $this->assertArrayHasKey('name', $method, 'Every method must have a name');
            $this->assertIsString($method['name']);
            $this->assertArrayHasKey('result', $method, 'Every method must have a result descriptor');
            $this->assertArrayHasKey('name', $method['result']);
            $this->assertArrayHasKey('schema', $method['result']);
        }
    }

    public function testSchemaProviderMetadataFlowsIntoOpenRpc(): void
    {
        $config = new Config([
            'handlers' => [
                'paths' => [realpath(__DIR__ . '/../Fixtures') ?: __DIR__ . '/../Fixtures'],
                'namespace' => 'Lumen\\JsonRpc\\Tests\\Fixtures\\',
            ],
            'logging' => ['enabled' => false],
            'health' => ['enabled' => false],
            'auth' => ['enabled' => false],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
        ]);

        $server = new JsonRpcServer($config);
        $docs = (new DocGenerator($server->getRegistry()))->generate();
        $spec = json_decode((new OpenRpcGenerator())->generate($docs, 'Test API', '1.0.0'), true);

        $method = null;
        foreach ($spec['methods'] as $candidate) {
            if ($candidate['name'] === 'validatedhandler.create') {
                $method = $candidate;
                break;
            }
        }

        $this->assertNotNull($method);
        $this->assertSame('by-name', $method['paramStructure']);
        $this->assertFalse($method['x-jsonrpc-requestSchema']['additionalProperties']);
        $this->assertSame('string', $method['params'][0]['schema']['type']);
        $this->assertSame('array', $method['params'][1]['schema']['type']);
        $this->assertSame(1, $method['params'][1]['schema']['minItems']);
    }
}
