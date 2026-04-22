<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Doc;

use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Dispatcher\ProcedureDescriptor;
use Lumen\JsonRpc\Doc\DocGenerator;
use PHPUnit\Framework\TestCase;

final class DocGeneratorExtendedTest extends TestCase
{
    private string $handlerPath;
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->handlerPath = realpath(__DIR__ . '/../../../examples/basic/handlers') ?: __DIR__ . '/../../../examples/basic/handlers';
        $this->fixturePath = realpath(__DIR__ . '/../../../tests/Fixtures') ?: __DIR__ . '/../../../tests/Fixtures';
    }

    public function testGenerateWithDescriptorMetadataErrors(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new ProcedureDescriptor(
                method: 'test.method',
                handlerClass: 'App\\Handlers\\System',
                handlerMethod: 'health',
                metadata: [
                    'description' => 'A test method',
                    'errors' => [
                        ['code' => -32602, 'message' => 'Invalid params', 'description' => 'Bad input'],
                        ['code' => -32000, 'description' => 'Server error'],
                    ],
                ],
            ),
        );

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertCount(1, $docs);
        $errors = $docs[0]->errors;
        $this->assertCount(2, $errors);
        $this->assertEquals('-32602', $errors[0]['code']);
        $this->assertEquals('Server error', $errors[1]['description']);
    }

    public function testGenerateWithDescriptorMetadataExampleRequest(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new ProcedureDescriptor(
                method: 'test.method',
                handlerClass: 'App\\Handlers\\System',
                handlerMethod: 'health',
                metadata: [
                    'description' => 'Test',
                    'exampleRequest' => '{"jsonrpc":"2.0","method":"test.method","params":{}}',
                    'exampleResponse' => '{"jsonrpc":"2.0","result":"ok"}',
                ],
            ),
        );

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertNotNull($docs[0]->exampleRequest);
        $this->assertNotNull($docs[0]->exampleResponse);
        $this->assertStringContainsString('test.method', $docs[0]->exampleRequest);
    }

    public function testGenerateWithDescriptorMetadataRequiresAuth(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new ProcedureDescriptor(
                method: 'test.secure',
                handlerClass: 'App\\Handlers\\System',
                handlerMethod: 'health',
                metadata: [
                    'description' => 'Secure method',
                    'requiresAuth' => true,
                ],
            ),
        );

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertTrue($docs[0]->requiresAuth);
    }

    public function testGenerateWithDescriptorReturnDescription(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new ProcedureDescriptor(
                method: 'test.method',
                handlerClass: 'App\\Handlers\\System',
                handlerMethod: 'health',
                metadata: [
                    'description' => 'A method',
                    'returnType' => 'array',
                    'returnDescription' => 'The result array',
                ],
            ),
        );

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertEquals('The result array', $docs[0]->returnDescription);
    }

    public function testGenerateWithDescriptorSchemaMetadata(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new ProcedureDescriptor(
                method: 'test.method',
                handlerClass: 'App\\Handlers\\System',
                handlerMethod: 'health',
                metadata: [
                    'description' => 'Schema test',
                    'resultSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'count' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ),
        );

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertNotNull($docs[0]->resultSchema);
        $this->assertEquals('object', $docs[0]->resultSchema['type']);
    }

    public function testGenerateWithDescriptorParamsWithSchema(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new ProcedureDescriptor(
                method: 'test.method',
                handlerClass: 'App\\Handlers\\System',
                handlerMethod: 'health',
                metadata: [
                    'description' => 'Params with schema',
                    'params' => [
                        'id' => [
                            'type' => 'int',
                            'description' => 'ID',
                            'required' => true,
                            'default' => null,
                            'schema' => ['type' => 'integer', 'minimum' => 1],
                        ],
                    ],
                ],
            ),
        );

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertArrayHasKey('id', $docs[0]->params);
        $param = $docs[0]->params['id'];
        $this->assertEquals('int', $param['type']);
        $this->assertTrue($param['required']);
        $this->assertArrayHasKey('schema', $param);
        $this->assertEquals(1, $param['schema']['minimum']);
    }

    public function testGenerateWithDescriptorInvalidErrorsSkipped(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new ProcedureDescriptor(
                method: 'test.method',
                handlerClass: 'App\\Handlers\\System',
                handlerMethod: 'health',
                metadata: [
                    'description' => 'Test',
                    'errors' => [
                        ['description' => ''],
                        'not-an-array',
                        ['code' => -32600, 'description' => 'Valid error'],
                    ],
                ],
            ),
        );

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertCount(1, $docs[0]->errors);
        $this->assertEquals('Valid error', $docs[0]->errors[0]['description']);
    }

    public function testGenerateWithNonStringDescriptionUsesDefault(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new ProcedureDescriptor(
                method: 'test.method',
                handlerClass: 'CompletelyNonExistentClassXYZ',
                handlerMethod: 'someMethod',
                metadata: [
                    'description' => 123,
                ],
            ),
        );

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertSame('', $docs[0]->description);
    }

    public function testGenerateWithInvalidReturnTypeUsesDefault(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new ProcedureDescriptor(
                method: 'test.method',
                handlerClass: 'CompletelyNonExistentClassXYZ',
                handlerMethod: 'someMethod',
                metadata: [
                    'returnType' => 42,
                ],
            ),
        );

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertNull($docs[0]->returnType);
    }

    public function testGenerateWithRequiresAuthNonBool(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new ProcedureDescriptor(
                method: 'test.method',
                handlerClass: 'App\\Handlers\\System',
                handlerMethod: 'health',
                metadata: [
                    'requiresAuth' => 'yes',
                ],
            ),
        );

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertFalse($docs[0]->requiresAuth);
    }

    public function testGenerateFromHandlerWithDocblock(): void
    {
        $registry = new HandlerRegistry([$this->handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $health = array_values(array_filter($docs, fn($d) => $d->name === 'system.health'))[0];
        $this->assertNotEmpty($health->description);
        $this->assertNotEmpty($health->returnType);
    }

    public function testGenerateFromClassNotFound(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new ProcedureDescriptor(
                method: 'test.missing',
                handlerClass: 'NonExistentClass',
                handlerMethod: 'someMethod',
                metadata: [
                    'description' => 'Missing class',
                ],
            ),
        );

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertCount(1, $docs);
        $this->assertEquals('Missing class', $docs[0]->description);
    }

    public function testGenerateWithNonArrayParamsMetadata(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new ProcedureDescriptor(
                method: 'test.method',
                handlerClass: 'App\\Handlers\\System',
                handlerMethod: 'health',
                metadata: [
                    'params' => 'not-array',
                ],
            ),
        );

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertEmpty($docs[0]->params);
    }

    public function testGenerateWithMixedParams(): void
    {
        $registry = new HandlerRegistry([$this->fixturePath], 'Lumen\\JsonRpc\\Tests\\Fixtures\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $names = array_map(fn($d) => $d->name, $docs);
        $this->assertNotEmpty($names);
    }
}
