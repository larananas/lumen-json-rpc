<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Http;

use Lumen\JsonRpc\Http\HttpRequest;
use PHPUnit\Framework\TestCase;

final class HttpRequestTest extends TestCase
{
    private HttpRequest $request;

    protected function setUp(): void
    {
        $this->request = new HttpRequest(
            body: '{"jsonrpc":"2.0"}',
            headers: [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer token123',
                'X-Custom' => 'value',
            ],
            method: 'POST',
            clientIp: '192.168.1.1',
            server: [],
        );
    }

    public function testGetHeaderReturnsHeaderValue(): void
    {
        $this->assertSame('application/json', $this->request->getHeader('Content-Type'));
        $this->assertSame('value', $this->request->getHeader('X-Custom'));
    }

    public function testGetHeaderReturnsNullForMissingHeader(): void
    {
        $this->assertNull($this->request->getHeader('X-Missing'));
    }

    public function testGetAuthorizationHeaderReturnsToken(): void
    {
        $this->assertSame('Bearer token123', $this->request->getAuthorizationHeader());
    }

    public function testGetAuthorizationHeaderReturnsNullWhenAbsent(): void
    {
        $request = new HttpRequest(
            body: '',
            headers: [],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );
        $this->assertNull($request->getAuthorizationHeader());
    }

    public function testIsGzippedReturnsTrueForGzipHeader(): void
    {
        $request = new HttpRequest(
            body: 'this is not actually gzipped',
            headers: ['Content-Encoding' => 'gzip'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );
        $this->assertTrue($request->isGzipped());
    }

    public function testIsGzippedReturnsFalseWhenNoEncoding(): void
    {
        $this->assertFalse($this->request->isGzipped());
    }
}
