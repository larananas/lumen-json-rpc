<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Exception;

use Lumen\JsonRpc\Exception\ParseErrorException;
use Lumen\JsonRpc\Exception\InvalidRequestException;
use Lumen\JsonRpc\Exception\MethodNotFoundException;
use Lumen\JsonRpc\Exception\InvalidParamsException;
use Lumen\JsonRpc\Exception\InternalErrorException;
use Lumen\JsonRpc\Exception\ServerErrorException;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    public function testParseError(): void
    {
        $e = new ParseErrorException();
        $this->assertEquals(-32700, $e->getErrorCode());
        $this->assertEquals('Parse error', $e->getErrorMessage());
    }

    public function testInvalidRequest(): void
    {
        $e = new InvalidRequestException();
        $this->assertEquals(-32600, $e->getErrorCode());
        $this->assertEquals('Invalid Request', $e->getErrorMessage());
    }

    public function testMethodNotFound(): void
    {
        $e = new MethodNotFoundException();
        $this->assertEquals(-32601, $e->getErrorCode());
        $this->assertEquals('Method not found', $e->getErrorMessage());
    }

    public function testInvalidParams(): void
    {
        $e = new InvalidParamsException();
        $this->assertEquals(-32602, $e->getErrorCode());
        $this->assertEquals('Invalid params', $e->getErrorMessage());
    }

    public function testInternalError(): void
    {
        $e = new InternalErrorException();
        $this->assertEquals(-32603, $e->getErrorCode());
        $this->assertEquals('Internal error', $e->getErrorMessage());
    }

    public function testServerErrorInRange(): void
    {
        $e = new ServerErrorException('Custom error', -32050);
        $this->assertEquals(-32050, $e->getErrorCode());
        $this->assertEquals('Custom error', $e->getErrorMessage());
    }

    public function testServerErrorOutOfRangeIsClamped(): void
    {
        $e = new ServerErrorException('Test', -32100);
        $this->assertEquals(-32099, $e->getErrorCode());
    }

    public function testExceptionWithData(): void
    {
        $e = new InvalidParamsException('Bad params', 0, null, ['field' => 'name']);
        $this->assertEquals(['field' => 'name'], $e->getErrorData());
        $arr = $e->toArray();
        $this->assertArrayHasKey('data', $arr);
    }

    public function testExceptionToArrayWithoutData(): void
    {
        $e = new MethodNotFoundException();
        $arr = $e->toArray();
        $this->assertArrayNotHasKey('data', $arr);
        $this->assertEquals(-32601, $arr['code']);
    }
}
