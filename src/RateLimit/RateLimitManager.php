<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\RateLimit;

use Lumen\JsonRpc\Support\RequestContext;

final class RateLimitManager
{
    private ?RateLimiterInterface $limiter = null;

    public function __construct(
        private readonly bool $enabled = false,
        private readonly string $strategy = 'ip',
        private readonly int $batchWeight = 1,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setLimiter(RateLimiterInterface $limiter): void
    {
        $this->limiter = $limiter;
    }

    public function check(RequestContext $context, int $requestCount = 1): RateLimitResult
    {
        if (!$this->enabled || $this->limiter === null) {
            return RateLimitResult::allowed(PHP_INT_MAX, 0, PHP_INT_MAX);
        }

        $weight = max(1, $requestCount) * max(1, $this->batchWeight);
        $key = $this->resolveKey($context);

        if ($weight <= 1) {
            return $this->limiter->check($key);
        }

        return $this->limiter->checkAndConsume($key, $weight);
    }

    public static function computeRawItemCount(mixed $decoded): int
    {
        if (!is_array($decoded) || empty($decoded)) {
            return 1;
        }
        if (array_keys($decoded) !== range(0, count($decoded) - 1)) {
            return 1;
        }
        return count($decoded);
    }

    private function resolveKey(RequestContext $context): string
    {
        return match ($this->strategy) {
            'user' => $context->authUserId ?? 'anonymous_' . $context->clientIp,
            'token' => ($context->authClaims['jti'] ?? '') ?: $context->clientIp,
            default => $context->clientIp,
        };
    }
}