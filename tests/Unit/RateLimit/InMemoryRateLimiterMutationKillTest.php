<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\RateLimit;

use Lumen\JsonRpc\RateLimit\InMemoryRateLimiter;
use PHPUnit\Framework\TestCase;

final class InMemoryRateLimiterMutationKillTest extends TestCase
{
    public function testDefaultMaxRequestsIs100(): void
    {
        $limiter = new InMemoryRateLimiter();
        for ($i = 0; $i < 100; $i++) {
            $result = $limiter->check('key');
            $this->assertTrue($result->allowed);
        }
        $result = $limiter->check('key');
        $this->assertFalse($result->allowed);
    }

    public function testCheckAndConsumeWithWeight(): void
    {
        $limiter = new InMemoryRateLimiter(maxRequests: 10, windowSeconds: 60);
        $result = $limiter->checkAndConsume('key', 5);
        $this->assertTrue($result->allowed);
        $this->assertSame(5, $result->remaining);

        $result = $limiter->checkAndConsume('key', 5);
        $this->assertTrue($result->allowed);

        $result = $limiter->checkAndConsume('key', 1);
        $this->assertFalse($result->allowed);
    }

    public function testWeightBelowOneIsTreatedAsOne(): void
    {
        $limiter = new InMemoryRateLimiter(maxRequests: 5, windowSeconds: 60);
        $result = $limiter->checkAndConsume('key', 0);
        $this->assertTrue($result->allowed);
    }

    public function testResetClearsKey(): void
    {
        $limiter = new InMemoryRateLimiter(maxRequests: 1, windowSeconds: 60);
        $limiter->check('key');
        $result = $limiter->check('key');
        $this->assertFalse($result->allowed);

        $limiter->reset('key');
        $result = $limiter->check('key');
        $this->assertTrue($result->allowed);
    }

    public function testResetAllClearsEverything(): void
    {
        $limiter = new InMemoryRateLimiter(maxRequests: 1, windowSeconds: 60);
        $limiter->check('key1');
        $limiter->check('key2');
        $limiter->resetAll();
        $this->assertTrue($limiter->check('key1')->allowed);
        $this->assertTrue($limiter->check('key2')->allowed);
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $limiter = new InMemoryRateLimiter(maxRequests: 1, windowSeconds: 60);
        $this->assertTrue($limiter->check('a')->allowed);
        $this->assertFalse($limiter->check('a')->allowed);
        $this->assertTrue($limiter->check('b')->allowed);
    }

    public function testDenialResultContainsCorrectResetAt(): void
    {
        $limiter = new InMemoryRateLimiter(maxRequests: 1, windowSeconds: 60);
        $limiter->check('key');
        $result = $limiter->check('key');
        $this->assertFalse($result->allowed);
        $this->assertGreaterThan(time(), $result->resetAt);
    }
}
