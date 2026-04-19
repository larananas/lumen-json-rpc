<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Dispatcher;

use Lumen\JsonRpc\Dispatcher\HandlerDispatcher;
use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Dispatcher\MethodResolver;
use Lumen\JsonRpc\Dispatcher\ParameterBinder;
use Lumen\JsonRpc\Dispatcher\ProcedureDescriptor;
use Lumen\JsonRpc\Exception\MethodNotFoundException;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class ProcedureDescriptorTest extends TestCase
{
    private HandlerRegistry $registry;
    private HandlerDispatcher $dispatcher;
    private RequestContext $context;

    protected function setUp(): void
    {
        $this->context = new RequestContext(
            correlationId: 'test-1',
            headers: [],
            clientIp: '127.0.0.1',
        );

        $this->registry = new HandlerRegistry([], 'App\\Handlers\\', '.');

        $resolver = new MethodResolver([], 'App\\Handlers\\', '.');
        $this->dispatcher = new HandlerDispatcher(
            $resolver,
            new ParameterBinder(),
            $this->registry,
        );
    }

    public function testRegisterAddsMethod(): void
    {
        $this->registry->register(
            'math.add',
            FixtureMathHandler::class,
            'add',
            ['description' => 'Add two numbers'],
        );

        $this->assertTrue($this->registry->hasMethod('math.add'));
    }

    public function testRegisterDescriptor(): void
    {
        $descriptor = new ProcedureDescriptor(
            method: 'math.multiply',
            handlerClass: FixtureMathHandler::class,
            handlerMethod: 'multiply',
            metadata: ['description' => 'Multiply two numbers'],
        );

        $this->registry->registerDescriptor($descriptor);

        $this->assertTrue($this->registry->hasMethod('math.multiply'));

        $retrieved = $this->registry->getDescriptor('math.multiply');
        $this->assertNotNull($retrieved);
        $this->assertSame('math.multiply', $retrieved->method);
        $this->assertSame(FixtureMathHandler::class, $retrieved->handlerClass);
        $this->assertSame('multiply', $retrieved->handlerMethod);
        $this->assertSame(['description' => 'Multiply two numbers'], $retrieved->metadata);
    }

    public function testRegisterDescriptors(): void
    {
        $descriptors = [
            new ProcedureDescriptor('math.add', FixtureMathHandler::class, 'add'),
            new ProcedureDescriptor('math.multiply', FixtureMathHandler::class, 'multiply'),
        ];

        $this->registry->registerDescriptors($descriptors);

        $this->assertTrue($this->registry->hasMethod('math.add'));
        $this->assertTrue($this->registry->hasMethod('math.multiply'));
    }

    public function testDispatchDescriptorMethod(): void
    {
        $this->registry->register('math.add', FixtureMathHandler::class, 'add');

        $request = Request::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'math.add',
            'params' => ['a' => 3, 'b' => 4],
            'id' => 1,
        ]);

        $result = $this->dispatcher->dispatch($request, $this->context);
        $this->assertSame(7, $result);
    }

    public function testDispatchDescriptorMethodNotFound(): void
    {
        $request = Request::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'nonexistent.method',
            'params' => [],
            'id' => 1,
        ]);

        $this->expectException(MethodNotFoundException::class);
        $this->dispatcher->dispatch($request, $this->context);
    }

    public function testGetDescriptorReturnsNullForUnknownMethod(): void
    {
        $this->assertNull($this->registry->getDescriptor('unknown'));
    }

    public function testGetDescriptorsReturnsAll(): void
    {
        $this->registry->register('a.b', FixtureMathHandler::class, 'add');
        $this->registry->register('c.d', FixtureMathHandler::class, 'multiply');

        $descriptors = $this->registry->getDescriptors();
        $this->assertCount(2, $descriptors);
    }

    public function testDescriptorToArray(): void
    {
        $descriptor = new ProcedureDescriptor(
            method: 'test.method',
            handlerClass: 'SomeHandler',
            handlerMethod: 'someMethod',
            metadata: ['key' => 'value'],
        );

        $arr = $descriptor->toArray();
        $this->assertSame('test.method', $arr['method']);
        $this->assertSame('SomeHandler', $arr['handlerClass']);
        $this->assertSame('someMethod', $arr['handlerMethod']);
        $this->assertSame(['key' => 'value'], $arr['metadata']);
    }

    public function testResolvedMethodFromDescriptor(): void
    {
        $this->registry->register('math.add', FixtureMathHandler::class, 'add');

        $resolution = $this->dispatcher->resolveMethod('math.add');
        $this->assertNotNull($resolution);
        $this->assertSame(FixtureMathHandler::class, $resolution->className);
        $this->assertSame('add', $resolution->methodName);
    }

    public function testDispatchWithNonexistentHandlerClass(): void
    {
        $this->registry->register('math.add', 'NonexistentClass', 'add');

        $request = Request::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'math.add',
            'params' => [],
            'id' => 1,
        ]);

        $this->expectException(MethodNotFoundException::class);
        $this->dispatcher->dispatch($request, $this->context);
    }
}

class FixtureMathHandler
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function multiply(int $a, int $b): int
    {
        return $a * $b;
    }
}
