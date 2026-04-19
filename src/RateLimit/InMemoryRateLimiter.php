<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\RateLimit;

final class InMemoryRateLimiter implements RateLimiterInterface
{
    /** @var array<string, array{window: int, count: int}> */
    private array $storage = [];

    public function __construct(
        private readonly int $maxRequests = 100,
        private readonly int $windowSeconds = 60,
    ) {}

    public function check(string $key): RateLimitResult
    {
        return $this->checkAndConsume($key, 1);
    }

    public function checkAndConsume(string $key, int $weight): RateLimitResult
    {
        $now = time();
        $windowStart = $now - ($now % $this->windowSeconds);
        $resetAt = $windowStart + $this->windowSeconds;

        if ($weight < 1) {
            $weight = 1;
        }

        if (!isset($this->storage[$key])) {
            $this->storage[$key] = ['window' => 0, 'count' => 0];
        }

        if ($this->storage[$key]['window'] !== $windowStart) {
            $this->storage[$key] = ['window' => $windowStart, 'count' => 0];
        }

        $currentCount = $this->storage[$key]['count'];
        if ($currentCount + $weight > $this->maxRequests) {
            return RateLimitResult::denied($resetAt, $this->maxRequests);
        }

        $this->storage[$key]['count'] = $currentCount + $weight;

        return RateLimitResult::allowed(
            $this->maxRequests - $this->storage[$key]['count'],
            $resetAt,
            $this->maxRequests,
        );
    }

    public function reset(string $key): void
    {
        unset($this->storage[$key]);
    }

    public function resetAll(): void
    {
        $this->storage = [];
    }
}
