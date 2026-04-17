<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Protocol;

use Lumen\JsonRpc\Protocol\Error;
use PHPUnit\Framework\TestCase;

final class ErrorTest extends TestCase
{
    public function testParseError(): void
    {
        $error = Error::parseError();
        $this->assertEquals(-32700, $error->code);
        $this->assertEquals('Parse error', $error->message);
        $this->assertNull($error->data);
    }

    public function testInvalidRequest(): void
    {
        $error = Error::invalidRequest();
        $this->assertEquals(-32600, $error->code);
        $this->assertEquals('Invalid Request', $error->message);
    }

    public function testMethodNotFound(): void
    {
        $error = Error::methodNotFound();
        $this->assertEquals(-32601, $error->code);
        $this->assertEquals('Method not found', $error->message);
    }

    public function testInvalidParams(): void
    {
        $error = Error::invalidParams('Missing field: name');
        $this->assertEquals(-32602, $error->code);
        $this->assertEquals('Invalid params', $error->message);
        $this->assertEquals('Missing field: name', $error->data);
    }

    public function testInternalError(): void
    {
        $error = Error::internalError();
        $this->assertEquals(-32603, $error->code);
        $this->assertEquals('Internal error', $error->message);
    }

    public function testToArrayWithoutData(): void
    {
        $error = Error::parseError();
        $arr = $error->toArray();
        $this->assertArrayHasKey('code', $arr);
        $this->assertArrayHasKey('message', $arr);
        $this->assertArrayNotHasKey('data', $arr);
    }

    public function testToArrayWithData(): void
    {
        $error = new Error(-32602, 'Invalid params', 'detail');
        $arr = $error->toArray();
        $this->assertArrayHasKey('data', $arr);
        $this->assertEquals('detail', $arr['data']);
    }
}
