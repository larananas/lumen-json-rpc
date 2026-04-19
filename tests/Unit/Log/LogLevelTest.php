<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Log;

use Lumen\JsonRpc\Log\LogLevel;
use PHPUnit\Framework\TestCase;

final class LogLevelTest extends TestCase
{
    public function testFromStringDebug(): void
    {
        $this->assertSame(LogLevel::DEBUG, LogLevel::fromString('debug'));
    }

    public function testFromStringInfo(): void
    {
        $this->assertSame(LogLevel::INFO, LogLevel::fromString('info'));
    }

    public function testFromStringWarning(): void
    {
        $this->assertSame(LogLevel::WARNING, LogLevel::fromString('warning'));
    }

    public function testFromStringError(): void
    {
        $this->assertSame(LogLevel::ERROR, LogLevel::fromString('error'));
    }

    public function testFromStringNone(): void
    {
        $this->assertSame(LogLevel::NONE, LogLevel::fromString('none'));
    }

    public function testFromStringDefaultFallback(): void
    {
        $this->assertSame(LogLevel::INFO, LogLevel::fromString('unknown'));
    }

    public function testFromStringCaseInsensitive(): void
    {
        $this->assertSame(LogLevel::DEBUG, LogLevel::fromString('DEBUG'));
        $this->assertSame(LogLevel::WARNING, LogLevel::fromString('Warning'));
    }

    public function testEnumValues(): void
    {
        $this->assertSame(0, LogLevel::DEBUG->value);
        $this->assertSame(1, LogLevel::INFO->value);
        $this->assertSame(2, LogLevel::WARNING->value);
        $this->assertSame(3, LogLevel::ERROR->value);
        $this->assertSame(4, LogLevel::NONE->value);
    }
}
