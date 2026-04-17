<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Protocol;

use Lumen\JsonRpc\Protocol\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testRequestWithIdIsNotNotification(): void
    {
        $request = new Request('test.method', 1, null, true);
        $this->assertFalse($request->isNotification);
    }

    public function testRequestWithoutIdIsNotification(): void
    {
        $request = new Request('test.method', null, null, false);
        $this->assertTrue($request->isNotification);
    }

    public function testRequestWithNullIdIsNotNotification(): void
    {
        $request = new Request('test.method', null, null, true);
        $this->assertFalse($request->isNotification);
    }

    public function testFromArrayParsesCorrectly(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'user.create',
            'params' => ['name' => 'John'],
            'id' => 42,
        ];
        $request = Request::fromArray($data);
        $this->assertEquals('user.create', $request->method);
        $this->assertEquals(42, $request->id);
        $this->assertEquals(['name' => 'John'], $request->params);
        $this->assertEquals('2.0', $request->jsonrpc);
        $this->assertFalse($request->isNotification);
    }

    public function testFromArrayNotification(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'notify',
        ];
        $request = Request::fromArray($data);
        $this->assertTrue($request->isNotification);
        $this->assertNull($request->id);
    }

    public function testToArrayIncludesIdWhenProvided(): void
    {
        $request = new Request('test', 1, null, true);
        $arr = $request->toArray();
        $this->assertArrayHasKey('id', $arr);
    }

    public function testToArrayOmitsIdWhenNotProvided(): void
    {
        $request = new Request('test', null, null, false);
        $arr = $request->toArray();
        $this->assertArrayNotHasKey('id', $arr);
    }

    public function testToArrayOmitsParamsWhenNull(): void
    {
        $request = new Request('test', 1, null, true);
        $arr = $request->toArray();
        $this->assertArrayNotHasKey('params', $arr);
    }
}
