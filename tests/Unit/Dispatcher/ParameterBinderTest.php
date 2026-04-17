<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Dispatcher;

use Lumen\JsonRpc\Dispatcher\ParameterBinder;
use Lumen\JsonRpc\Exception\InvalidParamsException;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ParameterBinderTest extends TestCase
{
    private ParameterBinder $binder;
    private RequestContext $context;

    protected function setUp(): void
    {
        $this->binder = new ParameterBinder();
        $this->context = new RequestContext(
            correlationId: 'test',
            headers: [],
            clientIp: '127.0.0.1',
        );
    }

    public function testBindNamedParams(): void
    {
        $method = new ReflectionMethod($this, 'exampleMethod');
        $args = $this->binder->bind($method, ['name' => 'John', 'age' => 30], $this->context);
        $this->assertCount(3, $args);
        $this->assertInstanceOf(RequestContext::class, $args[0]);
        $this->assertEquals('John', $args[1]);
        $this->assertEquals(30, $args[2]);
    }

    public function testBindPositionalParams(): void
    {
        $method = new ReflectionMethod($this, 'exampleMethod');
        $args = $this->binder->bind($method, ['John', 30], $this->context);
        $this->assertCount(3, $args);
        $this->assertEquals('John', $args[1]);
        $this->assertEquals(30, $args[2]);
    }

    public function testMissingRequiredParamThrowsInvalidParams(): void
    {
        $this->expectException(InvalidParamsException::class);
        $method = new ReflectionMethod($this, 'exampleMethod');
        $this->binder->bind($method, ['age' => 30], $this->context);
    }

    public function testWrongScalarTypeThrowsInvalidParams(): void
    {
        $this->expectException(InvalidParamsException::class);
        $method = new ReflectionMethod($this, 'exampleMethod');
        $this->binder->bind($method, ['name' => 'John', 'age' => 'not-a-number'], $this->context);
    }

    public function testNullWhenNotAllowedThrowsInvalidParams(): void
    {
        $this->expectException(InvalidParamsException::class);
        $method = new ReflectionMethod($this, 'exampleMethod');
        $this->binder->bind($method, ['name' => null, 'age' => 30], $this->context);
    }

    public function testOptionalParamUsesDefault(): void
    {
        $method = new ReflectionMethod($this, 'methodWithOptional');
        $args = $this->binder->bind($method, [], $this->context);
        $this->assertEquals(10, $args[1]);
    }

    public function testNullableParamAcceptsNull(): void
    {
        $method = new ReflectionMethod($this, 'methodWithNullable');
        $args = $this->binder->bind($method, ['value' => null], $this->context);
        $this->assertNull($args[1]);
    }

    public function testNullParamsDefaultsToEmptyArray(): void
    {
        $method = new ReflectionMethod($this, 'methodWithOptional');
        $args = $this->binder->bind($method, null, $this->context);
        $this->assertEquals(10, $args[1]);
    }

    public function testIntToFloatCoercion(): void
    {
        $method = new ReflectionMethod($this, 'methodWithFloat');
        $args = $this->binder->bind($method, ['price' => 10], $this->context);
        $this->assertEquals(10.0, $args[1]);
    }

    public function testWrongTypeStringForInt(): void
    {
        $this->expectException(InvalidParamsException::class);
        $method = new ReflectionMethod($this, 'methodWithFloat');
        $this->binder->bind($method, ['price' => 'cheap'], $this->context);
    }

    public function testMethodWithoutContextParam(): void
    {
        $method = new ReflectionMethod($this, 'methodNoContext');
        $args = $this->binder->bind($method, ['x' => 5], $this->context);
        $this->assertCount(1, $args);
        $this->assertEquals(5, $args[0]);
    }

    public function testWrongTypeForArrayParam(): void
    {
        $this->expectException(InvalidParamsException::class);
        $method = new ReflectionMethod($this, 'methodWithArray');
        $this->binder->bind($method, ['items' => 'not-array'], $this->context);
    }

    public function exampleMethod(RequestContext $context, string $name, int $age): array
    {
        return ['name' => $name, 'age' => $age];
    }

    public function methodWithOptional(RequestContext $context, int $limit = 10): int
    {
        return $limit;
    }

    public function methodWithNullable(RequestContext $context, ?string $value): ?string
    {
        return $value;
    }

    public function methodWithFloat(RequestContext $context, float $price): float
    {
        return $price;
    }

    public function methodNoContext(int $x): int
    {
        return $x;
    }

    public function methodWithArray(RequestContext $context, array $items): array
    {
        return $items;
    }
}
