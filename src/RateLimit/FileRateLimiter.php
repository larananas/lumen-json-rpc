<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\RateLimit;

final class FileRateLimiter implements RateLimiterInterface
{
    public function __construct(
        private readonly int $maxRequests = 100,
        private readonly int $windowSeconds = 60,
        private readonly string $storagePath = '',
        private readonly bool $failOpen = true,
    ) {}

    public function check(string $key): RateLimitResult
    {
        return $this->checkAndConsume($key, 1);
    }

    public function checkAndConsume(string $key, int $weight): RateLimitResult
    {
        $this->ensureStorageDir();
        $file = $this->getFilePath($key);
        $now = time();
        $windowStart = $now - ($now % $this->windowSeconds);
        $resetAt = $windowStart + $this->windowSeconds;

        if ($weight < 1) {
            $weight = 1;
        }

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            if ($this->failOpen) {
                trigger_error(
                    "Rate limiter: unable to open storage for key '{$key}', fail-open allowing request",
                    E_USER_WARNING
                );
                return RateLimitResult::allowed($this->maxRequests - 1, $resetAt, $this->maxRequests);
            }
            trigger_error(
                "Rate limiter: unable to open storage for key '{$key}', fail-closed denying request",
                E_USER_WARNING
            );
            return RateLimitResult::denied($resetAt, $this->maxRequests);
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                if ($this->failOpen) {
                    trigger_error(
                        "Rate limiter: unable to acquire lock for key '{$key}', fail-open allowing request",
                        E_USER_WARNING
                    );
                    return RateLimitResult::allowed($this->maxRequests - 1, $resetAt, $this->maxRequests);
                }
                trigger_error(
                    "Rate limiter: unable to acquire lock for key '{$key}', fail-closed denying request",
                    E_USER_WARNING
                );
                return RateLimitResult::denied($resetAt, $this->maxRequests);
            }

            $content = stream_get_contents($fp);
            $data = is_string($content) ? json_decode($content, true) : null;
            if (!is_array($data)) {
                $data = ['window' => 0, 'count' => 0];
            }

            if ($data['window'] !== $windowStart) {
                $data = ['window' => $windowStart, 'count' => 0];
            }

            $currentCount = (int)$data['count'];
            if ($currentCount + $weight > $this->maxRequests) {
                return RateLimitResult::denied($resetAt, $this->maxRequests);
            }

            $data['count'] = $currentCount + $weight;

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);

            return RateLimitResult::allowed(
                $this->maxRequests - $data['count'],
                $resetAt,
                $this->maxRequests
            );
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function getFilePath(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9._-]/', '_', $key);
        return $this->storagePath . '/' . $safeKey . '.json';
    }

    private function ensureStorageDir(): void
    {
        if ($this->storagePath !== '' && !is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0755, true);
        }
    }
}