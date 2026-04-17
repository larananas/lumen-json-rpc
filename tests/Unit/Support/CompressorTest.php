<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Support;

use Lumen\JsonRpc\Support\Compressor;
use PHPUnit\Framework\TestCase;

final class CompressorTest extends TestCase
{
    public function testEncodeAndDecodeGzip(): void
    {
        $data = 'Hello, this is test data for compression!';
        $encoded = Compressor::encodeGzip($data);
        $this->assertNotNull($encoded);
        $decoded = Compressor::decodeGzip($encoded);
        $this->assertEquals($data, $decoded);
    }

    public function testDecodeGzipReturnsNullForInvalidData(): void
    {
        $this->assertNull(Compressor::decodeGzip('not gzipped'));
        $this->assertNull(Compressor::decodeGzip(''));
    }

    public function testIsGzippedDetectsValidGzip(): void
    {
        $data = 'test data';
        $encoded = Compressor::encodeGzip($data);
        $this->assertTrue(Compressor::isGzipped($encoded));
    }

    public function testIsGzippedDetectsNonGzip(): void
    {
        $this->assertFalse(Compressor::isGzipped('plain text'));
        $this->assertFalse(Compressor::isGzipped(''));
    }
}
