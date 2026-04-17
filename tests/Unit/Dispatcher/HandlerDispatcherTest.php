<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Dispatcher;

use Lumen\JsonRpc\Dispatcher\HandlerDispatcher;
use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Dispatcher\MethodResolver;
use Lumen\JsonRpc\Dispatcher\ParameterBinder;
use Lumen\JsonRpc\Exception\MethodNotFoundException;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class HandlerDispatcherTest extends TestCase
{
    private string $handlerPath;

    protected function setUp(): void
    {
        $this->handlerPath = realpath(__DIR__ . '/../../../examples/basic/handlers') ?: __DIR__ . '/../../../examples/basic/handlers';
    }

    public function testDispatchWithRequestContextConstructor(): void
    {
        $resolver = new MethodResolver(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.'
        );

        $dispatcher = new HandlerDispatcher(
            $resolver,
            new ParameterBinder()
        );

        $context = new RequestContext(
            correlationId: 'test',
            headers: [],
            clientIp: '127.0.0.1',
        );

        $request = new Request(
            method: 'system.health',
            id: 1,
            params: [],
            idProvided: true,
        );

        $result = $dispatcher->dispatch($request, $context);
        $this->assertIsArray($result);
        $this->assertEquals('ok', $result['status']);
    }

    public function testDispatchWithCustomSeparator(): void
    {
        $resolver = new MethodResolver(
            [$this->handlerPath],
            'App\\Handlers\\',
            '_'
        );

        $dispatcher = new HandlerDispatcher(
            $resolver,
            new ParameterBinder()
        );

        $context = new RequestContext(
            correlationId: 'test',
            headers: [],
            clientIp: '127.0.0.1',
        );

        $request = new Request(
            method: 'system_health',
            id: 1,
            params: [],
            idProvided: true,
        );

        $result = $dispatcher->dispatch($request, $context);
        $this->assertIsArray($result);
        $this->assertEquals('ok', $result['status']);
    }

    public function testMethodNotFoundThrowsException(): void
    {
        $resolver = new MethodResolver(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.'
        );

        $dispatcher = new HandlerDispatcher(
            $resolver,
            new ParameterBinder()
        );

        $context = new RequestContext(
            correlationId: 'test',
            headers: [],
            clientIp: '127.0.0.1',
        );

        $request = new Request(
            method: 'nonexistent.method',
            id: 1,
            params: [],
            idProvided: true,
        );

        $this->expectException(MethodNotFoundException::class);
        $dispatcher->dispatch($request, $context);
    }

    public function testReservedRpcMethodThrowsException(): void
    {
        $resolver = new MethodResolver(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.'
        );

        $dispatcher = new HandlerDispatcher(
            $resolver,
            new ParameterBinder()
        );

        $context = new RequestContext(
            correlationId: 'test',
            headers: [],
            clientIp: '127.0.0.1',
        );

        $request = new Request(
            method: 'rpc.anything',
            id: 1,
            params: [],
            idProvided: true,
        );

        $this->expectException(MethodNotFoundException::class);
        $dispatcher->dispatch($request, $context);
    }
}
