<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Http;

use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\Http\RequestReader;
use PHPUnit\Framework\TestCase;

final class RequestReaderMutationKillTest extends TestCase
{
    public function testDefaultMaxBodySizeAllowsExactlyMaxBytes(): void
    {
        $reader = new RequestReader();
        $body = str_repeat('a', 1_048_576);
        $request = new HttpRequest($body, [], 'POST', '127.0.0.1', []);
        $this->assertSame($body, $reader->read($request));
    }

    public function testDefaultMaxBodySizeRejectsOverMaxBytes(): void
    {
        $reader = new RequestReader();
        $body = str_repeat('a', 1_048_577);
        $request = new HttpRequest($body, [], 'POST', '127.0.0.1', []);
        $this->assertSame('', $reader->read($request));
    }

    public function testGzipDisabledDoesNotDecompress(): void
    {
        if (!function_exists('gzencode')) {
            $this->markTestSkipped('zlib not available');
        }
        $original = '{"jsonrpc":"2.0","method":"test","id":1}';
        $compressed = gzencode($original);
        $reader = new RequestReader(requestGzipEnabled: false);
        $request = new HttpRequest($compressed, [], 'POST', '127.0.0.1', []);
        $result = $reader->read($request);
        $this->assertNotSame($original, $result);
    }

    public function testExactBoundaryForDecodedGzipSize(): void
    {
        if (!function_exists('gzencode')) {
            $this->markTestSkipped('zlib not available');
        }
        $original = str_repeat('a', 100);
        $compressed = gzencode($original);
        $reader = new RequestReader(maxBodySize: 100, requestGzipEnabled: true);
        $request = new HttpRequest($compressed, ['Content-Encoding' => 'gzip'], 'POST', '127.0.0.1', []);
        $result = $reader->read($request);
        $this->assertSame($original, $result);
    }

    public function testDecodedGzipOverMaxIsRejected(): void
    {
        if (!function_exists('gzencode')) {
            $this->markTestSkipped('zlib not available');
        }
        $original = str_repeat('a', 101);
        $compressed = gzencode($original);
        $reader = new RequestReader(maxBodySize: 100, requestGzipEnabled: true);
        $request = new HttpRequest($compressed, ['Content-Encoding' => 'gzip'], 'POST', '127.0.0.1', []);
        $result = $reader->read($request);
        $this->assertSame('', $result);
    }
}
