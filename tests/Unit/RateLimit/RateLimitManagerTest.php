<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\RateLimit;

use Lumen\JsonRpc\RateLimit\RateLimitManager;
use Lumen\JsonRpc\RateLimit\RateLimiterInterface;
use Lumen\JsonRpc\RateLimit\RateLimitResult;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class RateLimitManagerTest extends TestCase
{
    public function testDisabledByDefault(): void
    {
        $manager = new RateLimitManager();
        $this->assertFalse($manager->isEnabled());
    }

    public function testEnabledWhenConstructedWithTrue(): void
    {
        $manager = new RateLimitManager(true);
        $this->assertTrue($manager->isEnabled());
    }

    public function testCheckReturnsAllowedWhenDisabled(): void
    {
        $manager = new RateLimitManager(false);
        $ctx = new RequestContext('cid', [], '127.0.0.1');
        $result = $manager->check($ctx);
        $this->assertTrue($result->allowed);
    }

    public function testCheckReturnsAllowedWhenNoLimiter(): void
    {
        $manager = new RateLimitManager(true);
        $ctx = new RequestContext('cid', [], '127.0.0.1');
        $result = $manager->check($ctx);
        $this->assertTrue($result->allowed);
    }

    public function testCheckDelegatesToLimiterForSingleRequest(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects($this->once())->method('check')->with('127.0.0.1')
            ->willReturn(RateLimitResult::allowed(99, time() + 60, 100));

        $manager = new RateLimitManager(true, 'ip', 1);
        $manager->setLimiter($limiter);

        $ctx = new RequestContext('cid', [], '127.0.0.1');
        $result = $manager->check($ctx, 1);
        $this->assertTrue($result->allowed);
    }

    public function testCheckUsesCheckAndConsumeForBatchWeight(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects($this->once())->method('checkAndConsume')->with('127.0.0.1', 6)
            ->willReturn(RateLimitResult::allowed(94, time() + 60, 100));

        $manager = new RateLimitManager(true, 'ip', 2);
        $manager->setLimiter($limiter);

        $ctx = new RequestContext('cid', [], '127.0.0.1');
        $result = $manager->check($ctx, 3);
        $this->assertTrue($result->allowed);
    }

    public function testStrategyIpUsesClientIp(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects($this->once())->method('check')->with('10.0.0.1')
            ->willReturn(RateLimitResult::allowed(99, time() + 60, 100));

        $manager = new RateLimitManager(true, 'ip', 1);
        $manager->setLimiter($limiter);

        $ctx = new RequestContext('cid', [], '10.0.0.1');
        $manager->check($ctx);
    }

    public function testStrategyUserUsesAuthUserId(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects($this->once())->method('check')
            ->with('user-42')
            ->willReturn(RateLimitResult::allowed(99, time() + 60, 100));

        $manager = new RateLimitManager(true, 'user', 1);
        $manager->setLimiter($limiter);

        $ctx = new RequestContext('cid', [], '127.0.0.1', 'user-42', [], []);
        $manager->check($ctx);
    }

    public function testStrategyUserFallsBackToIpWhenAnonymous(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects($this->once())->method('check')
            ->with($this->stringContains('anonymous_'))
            ->willReturn(RateLimitResult::allowed(99, time() + 60, 100));

        $manager = new RateLimitManager(true, 'user', 1);
        $manager->setLimiter($limiter);

        $ctx = new RequestContext('cid', [], '192.168.1.1');
        $manager->check($ctx);
    }

    public function testStrategyTokenUsesJtiClaim(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects($this->once())->method('check')
            ->with('my-jti-123')
            ->willReturn(RateLimitResult::allowed(99, time() + 60, 100));

        $manager = new RateLimitManager(true, 'token', 1);
        $manager->setLimiter($limiter);

        $ctx = new RequestContext('cid', [], '127.0.0.1', null, ['jti' => 'my-jti-123'], []);
        $manager->check($ctx);
    }

    public function testStrategyTokenFallsBackToIpWhenNoJti(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects($this->once())->method('check')
            ->with('10.0.0.5')
            ->willReturn(RateLimitResult::allowed(99, time() + 60, 100));

        $manager = new RateLimitManager(true, 'token', 1);
        $manager->setLimiter($limiter);

        $ctx = new RequestContext('cid', [], '10.0.0.5', null, [], []);
        $manager->check($ctx);
    }

    public function testComputeRawItemCountSingleObject(): void
    {
        $this->assertEquals(1, RateLimitManager::computeRawItemCount(
            ['jsonrpc' => '2.0', 'method' => 'test']
        ));
    }

    public function testComputeRawItemCountBatchArray(): void
    {
        $this->assertEquals(3, RateLimitManager::computeRawItemCount([
            ['jsonrpc' => '2.0', 'method' => 'a'],
            ['jsonrpc' => '2.0', 'method' => 'b'],
            ['jsonrpc' => '2.0', 'method' => 'c'],
        ]));
    }

    public function testComputeRawItemCountNull(): void
    {
        $this->assertEquals(1, RateLimitManager::computeRawItemCount(null));
    }

    public function testComputeRawItemCountString(): void
    {
        $this->assertEquals(1, RateLimitManager::computeRawItemCount('not-array'));
    }

    public function testComputeRawItemCountEmptyArray(): void
    {
        $this->assertEquals(1, RateLimitManager::computeRawItemCount([]));
    }

    public function testBatchWeightMultiplesRequestCount(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects($this->once())->method('checkAndConsume')
            ->with('127.0.0.1', 10)
            ->willReturn(RateLimitResult::allowed(90, time() + 60, 100));

        $manager = new RateLimitManager(true, 'ip', 5);
        $manager->setLimiter($limiter);

        $ctx = new RequestContext('cid', [], '127.0.0.1');
        $manager->check($ctx, 2);
    }

    public function testStrategyUserAnonymousKeyStartsWithAnonymousPrefix(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects($this->once())->method('check')
            ->with($this->equalTo('anonymous_192.168.1.1'))
            ->willReturn(RateLimitResult::allowed(99, time() + 60, 100));

        $manager = new RateLimitManager(true, 'user', 1);
        $manager->setLimiter($limiter);

        $ctx = new RequestContext('cid', [], '192.168.1.1');
        $manager->check($ctx);
    }
}
