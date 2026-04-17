<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Dispatcher;

use Lumen\JsonRpc\Dispatcher\HandlerDispatcher;
use Lumen\JsonRpc\Dispatcher\MethodResolver;
use Lumen\JsonRpc\Dispatcher\ParameterBinder;
use Lumen\JsonRpc\Exception\MethodNotFoundException;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests for handler instantiation with various constructor signatures
 */
final class HandlerInstantiationTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = realpath(__DIR__ . '/../../Fixtures') ?: __DIR__ . '/../../Fixtures';
    }

    public function testHandlerWithRequestContextConstructor(): void
    {
        $resolver = new MethodResolver(
            [$this->fixturesPath],
            'Lumen\\JsonRpc\\Tests\\Fixtures\\',
            '.'
        );

        $dispatcher = new HandlerDispatcher(
            $resolver,
            new ParameterBinder()
        );

        $context = new RequestContext(
            correlationId: 'test-123',
            headers: [],
            clientIp: '127.0.0.1',
        );

        $request = new Request(
            method: 'handlerwithcontext.testMethod',
            id: 1,
            params: [],
            idProvided: true,
        );

        $result = $dispatcher->dispatch($request, $context);
        $this->assertEquals('with-context', $result);
    }

    public function testHandlerNoConstructor(): void
    {
        $resolver = new MethodResolver(
            [$this->fixturesPath],
            'Lumen\\JsonRpc\\Tests\\Fixtures\\',
            '.'
        );

        $dispatcher = new HandlerDispatcher(
            $resolver,
            new ParameterBinder()
        );

        $context = new RequestContext(
            correlationId: 'test-123',
            headers: [],
            clientIp: '127.0.0.1',
        );

        $request = new Request(
            method: 'handlernoconstructor.testMethod',
            id: 1,
            params: [],
            idProvided: true,
        );

        $result = $dispatcher->dispatch($request, $context);
        $this->assertEquals('no-constructor', $result);
    }

    public function testHandlerWithOptionalParam(): void
    {
        $resolver = new MethodResolver(
            [$this->fixturesPath],
            'Lumen\\JsonRpc\\Tests\\Fixtures\\',
            '.'
        );

        $dispatcher = new HandlerDispatcher(
            $resolver,
            new ParameterBinder()
        );

        $context = new RequestContext(
            correlationId: 'test-123',
            headers: [],
            clientIp: '127.0.0.1',
        );

        $request = new Request(
            method: 'handleroptionalparam.testMethod',
            id: 1,
            params: [],
            idProvided: true,
        );

        $result = $dispatcher->dispatch($request, $context);
        $this->assertEquals('optional-param', $result);
    }

    public function testHandlerIncompatibleConstructorThrowsMethodNotFound(): void
    {
        $resolver = new MethodResolver(
            [$this->fixturesPath],
            'Lumen\\JsonRpc\\Tests\\Fixtures\\',
            '.'
        );

        $dispatcher = new HandlerDispatcher(
            $resolver,
            new ParameterBinder()
        );

        $context = new RequestContext(
            correlationId: 'test-123',
            headers: [],
            clientIp: '127.0.0.1',
        );

        $request = new Request(
            method: 'handlerincompatible.testMethod',
            id: 1,
            params: [],
            idProvided: true,
        );

        // Should throw MethodNotFoundException, not InternalErrorException
        $this->expectException(MethodNotFoundException::class);
        $this->expectExceptionMessage('constructor is not compatible');
        $dispatcher->dispatch($request, $context);
    }

    public function testHandlerWithContextReceivesCorrectContext(): void
    {
        $resolver = new MethodResolver(
            [$this->fixturesPath],
            'Lumen\\JsonRpc\\Tests\\Fixtures\\',
            '.'
        );

        $dispatcher = new HandlerDispatcher(
            $resolver,
            new ParameterBinder()
        );

        $context = new RequestContext(
            correlationId: 'specific-correlation-id',
            headers: ['X-Test' => 'value'],
            clientIp: '192.168.1.1',
        );

        $request = new Request(
            method: 'handlerwithcontext.getContext',
            id: 1,
            params: [],
            idProvided: true,
        );

        $result = $dispatcher->dispatch($request, $context);
        $this->assertInstanceOf(RequestContext::class, $result);
        $this->assertEquals('specific-correlation-id', $result->correlationId);
        $this->assertEquals('192.168.1.1', $result->clientIp);
    }
}
