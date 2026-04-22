<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Log;

final class LogFormatter
{
    private const SENSITIVE_KEYS = [
        'password', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'credit_card', 'creditcard', 'cvv',
        'access_token', 'refresh_token', 'private_key',
    ];

    public function __construct(
        private readonly bool $sanitizeSecrets = true,
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function format(string $level, string $message, array $context = [], ?string $correlationId = null): string
    {
        $timestamp = date('Y-m-d\TH:i:s.vP');
        $cid = $correlationId ? " [$correlationId]" : '';
        $contextStr = '';

        $safeMessage = $this->sanitizeLine($message);

        if (!empty($context)) {
            $data = $this->sanitizeSecrets ? $this->sanitize($context) : $context;
            $contextStr = ' ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return "[$timestamp]$cid [$level] $safeMessage$contextStr" . PHP_EOL;
    }

    private function sanitizeLine(string $text): string
    {
        return str_replace(["\r", "\n"], ['\\r', '\\n'], $text);
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                /** @var array<int|string, mixed> $value */
                $data[$key] = $this->sanitize($value);
            } elseif ($this->isSensitiveKey((string)$key)) {
                $data[$key] = '***REDACTED***';
            }
        }
        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($lower, $sensitive)) {
                return true;
            }
        }
        return false;
    }
}
