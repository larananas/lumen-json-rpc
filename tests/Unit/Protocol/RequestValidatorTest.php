<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Protocol;

use Lumen\JsonRpc\Protocol\Error;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Protocol\RequestValidator;
use PHPUnit\Framework\TestCase;

final class RequestValidatorTest extends TestCase
{
    private RequestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new RequestValidator();
    }

    public function testValidRequestReturnsNoError(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'user.create',
            'params' => ['name' => 'John'],
            'id' => 1,
        ];
        $this->assertNull($this->validator->validateArray($data));
    }

    public function testMissingJsonrpcVersion(): void
    {
        $data = ['method' => 'test', 'id' => 1];
        $error = $this->validator->validateArray($data);
        $this->assertNotNull($error);
        $this->assertEquals(-32600, $error->code);
    }

    public function testWrongJsonrpcVersion(): void
    {
        $data = ['jsonrpc' => '1.0', 'method' => 'test', 'id' => 1];
        $error = $this->validator->validateArray($data);
        $this->assertNotNull($error);
        $this->assertEquals(-32600, $error->code);
    }

    public function testMissingMethod(): void
    {
        $data = ['jsonrpc' => '2.0', 'id' => 1];
        $error = $this->validator->validateArray($data);
        $this->assertNotNull($error);
        $this->assertEquals(-32600, $error->code);
    }

    public function testNonStringMethod(): void
    {
        $data = ['jsonrpc' => '2.0', 'method' => 123, 'id' => 1];
        $error = $this->validator->validateArray($data);
        $this->assertNotNull($error);
        $this->assertEquals(-32600, $error->code);
    }

    public function testInvalidIdType(): void
    {
        $data = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => []];
        $error = $this->validator->validateArray($data);
        $this->assertNotNull($error);
        $this->assertEquals(-32600, $error->code);
    }

    public function testFloatIdRejected(): void
    {
        $data = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => 3.14];
        $error = $this->validator->validateArray($data);
        $this->assertNotNull($error);
        $this->assertEquals(-32600, $error->code);
        $this->assertStringContainsString('fractional', (string)$error->data);
    }

    public function testIntegerFloatIdRejected(): void
    {
        $data = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1.0];
        $error = $this->validator->validateArray($data);
        $this->assertNotNull($error);
        $this->assertEquals(-32600, $error->code);
    }

    public function testNullIdIsAllowed(): void
    {
        $data = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => null];
        $this->assertNull($this->validator->validateArray($data));
    }

    public function testStringIdIsAllowed(): void
    {
        $data = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => 'abc'];
        $this->assertNull($this->validator->validateArray($data));
    }

    public function testInvalidParamsType(): void
    {
        $data = ['jsonrpc' => '2.0', 'method' => 'test', 'params' => 'string', 'id' => 1];
        $error = $this->validator->validateArray($data);
        $this->assertNotNull($error);
        $this->assertEquals(-32600, $error->code);
    }

    public function testNotificationWithoutId(): void
    {
        $data = ['jsonrpc' => '2.0', 'method' => 'test'];
        $this->assertNull($this->validator->validateArray($data));
    }

    public function testExtraMembersAllowedByDefault(): void
    {
        $data = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1, 'extra' => 'value'];
        $this->assertNull($this->validator->validateArray($data));
    }

    public function testEmptyMethodRejected(): void
    {
        $data = ['jsonrpc' => '2.0', 'method' => '', 'id' => 1];
        $error = $this->validator->validateArray($data);
        $this->assertNotNull($error);
        $this->assertEquals(-32600, $error->code);
    }

    public function testReservedRpcMethodPrefixRejected(): void
    {
        $data = ['jsonrpc' => '2.0', 'method' => 'rpc.reserved', 'id' => 1];
        $error = $this->validator->validateArray($data);
        $this->assertNotNull($error);
        $this->assertEquals(-32600, $error->code);
        $this->assertStringContainsString('reserved', (string)$error->data);
    }

    public function testMethodNameTooLongRejected(): void
    {
        $longMethod = str_repeat('a', 257);
        $data = ['jsonrpc' => '2.0', 'method' => $longMethod, 'id' => 1];
        $error = $this->validator->validateArray($data);
        $this->assertNotNull($error);
        $this->assertEquals(-32600, $error->code);
        $this->assertStringContainsString('too long', (string)$error->data);
    }

    public function testMaxLengthMethodNameAccepted(): void
    {
        $method = str_repeat('a', 256);
        $data = ['jsonrpc' => '2.0', 'method' => $method, 'id' => 1];
        $this->assertNull($this->validator->validateArray($data));
    }

    public function testValidateRequestInvalidJsonrpc(): void
    {
        $request = new Request('test.method', 1, null, true, '1.0');
        $error = $this->validator->validateRequest($request);
        $this->assertNotNull($error);
        $this->assertEquals(-32600, $error->code);
    }

    public function testValidateRequestEmptyMethod(): void
    {
        $request = new Request('', 1, null, true, '2.0');
        $error = $this->validator->validateRequest($request);
        $this->assertNotNull($error);
        $this->assertEquals(-32600, $error->code);
    }

    public function testValidateRequestValidReturnsNull(): void
    {
        $request = new Request('test.method', 1, null, true, '2.0');
        $this->assertNull($this->validator->validateRequest($request));
    }

    public function testNonStrictModeAllowsExtraMembers(): void
    {
        $validator = new RequestValidator(strict: false);
        $data = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1, 'extra' => 'value'];
        $this->assertNull($validator->validateArray($data));
    }

    public function testStrictModeRejectsExtraMembers(): void
    {
        $validator = new RequestValidator(strict: true);
        $data = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1, 'extra' => 'value'];
        $error = $validator->validateArray($data);
        $this->assertNotNull($error);
        $this->assertEquals(-32600, $error->code);
    }

    public function testStrictModeErrorMessageFormat(): void
    {
        $validator = new RequestValidator(strict: true);
        $data = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1, 'foo' => 1, 'bar' => 2];
        $error = $validator->validateArray($data);
        $this->assertNotNull($error);
        $msg = $error->data ?? $error->message;
        $this->assertStringStartsWith('unexpected members: ', (string) $msg);
        $this->assertStringContainsString('foo', (string) $msg);
        $this->assertStringContainsString('bar', (string) $msg);
    }
}
