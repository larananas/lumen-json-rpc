<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Dispatcher;

use Lumen\JsonRpc\Dispatcher\ParameterBinder;
use Lumen\JsonRpc\Exception\InvalidParamsException;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ParameterBinderExtendedTest extends TestCase
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

    public function testEmptyMethodReturnsEmptyArray(): void
    {
        $method = new ReflectionMethod($this, 'emptyMethod');
        $args = $this->binder->bind($method, ['anything'], $this->context);
        $this->assertSame([], $args);
    }

    public function testMethodWithOnlyContextReturnsJustContext(): void
    {
        $method = new ReflectionMethod($this, 'onlyContext');
        $args = $this->binder->bind($method, ['unused'], $this->context);
        $this->assertCount(1, $args);
        $this->assertInstanceOf(RequestContext::class, $args[0]);
    }

    public function testUnknownNamedParamsThrowsException(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessage('Unknown parameters');
        $method = new ReflectionMethod($this, 'simpleMethod');
        $this->binder->bind($method, ['name' => 'test', 'unknown' => 'val'], $this->context);
    }

    public function testTooManyPositionalParamsThrowsException(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessage('Too many positional parameters');
        $method = new ReflectionMethod($this, 'simpleMethod');
        $this->binder->bind($method, ['a', 'b', 'c', 'd'], $this->context);
    }

    public function testMissingPositionalParamThrowsException(): void
    {
        $this->expectException(InvalidParamsException::class);
        $method = new ReflectionMethod($this, 'simpleMethod');
        $this->binder->bind($method, ['onlyname'], $this->context);
    }

    public function testBoolParamTypeValidation(): void
    {
        $method = new ReflectionMethod($this, 'methodWithBool');
        $args = $this->binder->bind($method, ['flag' => true], $this->context);
        $this->assertTrue($args[0]);
    }

    public function testBoolParamWrongTypeThrows(): void
    {
        $this->expectException(InvalidParamsException::class);
        $method = new ReflectionMethod($this, 'methodWithBool');
        $this->binder->bind($method, ['flag' => 'yes'], $this->context);
    }

    public function testStringParamTypeValidation(): void
    {
        $method = new ReflectionMethod($this, 'methodWithString');
        $args = $this->binder->bind($method, ['val' => 'hello'], $this->context);
        $this->assertEquals('hello', $args[0]);
    }

    public function testStringParamWrongTypeThrows(): void
    {
        $this->expectException(InvalidParamsException::class);
        $method = new ReflectionMethod($this, 'methodWithString');
        $this->binder->bind($method, ['val' => 123], $this->context);
    }

    public function testArrayParamWithValidArray(): void
    {
        $method = new ReflectionMethod($this, 'methodWithArray');
        $args = $this->binder->bind($method, ['items' => [1, 2, 3]], $this->context);
        $this->assertEquals([1, 2, 3], $args[0]);
    }

    public function testUntypedParamAcceptsAnything(): void
    {
        $method = new ReflectionMethod($this, 'methodWithMixed');
        $args = $this->binder->bind($method, ['data' => ['complex' => 'data']], $this->context);
        $this->assertEquals(['complex' => 'data'], $args[0]);
    }

    public function testNullForNullableParam(): void
    {
        $method = new ReflectionMethod($this, 'methodWithNullable');
        $args = $this->binder->bind($method, [], $this->context);
        $this->assertNull($args[0]);
    }

    public function testDefaultUsedWhenParamMissing(): void
    {
        $method = new ReflectionMethod($this, 'methodWithDefault');
        $args = $this->binder->bind($method, [], $this->context);
        $this->assertEquals('default-val', $args[0]);
    }

    public function testNullOnNonNullableParamThrows(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessage('cannot be null');
        $method = new ReflectionMethod($this, 'methodWithString');
        $this->binder->bind($method, ['val' => null], $this->context);
    }

    public function testEmptyArrayIsPositional(): void
    {
        $method = new ReflectionMethod($this, 'methodWithDefault');
        $args = $this->binder->bind($method, [], $this->context);
        $this->assertEquals('default-val', $args[0]);
    }

    public function testAssociativeArrayNamedBinding(): void
    {
        $method = new ReflectionMethod($this, 'simpleMethod');
        $args = $this->binder->bind($method, ['name' => 'test', 'age' => 25], $this->context);
        $this->assertEquals('test', $args[0]);
        $this->assertEquals(25, $args[1]);
    }

    public function testPositionalBindingWithDefaults(): void
    {
        $method = new ReflectionMethod($this, 'methodWithOptionalAtEnd');
        $args = $this->binder->bind($method, ['first'], $this->context);
        $this->assertEquals('first', $args[0]);
        $this->assertEquals('fallback', $args[1]);
    }

    public function testFloatParamWithNonNumericThrows(): void
    {
        $this->expectException(InvalidParamsException::class);
        $method = new ReflectionMethod($this, 'methodFloatOnly');
        $this->binder->bind($method, ['price' => 'not-float'], $this->context);
    }

    public function testIntParamWithNonIntThrows(): void
    {
        $this->expectException(InvalidParamsException::class);
        $method = new ReflectionMethod($this, 'methodIntOnly');
        $this->binder->bind($method, ['count' => 3.14], $this->context);
    }

    public function emptyMethod(): void {}
    public function onlyContext(RequestContext $context): void {}
    public function simpleMethod(string $name, int $age): array { return []; }
    public function methodWithBool(bool $flag): bool { return $flag; }
    public function methodWithString(string $val): string { return $val; }
    public function methodWithArray(array $items): array { return $items; }
    public function methodWithMixed($data): mixed { return $data; }
    public function methodWithNullable(?string $value): ?string { return $value; }
    public function methodWithDefault(string $val = 'default-val'): string { return $val; }
    public function methodWithOptionalAtEnd(string $first, string $second = 'fallback'): string { return $first . $second; }
    public function methodFloatOnly(float $price): float { return $price; }
    public function methodIntOnly(int $count): int { return $count; }
}
