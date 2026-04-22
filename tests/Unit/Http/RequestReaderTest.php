<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Http;

use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\Http\RequestReader;
use Lumen\JsonRpc\Support\Compressor;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class RequestReaderTest extends TestCase
{
    protected function tearDown(): void
    {
        Compressor::resetCache();
    }

    public function testNormalBodyRead(): void
    {
        $reader = new RequestReader(1_048_576, true);
        $request = new HttpRequest(
            body: '{"test": true}',
            headers: ['Content-Type' => 'application/json'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );
        $this->assertEquals('{"test": true}', $reader->read($request));
    }

    public function testGzippedBodyDecompressed(): void
    {
        $reader = new RequestReader(1_048_576, true);
        $original = '{"jsonrpc":"2.0","method":"test","id":1}';
        $compressed = gzencode($original);
        $request = new HttpRequest(
            body: $compressed,
            headers: ['Content-Encoding' => 'gzip'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );
        $this->assertEquals($original, $reader->read($request));
    }

    public function testInvalidGzipReturnsEmpty(): void
    {
        $reader = new RequestReader(1_048_576, true);
        $request = new HttpRequest(
            body: 'not-valid-gzip',
            headers: ['Content-Encoding' => 'gzip'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );
        $this->assertEquals('', $reader->read($request));
    }

    public function testOversizedBodyReturnsEmpty(): void
    {
        $reader = new RequestReader(10, true);
        $request = new HttpRequest(
            body: str_repeat('x', 100),
            headers: [],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );
        $this->assertEquals('', $reader->read($request));
    }

    public function testPostDecompressionOversizeReturnsEmpty(): void
    {
        $reader = new RequestReader(50, true);
        $large = str_repeat('x', 200);
        $compressed = gzencode($large);
        $request = new HttpRequest(
            body: $compressed,
            headers: ['Content-Encoding' => 'gzip'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );
        $this->assertEquals('', $reader->read($request));
    }

    public function testGzipDisabledRejectsCompressedBody(): void
    {
        $reader = new RequestReader(1_048_576, false);
        $original = '{"jsonrpc":"2.0","method":"test","id":1}';
        $compressed = gzencode($original);
        $request = new HttpRequest(
            body: $compressed,
            headers: ['Content-Encoding' => 'gzip'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );
        $this->assertNotEquals($original, $reader->read($request));
    }

    public function testGzippedBodyReturnsEmptyWhenZlibUnavailable(): void
    {
        $this->forceZlibAvailability(false);

        $reader = new RequestReader(1_048_576, true);
        $original = '{"jsonrpc":"2.0","method":"test","id":1}';
        $compressed = gzencode($original);
        $request = new HttpRequest(
            body: $compressed,
            headers: ['Content-Encoding' => 'gzip'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        );

        $this->assertSame('', $reader->read($request));
    }

    private function forceZlibAvailability(bool $available): void
    {
        $property = new ReflectionProperty(Compressor::class, 'zlibAvailable');
        $property->setValue(null, $available);
    }
}
