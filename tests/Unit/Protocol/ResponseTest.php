<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Protocol;

use Lumen\JsonRpc\Protocol\Error;
use Lumen\JsonRpc\Protocol\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testSuccessResponseStructure(): void
    {
        $response = Response::success(1, 'hello');
        $arr = $response->toArray();

        $this->assertEquals('2.0', $arr['jsonrpc']);
        $this->assertEquals('hello', $arr['result']);
        $this->assertEquals(1, $arr['id']);
        $this->assertArrayNotHasKey('error', $arr);
    }

    public function testErrorResponseStructure(): void
    {
        $error = Error::methodNotFound();
        $response = Response::error(1, $error);
        $arr = $response->toArray();

        $this->assertEquals('2.0', $arr['jsonrpc']);
        $this->assertArrayHasKey('error', $arr);
        $this->assertArrayNotHasKey('result', $arr);
        $this->assertEquals(-32601, $arr['error']['code']);
        $this->assertEquals(1, $arr['id']);
    }

    public function testNullIdOnError(): void
    {
        $response = Response::error(null, Error::parseError());
        $arr = $response->toArray();
        $this->assertNull($arr['id']);
    }

    public function testToJsonProducesValidJson(): void
    {
        $response = Response::success(1, ['key' => 'value']);
        $json = $response->toJson();
        $decoded = json_decode($json, true);

        $this->assertNotNull($decoded);
        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertEquals(['key' => 'value'], $decoded['result']);
    }

    public function testErrorWithNullIdForUnknownRequest(): void
    {
        $response = Response::error(null, Error::invalidRequest());
        $arr = $response->toArray();
        $this->assertNull($arr['id']);
        $this->assertEquals(-32600, $arr['error']['code']);
    }
}
