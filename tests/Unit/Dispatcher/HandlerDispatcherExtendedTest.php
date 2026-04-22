<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Dispatcher;

use Lumen\JsonRpc\Dispatcher\DefaultHandlerFactory;
use Lumen\JsonRpc\Dispatcher\HandlerDispatcher;
use Lumen\JsonRpc\Dispatcher\HandlerFactoryInterface;
use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Dispatcher\MethodResolver;
use Lumen\JsonRpc\Dispatcher\ParameterBinder;
use Lumen\JsonRpc\Exception\MethodNotFoundException;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Support\RequestContext;
use Lumen\JsonRpc\Tests\Fixtures\HandlerNoConstructor;
use PHPUnit\Framework\TestCase;

final class HandlerDispatcherExtendedTest extends TestCase
{
    private string $handlerPath;
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->handlerPath = realpath(__DIR__ . '/../../../examples/basic/handlers') ?: __DIR__ . '/../../../examples/basic/handlers';
        $this->fixturePath = realpath(__DIR__ . '/../../../tests/Fixtures') ?: __DIR__ . '/../../../tests/Fixtures';
    }

    private function context(): RequestContext
    {
        return new RequestContext('test-cid', [], '127.0.0.1');
    }

    public function testSetFactoryOverridesDefault(): void
    {
        $registry = new HandlerRegistry([$this->fixturePath], 'Lumen\\JsonRpc\\Tests\\Fixtures\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$this->fixturePath], 'Lumen\\JsonRpc\\Tests\\Fixtures\\', '.');

        $customFactory = new class implements HandlerFactoryInterface {
            public bool $called = false;
            public function create(string $className, RequestContext $context): object
            {
                $this->called = true;
                return new $className();
            }
        };

        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);
        $dispatcher->setFactory($customFactory);

        $request = new Request('handlernoconstructor.testMethod', 1, null, true);
        $result = $dispatcher->dispatch($request, $this->context());

        $this->assertTrue($customFactory->called);
        $this->assertEquals('no-constructor', $result);
    }

    public function testResolveMethodReturnsNullForUnknownMethod(): void
    {
        $resolver = new MethodResolver([$this->handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder());

        $resolution = $dispatcher->resolveMethod('nonexistent.method');
        $this->assertNull($resolution);
    }

    public function testResolveMethodWithRegistryReturnsResolution(): void
    {
        $registry = new HandlerRegistry([$this->handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$this->handlerPath], 'App\\Handlers\\', '.');

        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);
        $resolution = $dispatcher->resolveMethod('system.health');

        $this->assertNotNull($resolution);
        $this->assertEquals('health', $resolution->methodName);
    }

    public function testResolveMethodWithRegistryDescriptorReturnsResolution(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new \Lumen\JsonRpc\Dispatcher\ProcedureDescriptor(
                method: 'test.custom',
                handlerClass: HandlerNoConstructor::class,
                handlerMethod: 'testMethod',
                metadata: [],
            ),
        );

        $resolver = new MethodResolver([], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $resolution = $dispatcher->resolveMethod('test.custom');
        $this->assertNotNull($resolution);
        $this->assertEquals('testMethod', $resolution->methodName);
    }

    public function testResolveMethodReturnsNullWhenRegistryMissingMethod(): void
    {
        $registry = new HandlerRegistry([$this->handlerPath], 'App\\Handlers\\', '.');
        $registry->discover();
        $resolver = new MethodResolver([$this->handlerPath], 'App\\Handlers\\', '.');

        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $resolution = $dispatcher->resolveMethod('nonexistent.method');
        $this->assertNull($resolution);
    }

    public function testDispatchDescriptorWithRegistry(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new \Lumen\JsonRpc\Dispatcher\ProcedureDescriptor(
                method: 'test.dispatched',
                handlerClass: HandlerNoConstructor::class,
                handlerMethod: 'testMethod',
                metadata: [],
            ),
        );

        $resolver = new MethodResolver([], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $request = new Request('test.dispatched', 1, null, true);
        $result = $dispatcher->dispatch($request, $this->context());
        $this->assertEquals('no-constructor', $result);
    }

    public function testDispatchDescriptorWithMissingClassThrowsException(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new \Lumen\JsonRpc\Dispatcher\ProcedureDescriptor(
                method: 'test.missing',
                handlerClass: 'NonExistentClass',
                handlerMethod: 'someMethod',
                metadata: [],
            ),
        );

        $resolver = new MethodResolver([], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $request = new Request('test.missing', 1, null, true);
        $this->expectException(MethodNotFoundException::class);
        $dispatcher->dispatch($request, $this->context());
    }

    public function testDispatchWithoutRegistrySucceedsForResolvedMethod(): void
    {
        $resolver = new MethodResolver([$this->handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder());

        $request = new Request('system.health', 1, null, true);
        $result = $dispatcher->dispatch($request, $this->context());
        $this->assertIsArray($result);
        $this->assertEquals('ok', $result['status']);
    }

    public function testDispatchStaticMethodThrowsException(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new \Lumen\JsonRpc\Dispatcher\ProcedureDescriptor(
                method: 'test.staticmethod',
                handlerClass: HandlerWithStatic::class,
                handlerMethod: 'staticMethod',
                metadata: [],
            ),
        );

        $resolver = new MethodResolver([], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $request = new Request('test.staticmethod', 1, null, true);
        $this->expectException(MethodNotFoundException::class);
        $this->expectExceptionMessage('Static methods are not callable');
        $dispatcher->dispatch($request, $this->context());
    }

    public function testDispatchMagicMethodThrowsException(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new \Lumen\JsonRpc\Dispatcher\ProcedureDescriptor(
                method: 'test.__toString',
                handlerClass: HandlerWithMagic::class,
                handlerMethod: '__toString',
                metadata: [],
            ),
        );

        $resolver = new MethodResolver([], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $request = new Request('test.__toString', 1, null, true);
        $this->expectException(MethodNotFoundException::class);
        $this->expectExceptionMessage('Magic methods are not callable');
        $dispatcher->dispatch($request, $this->context());
    }

    public function testDispatchPrivateMethodThrowsException(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new \Lumen\JsonRpc\Dispatcher\ProcedureDescriptor(
                method: 'test.privateMethod',
                handlerClass: HandlerWithPrivate::class,
                handlerMethod: 'privateMethod',
                metadata: [],
            ),
        );

        $resolver = new MethodResolver([], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $request = new Request('test.privateMethod', 1, null, true);
        $this->expectException(MethodNotFoundException::class);
        $this->expectExceptionMessage('Method not accessible');
        $dispatcher->dispatch($request, $this->context());
    }

    public function testDispatchWithNonexistentMethodOnClassThrowsException(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $registry->registerDescriptor(
            new \Lumen\JsonRpc\Dispatcher\ProcedureDescriptor(
                method: 'test.nonexistent',
                handlerClass: HandlerNoConstructor::class,
                handlerMethod: 'nonexistentMethod',
                metadata: [],
            ),
        );

        $resolver = new MethodResolver([], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $request = new Request('test.nonexistent', 1, null, true);
        $this->expectException(MethodNotFoundException::class);
        $this->expectExceptionMessage('Method not found');
        $dispatcher->dispatch($request, $this->context());
    }

    public function testResolveMethodReturnsNullForMethodNotInRegistry(): void
    {
        $registry = new HandlerRegistry([], 'App\\Handlers\\', '.');
        $resolver = new MethodResolver([$this->handlerPath], 'App\\Handlers\\', '.');
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder(), $registry);

        $resolution = $dispatcher->resolveMethod('system.health');
        $this->assertNull($resolution);
    }
}

class HandlerWithStatic
{
    public static function staticMethod(): string
    {
        return 'static';
    }
}

class HandlerWithMagic
{
    public function __toString(): string
    {
        return 'magic';
    }
}

class HandlerWithPrivate
{
    private function privateMethod(): string
    {
        return 'private';
    }
}
