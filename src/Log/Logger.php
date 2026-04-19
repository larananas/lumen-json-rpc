<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Log;

final class Logger
{
    private LogLevel $minLevel;
    private LogFormatter $formatter;
    private ?LogRotator $rotator;
    private bool $silent;

    public function __construct(
        private readonly string $logPath,
        string $level = 'info',
        bool $sanitizeSecrets = true,
        ?LogRotator $rotator = null,
    ) {
        $this->minLevel = LogLevel::fromString($level);
        $this->formatter = new LogFormatter($sanitizeSecrets);
        $this->rotator = $rotator;
        $this->silent = ($logPath === '' || $this->minLevel === LogLevel::NONE);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = [], ?string $correlationId = null): void
    {
        $this->log(LogLevel::DEBUG, $message, $context, $correlationId);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = [], ?string $correlationId = null): void
    {
        $this->log(LogLevel::INFO, $message, $context, $correlationId);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = [], ?string $correlationId = null): void
    {
        $this->log(LogLevel::WARNING, $message, $context, $correlationId);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = [], ?string $correlationId = null): void
    {
        $this->log(LogLevel::ERROR, $message, $context, $correlationId);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(LogLevel $level, string $message, array $context, ?string $correlationId): void
    {
        if ($this->silent) {
            return;
        }

        if ($level->value < $this->minLevel->value) {
            return;
        }

        $line = $this->formatter->format($level->name, $message, $context, $correlationId);

        $this->ensureDirectory();

        if ($this->rotator !== null) {
            $this->rotator->rotateIfNeeded($this->logPath);
        }

        $result = file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
        if ($result === false && $this->minLevel->value <= LogLevel::ERROR->value) {
            error_log("Lumen\JsonRpc: Failed to write to log file: {$this->logPath}");
        }
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->logPath);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
}
