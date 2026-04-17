<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Dispatcher;

use Lumen\JsonRpc\Dispatcher\DefaultHandlerFactory;
use Lumen\JsonRpc\Dispatcher\HandlerDispatcher;
use Lumen\JsonRpc\Dispatcher\MethodResolver;
use Lumen\JsonRpc\Dispatcher\ParameterBinder;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Support\RequestContext;
use Lumen\JsonRpc\Tests\Fixtures\HandlerNoConstructor;
use Lumen\JsonRpc\Tests\Fixtures\HandlerWithContext;
use Lumen\JsonRpc\Tests\Fixtures\HandlerIncompatible;
use Lumen\JsonRpc\Tests\Fixtures\HandlerOptionalParam;
use PHPUnit\Framework\TestCase;

final class FactoryDeduplicationTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = realpath(__DIR__ . '/../../Fixtures') ?: __DIR__ . '/../../Fixtures';
    }

    private function createDispatcher(): HandlerDispatcher
    {
        $resolver = new MethodResolver(
            [$this->fixturesPath],
            'Lumen\\JsonRpc\\Tests\\Fixtures\\',
            '.'
        );
        return new HandlerDispatcher($resolver, new ParameterBinder());
    }

    private function createContext(): RequestContext
    {
        return new RequestContext(
            correlationId: 'dedup-test',
            headers: [],
            clientIp: '127.0.0.1',
        );
    }

    public function testDispatcherWithoutFactoryUsesDefaultHandlerFactory(): void
    {
        $dispatcher = $this->createDispatcher();
        $context = $this->createContext();

        $request = new Request(
            method: 'handlernoconstructor.testMethod',
            id: 1,
            params: [],
            idProvided: true,
        );

        $result = $dispatcher->dispatch($request, $context);
        $this->assertEquals('no-constructor', $result);
    }

    public function testDispatcherWithoutFactoryInjectsContext(): void
    {
        $dispatcher = $this->createDispatcher();
        $context = new RequestContext(
            correlationId: 'dedup-ctx-test',
            headers: ['X-Test' => 'yes'],
            clientIp: '10.0.0.1',
        );

        $request = new Request(
            method: 'handlerwithcontext.getContext',
            id: 1,
            params: [],
            idProvided: true,
        );

        $result = $dispatcher->dispatch($request, $context);
        $this->assertInstanceOf(RequestContext::class, $result);
        $this->assertEquals('dedup-ctx-test', $result->correlationId);
    }

    public function testDispatcherWithoutFactoryHandlesOptionalParam(): void
    {
        $dispatcher = $this->createDispatcher();
        $context = $this->createContext();

        $request = new Request(
            method: 'handleroptionalparam.testMethod',
            id: 1,
            params: [],
            idProvided: true,
        );

        $result = $dispatcher->dispatch($request, $context);
        $this->assertEquals('optional-param', $result);
    }

    public function testDispatcherWithoutFactoryRejectsIncompatible(): void
    {
        $dispatcher = $this->createDispatcher();
        $context = $this->createContext();

        $request = new Request(
            method: 'handlerincompatible.testMethod',
            id: 1,
            params: [],
            idProvided: true,
        );

        $this->expectException(\Lumen\JsonRpc\Exception\MethodNotFoundException::class);
        $this->expectExceptionMessage('constructor is not compatible');
        $dispatcher->dispatch($request, $context);
    }

    public function testDispatcherWithExplicitFactoryOverridesDefault(): void
    {
        $resolver = new MethodResolver(
            [$this->fixturesPath],
            'Lumen\\JsonRpc\\Tests\\Fixtures\\',
            '.'
        );
        $dispatcher = new HandlerDispatcher($resolver, new ParameterBinder());
        $dispatcher->setFactory(new DefaultHandlerFactory());

        $context = $this->createContext();
        $request = new Request(
            method: 'handlernoconstructor.testMethod',
            id: 1,
            params: [],
            idProvided: true,
        );

        $result = $dispatcher->dispatch($request, $context);
        $this->assertEquals('no-constructor', $result);
    }
}
