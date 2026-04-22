<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Doc;

use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Doc\DocGenerator;
use Lumen\JsonRpc\Doc\MethodDoc;
use PHPUnit\Framework\TestCase;

final class DocGeneratorContractTest extends TestCase
{
    public function testGenerateIsSideEffectFree(): void
    {
        $registry = new HandlerRegistry(
            [__DIR__ . '/../../../examples/basic/handlers'],
            'App\\Handlers\\',
            '.',
        );
        $handlersBefore = $registry->discover();
        $countBefore = count($handlersBefore);

        $generator = new DocGenerator($registry);
        $docs1 = $generator->generate();
        $docs2 = $generator->generate();

        $handlersAfterFirst = $registry->getHandlers();
        $handlersAfterSecond = $registry->getHandlers();

        $this->assertCount($countBefore, $handlersAfterFirst);
        $this->assertCount($countBefore, $handlersAfterSecond);
        $this->assertEquals($handlersBefore, $handlersAfterFirst);

        $this->assertEquals(
            array_map(fn(MethodDoc $d) => $d->name, $docs1),
            array_map(fn(MethodDoc $d) => $d->name, $docs2),
        );
    }

    public function testGenerateReturnsEmptyArrayForEmptyRegistry(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->discover();

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertIsArray($docs);
        $this->assertEmpty($docs);
    }

    public function testGenerateWithDescriptorMetadata(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new \Lumen\JsonRpc\Dispatcher\ProcedureDescriptor(
                method: 'test.method',
                handlerClass: 'App\\Handlers\\System',
                handlerMethod: 'health',
                metadata: [
                    'description' => 'Custom description',
                    'params' => [
                        'id' => ['type' => 'int', 'description' => 'The ID', 'required' => true, 'default' => null],
                    ],
                    'returnType' => 'array',
                    'returnDescription' => 'Result object',
                    'resultSchema' => [
                        'type' => 'object',
                        'required' => ['total'],
                        'properties' => [
                            'total' => ['type' => 'integer'],
                        ],
                    ],
                    'requiresAuth' => true,
                ],
            ),
        );

        $generator = new DocGenerator($registry);
        $docs = $generator->generate();

        $this->assertCount(1, $docs);
        $this->assertEquals('test.method', $docs[0]->name);
        $this->assertEquals('Custom description', $docs[0]->description);
        $this->assertTrue($docs[0]->requiresAuth);
        $this->assertEquals('array', $docs[0]->returnType);
        $this->assertEquals('Result object', $docs[0]->returnDescription);
        $this->assertArrayHasKey('id', $docs[0]->params);
        $this->assertSame('object', $docs[0]->resultSchema['type']);
        $this->assertSame('integer', $docs[0]->resultSchema['properties']['total']['type']);
    }
}
