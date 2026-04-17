<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Log;

enum LogLevel: int
{
    case DEBUG = 0;
    case INFO = 1;
    case WARNING = 2;
    case ERROR = 3;
    case NONE = 4;

    public static function fromString(string $level): self
    {
        return match (strtolower($level)) {
            'debug' => self::DEBUG,
            'info' => self::INFO,
            'warning' => self::WARNING,
            'error' => self::ERROR,
            'none' => self::NONE,
            default => self::INFO,
        };
    }
}
