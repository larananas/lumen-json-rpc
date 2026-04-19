<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\RateLimit;

use Lumen\JsonRpc\RateLimit\RateLimitResult;
use PHPUnit\Framework\TestCase;

final class RateLimitResultTest extends TestCase
{
    public function testAllowedFactory(): void
    {
        $result = RateLimitResult::allowed(50, time() + 60, 100);
        $this->assertTrue($result->allowed);
        $this->assertEquals(50, $result->remaining);
        $this->assertEquals(100, $result->limit);
    }

    public function testDeniedFactory(): void
    {
        $resetAt = time() + 60;
        $result = RateLimitResult::denied($resetAt, 100);
        $this->assertFalse($result->allowed);
        $this->assertEquals(0, $result->remaining);
        $this->assertEquals($resetAt, $result->resetAt);
        $this->assertEquals(100, $result->limit);
    }
}
