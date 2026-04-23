<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Doc;

use Lumen\JsonRpc\Doc\MethodDoc;
use PHPUnit\Framework\TestCase;

final class MethodDocTest extends TestCase
{
    public function testToArrayReturnsAllKeysWithCorrectValues(): void
    {
        $doc = new MethodDoc(
            name: 'testMethod',
            description: 'A test method',
            params: ['x' => ['type' => 'int', 'description' => 'param x', 'required' => true, 'default' => null]],
            returnType: 'string',
            returnDescription: 'the result',
            requiresAuth: true,
            errors: [['code' => 'ERR', 'description' => 'some error']],
            exampleRequest: '{"jsonrpc":"2.0","method":"testMethod","id":1}',
            exampleResponse: '{"jsonrpc":"2.0","result":"ok","id":1}',
            requestSchema: ['type' => 'object'],
            resultSchema: ['type' => 'string'],
        );

        $arr = $doc->toArray();

        $this->assertSame('testMethod', $arr['name']);
        $this->assertSame('A test method', $arr['description']);
        $this->assertSame(['x' => ['type' => 'int', 'description' => 'param x', 'required' => true, 'default' => null]], $arr['params']);
        $this->assertSame('string', $arr['returnType']);
        $this->assertSame('the result', $arr['returnDescription']);
        $this->assertTrue($arr['requiresAuth']);
        $this->assertSame([['code' => 'ERR', 'description' => 'some error']], $arr['errors']);
        $this->assertSame('{"jsonrpc":"2.0","method":"testMethod","id":1}', $arr['exampleRequest']);
        $this->assertSame('{"jsonrpc":"2.0","result":"ok","id":1}', $arr['exampleResponse']);
        $this->assertSame(['type' => 'object'], $arr['requestSchema']);
        $this->assertSame(['type' => 'string'], $arr['resultSchema']);
    }

    public function testToArrayWithDefaultsContainsAllKeys(): void
    {
        $doc = new MethodDoc(name: 'minimal');
        $arr = $doc->toArray();

        $this->assertSame('minimal', $arr['name']);
        $this->assertSame('', $arr['description']);
        $this->assertSame([], $arr['params']);
        $this->assertNull($arr['returnType']);
        $this->assertSame('', $arr['returnDescription']);
        $this->assertFalse($arr['requiresAuth']);
        $this->assertSame([], $arr['errors']);
        $this->assertNull($arr['exampleRequest']);
        $this->assertNull($arr['exampleResponse']);
        $this->assertNull($arr['requestSchema']);
        $this->assertNull($arr['resultSchema']);
    }

    public function testToArrayPreservesRequiresAuthFalse(): void
    {
        $doc = new MethodDoc(name: 'noauth', requiresAuth: false);
        $arr = $doc->toArray();
        $this->assertSame(false, $arr['requiresAuth']);
    }

    public function testToArrayPreservesNonNullReturnType(): void
    {
        $doc = new MethodDoc(name: 'typed', returnType: 'int');
        $arr = $doc->toArray();
        $this->assertSame('int', $arr['returnType']);
    }
}
