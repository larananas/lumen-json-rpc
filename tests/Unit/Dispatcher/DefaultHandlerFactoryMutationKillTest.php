<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Dispatcher;

use Lumen\JsonRpc\Dispatcher\DefaultHandlerFactory;
use Lumen\JsonRpc\Exception\MethodNotFoundException;
use Lumen\JsonRpc\Support\RequestContext;
use Lumen\JsonRpc\Tests\Fixtures\HandlerWithContext;
use Lumen\JsonRpc\Tests\Fixtures\HandlerNoConstructor;
use Lumen\JsonRpc\Tests\Fixtures\HandlerOptionalParam;
use Lumen\JsonRpc\Tests\Fixtures\HandlerIncompatible;
use PHPUnit\Framework\TestCase;

final class DefaultHandlerFactoryMutationKillTest extends TestCase
{
    public function testCreatesHandlerWithRequestContextParameter(): void
    {
        $factory = new DefaultHandlerFactory();
        $context = new RequestContext(correlationId: 'test', headers: [], clientIp: '127.0.0.1');
        $instance = $factory->create(HandlerWithContext::class, $context);
        $this->assertInstanceOf(HandlerWithContext::class, $instance);
    }

    public function testCreatesHandlerWithoutConstructor(): void
    {
        $factory = new DefaultHandlerFactory();
        $context = new RequestContext(correlationId: 'test', headers: [], clientIp: '127.0.0.1');
        $instance = $factory->create(HandlerNoConstructor::class, $context);
        $this->assertInstanceOf(HandlerNoConstructor::class, $instance);
    }

    public function testIncompatibleConstructorThrows(): void
    {
        $factory = new DefaultHandlerFactory();
        $context = new RequestContext(correlationId: 'test', headers: [], clientIp: '127.0.0.1');
        $this->expectException(MethodNotFoundException::class);
        $factory->create(HandlerIncompatible::class, $context);
    }

    public function testOptionalFirstParamCreatesWithDefault(): void
    {
        $factory = new DefaultHandlerFactory();
        $context = new RequestContext(correlationId: 'test', headers: [], clientIp: '127.0.0.1');
        $instance = $factory->create(HandlerOptionalParam::class, $context);
        $this->assertInstanceOf(HandlerOptionalParam::class, $instance);
    }
}
