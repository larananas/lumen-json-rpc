<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Support;

use Lumen\JsonRpc\Support\Compressor;
use PHPUnit\Framework\TestCase;

final class CompressorCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Compressor::resetCache();
    }

    public function testIsZlibAvailableReturnsBool(): void
    {
        $result = Compressor::isZlibAvailable();
        $this->assertIsBool($result);
    }

    public function testIsZlibAvailableIsCachedConsistent(): void
    {
        $first = Compressor::isZlibAvailable();
        $second = Compressor::isZlibAvailable();
        $this->assertSame($first, $second);
    }

    public function testResetCacheAllowsRecomputation(): void
    {
        $first = Compressor::isZlibAvailable();
        Compressor::resetCache();
        $second = Compressor::isZlibAvailable();
        $this->assertSame($first, $second);
    }

    public function testDecodeGzipReturnsNullForEmptyString(): void
    {
        $this->assertNull(Compressor::decodeGzip(''));
    }

    public function testEncodeGzipReturnsNullWhenZlibUnavailable(): void
    {
        $result = Compressor::encodeGzip('test');
        if (!Compressor::isZlibAvailable()) {
            $this->assertNull($result);
        } else {
            $this->assertNotNull($result);
        }
    }

    public function testIsGzippedDetectsValidHeader(): void
    {
        $gzipped = "\x1f\x8b" . "data";
        $this->assertTrue(Compressor::isGzipped($gzipped));
    }

    public function testIsGzippedRejectsInvalidHeader(): void
    {
        $this->assertFalse(Compressor::isGzipped('not gzipped'));
    }

    public function testIsGzippedRejectsShortString(): void
    {
        $this->assertFalse(Compressor::isGzipped('a'));
    }

    public function testIsGzippedRejectsEmptyString(): void
    {
        $this->assertFalse(Compressor::isGzipped(''));
    }

    public function testEncodeDecodeRoundTrip(): void
    {
        if (!Compressor::isZlibAvailable()) {
            $this->markTestSkipped('ext-zlib not available');
        }

        $original = '{"jsonrpc":"2.0","method":"test","id":1}';
        $encoded = Compressor::encodeGzip($original);
        $this->assertNotNull($encoded);
        $this->assertTrue(Compressor::isGzipped($encoded));

        $decoded = Compressor::decodeGzip($encoded);
        $this->assertSame($original, $decoded);
    }
}
