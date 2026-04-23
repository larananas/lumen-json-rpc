<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Support;

use Lumen\JsonRpc\Support\Compressor;
use PHPUnit\Framework\TestCase;

final class CompressorMutationKillTest extends TestCase
{
    public function testDecodeGzipReturnsNullForEmptyString(): void
    {
        $this->assertNull(Compressor::decodeGzip(''));
    }

    public function testIsGzippedReturnsFalseForShortData(): void
    {
        $this->assertFalse(Compressor::isGzipped('x'));
    }

    public function testIsGzippedDetectsValidGzipHeader(): void
    {
        if (!function_exists('gzencode')) {
            $this->markTestSkipped('zlib not available');
        }
        $compressed = gzencode('test');
        $this->assertTrue(Compressor::isGzipped($compressed));
    }

    public function testIsGzippedReturnsFalseForNonGzipData(): void
    {
        $this->assertFalse(Compressor::isGzipped('hello world data'));
    }

    public function testEncodeThenDecodeRoundTrip(): void
    {
        if (!function_exists('gzencode')) {
            $this->markTestSkipped('zlib not available');
        }
        $original = '{"jsonrpc":"2.0","result":42,"id":1}';
        $encoded = Compressor::encodeGzip($original);
        $this->assertNotNull($encoded);
        $decoded = Compressor::decodeGzip($encoded);
        $this->assertSame($original, $decoded);
    }
}
