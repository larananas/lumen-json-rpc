<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\RateLimit;

final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $remaining,
        public readonly int $resetAt,
        public readonly int $limit,
    ) {}

    public static function allowed(int $remaining, int $resetAt, int $limit): self
    {
        return new self(true, $remaining, $resetAt, $limit);
    }

    public static function denied(int $resetAt, int $limit): self
    {
        return new self(false, 0, $resetAt, $limit);
    }
}
