<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\RateLimit;

interface RateLimiterInterface
{
    public function check(string $key): RateLimitResult;

    public function checkAndConsume(string $key, int $weight): RateLimitResult;
}
