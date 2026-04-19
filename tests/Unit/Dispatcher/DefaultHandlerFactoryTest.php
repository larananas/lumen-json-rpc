<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Dispatcher;

use Lumen\JsonRpc\Dispatcher\DefaultHandlerFactory;
use Lumen\JsonRpc\Exception\MethodNotFoundException;
use Lumen\JsonRpc\Support\RequestContext;
use Lumen\JsonRpc\Tests\Fixtures\HandlerIncompatible;
use Lumen\JsonRpc\Tests\Fixtures\HandlerNoConstructor;
use Lumen\JsonRpc\Tests\Fixtures\HandlerOptionalParam;
use Lumen\JsonRpc\Tests\Fixtures\HandlerWithContext;
use PHPUnit\Framework\TestCase;

final class DefaultHandlerFactoryTest extends TestCase
{
    private RequestContext $context;

    protected function setUp(): void
    {
        $this->context = new RequestContext(
            correlationId: 'test',
            headers: [],
            clientIp: '127.0.0.1',
        );
    }

    public function testNoConstructorHandler(): void
    {
        $factory = new DefaultHandlerFactory();
        $instance = $factory->create(HandlerNoConstructor::class, $this->context);
        $this->assertInstanceOf(HandlerNoConstructor::class, $instance);
    }

    public function testHandlerWithContextInjection(): void
    {
        $factory = new DefaultHandlerFactory();
        $instance = $factory->create(HandlerWithContext::class, $this->context);
        $this->assertInstanceOf(HandlerWithContext::class, $instance);
    }

    public function testHandlerWithOptionalParam(): void
    {
        $factory = new DefaultHandlerFactory();
        $instance = $factory->create(HandlerOptionalParam::class, $this->context);
        $this->assertInstanceOf(HandlerOptionalParam::class, $instance);
    }

    public function testIncompatibleConstructorThrowsException(): void
    {
        $factory = new DefaultHandlerFactory();
        $this->expectException(MethodNotFoundException::class);
        $this->expectExceptionMessage('constructor is not compatible');
        $factory->create(HandlerIncompatible::class, $this->context);
    }

    public function testUnionTypeOptionalParamCreatesInstance(): void
    {
        $factory = new DefaultHandlerFactory();
        $instance = $factory->create(HandlerUnionTypeOptional::class, $this->context);
        $this->assertInstanceOf(HandlerUnionTypeOptional::class, $instance);
    }

    public function testUnionTypeRequiredParamThrowsException(): void
    {
        $factory = new DefaultHandlerFactory();
        $this->expectException(MethodNotFoundException::class);
        $this->expectExceptionMessage('constructor is not compatible');
        $factory->create(HandlerUnionTypeRequired::class, $this->context);
    }
}

final class HandlerUnionTypeOptional
{
    public function __construct(
        private readonly string|int $value = 'default',
    ) {}
}

final class HandlerUnionTypeRequired
{
    public function __construct(
        private readonly string|int $value,
    ) {}
}
