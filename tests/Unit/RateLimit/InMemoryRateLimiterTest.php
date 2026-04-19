<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\RateLimit;

use Lumen\JsonRpc\RateLimit\InMemoryRateLimiter;
use PHPUnit\Framework\TestCase;

final class InMemoryRateLimiterTest extends TestCase
{
    private InMemoryRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new InMemoryRateLimiter(5, 60);
    }

    public function testAllowsUnderLimit(): void
    {
        $result = $this->limiter->check('key1');
        $this->assertTrue($result->allowed);
        $this->assertSame(4, $result->remaining);
    }

    public function testDeniesOverLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->check('key1');
        }
        $result = $this->limiter->check('key1');
        $this->assertFalse($result->allowed);
    }

    public function testSeparateKeysAreIndependent(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->check('key1');
        }
        $result = $this->limiter->check('key2');
        $this->assertTrue($result->allowed);
    }

    public function testCheckAndConsumeWithWeight(): void
    {
        $result = $this->limiter->checkAndConsume('key1', 3);
        $this->assertTrue($result->allowed);
        $this->assertSame(2, $result->remaining);

        $result = $this->limiter->checkAndConsume('key1', 3);
        $this->assertFalse($result->allowed);
    }

    public function testResetClearsKey(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->check('key1');
        }
        $this->limiter->reset('key1');
        $result = $this->limiter->check('key1');
        $this->assertTrue($result->allowed);
    }

    public function testResetAllClearsEverything(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->check('key1');
            $this->limiter->check('key2');
        }
        $this->limiter->resetAll();
        $this->assertTrue($this->limiter->check('key1')->allowed);
        $this->assertTrue($this->limiter->check('key2')->allowed);
    }

    public function testWeightBelowOneTreatedAsOne(): void
    {
        $result = $this->limiter->checkAndConsume('key1', 0);
        $this->assertTrue($result->allowed);
        $this->assertSame(4, $result->remaining);
    }

    public function testRemainingDecrementsCorrectly(): void
    {
        $result = $this->limiter->check('key1');
        $this->assertSame(4, $result->remaining);

        $result = $this->limiter->check('key1');
        $this->assertSame(3, $result->remaining);

        $result = $this->limiter->check('key1');
        $this->assertSame(2, $result->remaining);
    }

    public function testLimitValueInResult(): void
    {
        $result = $this->limiter->check('key1');
        $this->assertSame(5, $result->limit);
    }
}
