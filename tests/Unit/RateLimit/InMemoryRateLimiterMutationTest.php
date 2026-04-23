<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\RateLimit;

use Lumen\JsonRpc\RateLimit\InMemoryRateLimiter;
use PHPUnit\Framework\TestCase;

final class InMemoryRateLimiterMutationTest extends TestCase
{
    public function testMultipleRequestsInSameWindowShareCounter(): void
    {
        $limiter = new InMemoryRateLimiter(3, 60);
        $r1 = $limiter->check('key');
        $r2 = $limiter->check('key');
        $this->assertTrue($r1->allowed);
        $this->assertSame(2, $r1->remaining);
        $this->assertTrue($r2->allowed);
        $this->assertSame(1, $r2->remaining);
    }

    public function testRequestAfterExhaustionIsDenied(): void
    {
        $limiter = new InMemoryRateLimiter(2, 60);
        $limiter->check('key');
        $limiter->check('key');
        $r3 = $limiter->check('key');
        $this->assertFalse($r3->allowed);
    }

    public function testWeightOneTreatedNormally(): void
    {
        $limiter = new InMemoryRateLimiter(5, 60);
        $r = $limiter->checkAndConsume('key', 1);
        $this->assertTrue($r->allowed);
        $this->assertSame(4, $r->remaining);
    }

    public function testWeightTwoConsumesCorrectly(): void
    {
        $limiter = new InMemoryRateLimiter(5, 60);
        $r = $limiter->checkAndConsume('key', 2);
        $this->assertTrue($r->allowed);
        $this->assertSame(3, $r->remaining);
    }

    public function testWeightZeroIsTreatedAsOne(): void
    {
        $limiter = new InMemoryRateLimiter(5, 60);
        $r = $limiter->checkAndConsume('key', 0);
        $this->assertTrue($r->allowed);
        $this->assertSame(4, $r->remaining);
    }

    public function testWeightNegativeIsTreatedAsOne(): void
    {
        $limiter = new InMemoryRateLimiter(5, 60);
        $r = $limiter->checkAndConsume('key', -1);
        $this->assertTrue($r->allowed);
        $this->assertSame(4, $r->remaining);
    }

    public function testRemainingIsZeroAtExactLimit(): void
    {
        $limiter = new InMemoryRateLimiter(3, 60);
        $limiter->check('key');
        $limiter->check('key');
        $r = $limiter->check('key');
        $this->assertTrue($r->allowed);
        $this->assertSame(0, $r->remaining);
    }

    public function testResetAtIsInFuture(): void
    {
        $limiter = new InMemoryRateLimiter(5, 60);
        $r = $limiter->check('key');
        $this->assertGreaterThan(time(), $r->resetAt);
        $this->assertLessThanOrEqual(time() + 60, $r->resetAt);
    }

    public function testResetAllowsNewRequests(): void
    {
        $limiter = new InMemoryRateLimiter(1, 60);
        $r1 = $limiter->check('key');
        $this->assertTrue($r1->allowed);
        $r2 = $limiter->check('key');
        $this->assertFalse($r2->allowed);
        $limiter->reset('key');
        $r3 = $limiter->check('key');
        $this->assertTrue($r3->allowed);
    }

    public function testResetAllClearsAllKeys(): void
    {
        $limiter = new InMemoryRateLimiter(1, 60);
        $limiter->check('key1');
        $limiter->check('key2');
        $limiter->resetAll();
        $this->assertTrue($limiter->check('key1')->allowed);
        $this->assertTrue($limiter->check('key2')->allowed);
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $limiter = new InMemoryRateLimiter(1, 60);
        $r1 = $limiter->check('key1');
        $r2 = $limiter->check('key2');
        $this->assertTrue($r1->allowed);
        $this->assertTrue($r2->allowed);
        $this->assertFalse($limiter->check('key1')->allowed);
        $this->assertFalse($limiter->check('key2')->allowed);
    }

    public function testLimitValueInResult(): void
    {
        $limiter = new InMemoryRateLimiter(42, 60);
        $r = $limiter->check('key');
        $this->assertSame(42, $r->limit);
    }

    public function testWeightExceedingRemainingIsDenied(): void
    {
        $limiter = new InMemoryRateLimiter(3, 60);
        $limiter->check('key');
        $limiter->check('key');
        $r = $limiter->checkAndConsume('key', 2);
        $this->assertFalse($r->allowed);
    }

    public function testExactWeightAtLimitIsAllowed(): void
    {
        $limiter = new InMemoryRateLimiter(5, 60);
        $limiter->check('key');
        $limiter->check('key');
        $r = $limiter->checkAndConsume('key', 3);
        $this->assertTrue($r->allowed);
        $this->assertSame(0, $r->remaining);
    }

    public function testDefaultMaxRequests100AllowsExactly100Requests(): void
    {
        $limiter = new InMemoryRateLimiter();
        for ($i = 0; $i < 99; $i++) {
            $r = $limiter->check('key');
            $this->assertTrue($r->allowed, "Request $i should be allowed");
        }
        $r100 = $limiter->check('key');
        $this->assertTrue($r100->allowed);
        $this->assertSame(0, $r100->remaining);
    }

    public function testDefaultMaxRequests100Denies101stRequest(): void
    {
        $limiter = new InMemoryRateLimiter();
        for ($i = 0; $i < 100; $i++) {
            $limiter->check('key');
        }
        $r = $limiter->check('key');
        $this->assertFalse($r->allowed);
    }
}
