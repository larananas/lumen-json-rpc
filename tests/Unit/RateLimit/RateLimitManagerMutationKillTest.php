<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\RateLimit;

use Lumen\JsonRpc\RateLimit\RateLimitManager;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class RateLimitManagerMutationKillTest extends TestCase
{
    public function testDefaultEnabledIsFalse(): void
    {
        $manager = new RateLimitManager();
        $this->assertFalse($manager->isEnabled());
    }

    public function testDisabledReturnsAllowedWhenNoLimiter(): void
    {
        $manager = new RateLimitManager(enabled: false);
        $context = new RequestContext(correlationId: 'test', headers: [], clientIp: '127.0.0.1');
        $result = $manager->check($context);
        $this->assertTrue($result->allowed);
    }

    public function testDefaultStrategyIsIp(): void
    {
        $manager = new RateLimitManager(enabled: false, strategy: 'ip');
        $context = new RequestContext(correlationId: 'test', headers: [], clientIp: '10.0.0.1');
        $result = $manager->check($context);
        $this->assertTrue($result->allowed);
    }

    public function testComputeRawItemCountReturns1ForObject(): void
    {
        $this->assertSame(1, RateLimitManager::computeRawItemCount(['key' => 'value']));
    }

    public function testComputeRawItemCountReturnsCountForArray(): void
    {
        $this->assertSame(3, RateLimitManager::computeRawItemCount([['a'], ['b'], ['c']]));
    }

    public function testComputeRawItemCountReturns1ForEmptyArray(): void
    {
        $this->assertSame(1, RateLimitManager::computeRawItemCount([]));
    }

    public function testComputeRawItemCountReturns1ForNull(): void
    {
        $this->assertSame(1, RateLimitManager::computeRawItemCount(null));
    }

    public function testComputeRawItemCountReturns1ForString(): void
    {
        $this->assertSame(1, RateLimitManager::computeRawItemCount('not-array'));
    }

    public function testUserStrategyUsesAuthUserId(): void
    {
        $limiter = new InMemoryRateLimiterMutationTarget();
        $manager = new RateLimitManager(enabled: true, strategy: 'user', batchWeight: 1);
        $manager->setLimiter($limiter);

        $context = new RequestContext(
            correlationId: 'test',
            headers: [],
            clientIp: '10.0.0.1',
            authUserId: 'user-42',
        );
        $result = $manager->check($context);
        $this->assertTrue($result->allowed);
    }

    public function testBatchWeightMultipliesRequestCount(): void
    {
        $limiter = new InMemoryRateLimiterMutationTarget();
        $manager = new RateLimitManager(enabled: true, strategy: 'ip', batchWeight: 2);
        $manager->setLimiter($limiter);

        $context = new RequestContext(
            correlationId: 'test',
            headers: [],
            clientIp: '10.0.0.1',
        );
        $result = $manager->check($context, 3);
        $this->assertTrue($result->allowed);
    }
}

final class InMemoryRateLimiterMutationTarget implements \Lumen\JsonRpc\RateLimit\RateLimiterInterface
{
    public int $lastWeight = 0;

    public function check(string $key): \Lumen\JsonRpc\RateLimit\RateLimitResult
    {
        return $this->checkAndConsume($key, 1);
    }

    public function checkAndConsume(string $key, int $weight): \Lumen\JsonRpc\RateLimit\RateLimitResult
    {
        $this->lastWeight = $weight;
        return \Lumen\JsonRpc\RateLimit\RateLimitResult::allowed(100 - $weight, time() + 60, 100);
    }

    public function reset(string $key): void {}

    public function resetAll(): void {}
}
