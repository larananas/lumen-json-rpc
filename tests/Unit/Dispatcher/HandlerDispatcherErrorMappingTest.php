<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Dispatcher;

use Lumen\JsonRpc\Dispatcher\HandlerDispatcher;
use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Dispatcher\MethodResolver;
use Lumen\JsonRpc\Dispatcher\ParameterBinder;
use Lumen\JsonRpc\Exception\InvalidParamsException;
use Lumen\JsonRpc\Exception\MethodNotFoundException;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class HandlerDispatcherErrorMappingTest extends TestCase
{
    private string $handlerPath;
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->handlerPath = realpath(__DIR__ . '/../../../examples/basic/handlers') ?: __DIR__ . '/../../../examples/basic/handlers';
        $this->fixturePath = realpath(__DIR__ . '/../../../tests/Fixtures') ?: __DIR__ . '/../../../tests/Fixtures';
    }

    private function createDispatcherWithoutRegistry(string $path, string $namespace, string $sep = '.'): HandlerDispatcher
    {
        $resolver = new MethodResolver([$path], $namespace, $sep);
        return new HandlerDispatcher($resolver, new ParameterBinder());
    }

    private function createContext(): RequestContext
    {
        return new RequestContext('test-cid', [], '127.0.0.1');
    }

    public function testMethodNotFoundPreservedOnDispatch(): void
    {
        $dispatcher = $this->createDispatcherWithoutRegistry($this->handlerPath, 'App\\Handlers\\');
        $request = new Request('nonexist.method', 1, null, true);

        $this->expectException(MethodNotFoundException::class);
        $dispatcher->dispatch($request, $this->createContext());
    }

    public function testNonExistentMethodOnValidHandlerThrowsMethodNotFound(): void
    {
        $dispatcher = $this->createDispatcherWithoutRegistry($this->fixturePath, 'Lumen\\JsonRpc\\Tests\\Fixtures\\');
        $request = new Request('handlernoconstructor.nonexistent', 1, null, true);

        $this->expectException(MethodNotFoundException::class);
        $dispatcher->dispatch($request, $this->createContext());
    }

    public function testIncompatibleHandlerConstructorThrowsMethodNotFound(): void
    {
        $dispatcher = $this->createDispatcherWithoutRegistry($this->fixturePath, 'Lumen\\JsonRpc\\Tests\\Fixtures\\');
        $request = new Request('handlerincompatible.testMethod', 1, null, true);

        $this->expectException(MethodNotFoundException::class);
        $dispatcher->dispatch($request, $this->createContext());
    }

    public function testWrongParamTypeReturnsInvalidParams(): void
    {
        $dispatcher = $this->createDispatcherWithoutRegistry($this->handlerPath, 'App\\Handlers\\');
        $request = new Request('user.get', 1, ['id' => 'not-an-int'], true);

        $this->expectException(InvalidParamsException::class);
        $dispatcher->dispatch($request, $this->createContext());
    }

    public function testMissingRequiredParamReturnsInvalidParams(): void
    {
        $dispatcher = $this->createDispatcherWithoutRegistry($this->handlerPath, 'App\\Handlers\\');
        $request = new Request('user.get', 1, [], true);

        $this->expectException(InvalidParamsException::class);
        $dispatcher->dispatch($request, $this->createContext());
    }

    public function testValidFixtureHandlerDispatchesCorrectly(): void
    {
        $dispatcher = $this->createDispatcherWithoutRegistry($this->fixturePath, 'Lumen\\JsonRpc\\Tests\\Fixtures\\');
        $request = new Request('handlernoconstructor.testMethod', 1, null, true);
        $result = $dispatcher->dispatch($request, $this->createContext());
        $this->assertEquals('no-constructor', $result);
    }

    public function testHandlerWithContextReceivesCorrectContext(): void
    {
        $dispatcher = $this->createDispatcherWithoutRegistry($this->fixturePath, 'Lumen\\JsonRpc\\Tests\\Fixtures\\');
        $context = new RequestContext('corr-123', ['X-Test' => 'yes'], '10.0.0.1');
        $request = new Request('handlerwithcontext.testMethod', 1, null, true);
        $result = $dispatcher->dispatch($request, $context);
        $this->assertEquals('with-context', $result);
    }

    public function testHandlerOptionalParamConstructor(): void
    {
        $dispatcher = $this->createDispatcherWithoutRegistry($this->fixturePath, 'Lumen\\JsonRpc\\Tests\\Fixtures\\');
        $request = new Request('handleroptionalparam.testMethod', 1, null, true);
        $result = $dispatcher->dispatch($request, $this->createContext());
        $this->assertEquals('optional-param', $result);
    }

    public function testValidHandlerMethodDispatchesCorrectly(): void
    {
        $dispatcher = $this->createDispatcherWithoutRegistry($this->handlerPath, 'App\\Handlers\\');
        $request = new Request('system.health', 1, null, true);
        $result = $dispatcher->dispatch($request, $this->createContext());
        $this->assertIsArray($result);
        $this->assertEquals('ok', $result['status']);
    }
}
