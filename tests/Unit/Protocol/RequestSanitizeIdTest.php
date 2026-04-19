<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Protocol;

use Lumen\JsonRpc\Protocol\Request;
use PHPUnit\Framework\TestCase;

final class RequestSanitizeIdTest extends TestCase
{
    public function testSanitizeIdReturnsStringUnchanged(): void
    {
        $this->assertSame('abc', Request::sanitizeId('abc'));
    }

    public function testSanitizeIdReturnsIntUnchanged(): void
    {
        $this->assertSame(42, Request::sanitizeId(42));
    }

    public function testSanitizeIdReturnsNullUnchanged(): void
    {
        $this->assertNull(Request::sanitizeId(null));
    }

    public function testSanitizeIdReturnsNullForFloat(): void
    {
        $this->assertNull(Request::sanitizeId(3.14));
    }

    public function testSanitizeIdReturnsNullForBool(): void
    {
        $this->assertNull(Request::sanitizeId(true));
        $this->assertNull(Request::sanitizeId(false));
    }

    public function testSanitizeIdReturnsNullForArray(): void
    {
        $this->assertNull(Request::sanitizeId([1, 2]));
    }

    public function testSanitizeIdReturnsNullForObject(): void
    {
        $this->assertNull(Request::sanitizeId((object)['a' => 1]));
    }

    public function testSanitizeIdHandlesLargeInt(): void
    {
        $this->assertSame(PHP_INT_MAX, Request::sanitizeId(PHP_INT_MAX));
    }

    public function testSanitizeIdHandlesEmptyString(): void
    {
        $this->assertSame('', Request::sanitizeId(''));
    }

    public function testSanitizeIdHandlesSpecialChars(): void
    {
        $this->assertSame('test-id_123!@#', Request::sanitizeId('test-id_123!@#'));
    }
}
