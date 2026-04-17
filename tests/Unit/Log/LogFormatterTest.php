<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Log;

use Lumen\JsonRpc\Log\LogFormatter;
use PHPUnit\Framework\TestCase;

final class LogFormatterTest extends TestCase
{
    public function testFormatIncludesTimestamp(): void
    {
        $formatter = new LogFormatter();
        $output = $formatter->format('INFO', 'test message');
        $this->assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2}T/', $output);
    }

    public function testFormatIncludesLevel(): void
    {
        $formatter = new LogFormatter();
        $output = $formatter->format('INFO', 'test message');
        $this->assertStringContainsString('[INFO]', $output);
    }

    public function testFormatIncludesMessage(): void
    {
        $formatter = new LogFormatter();
        $output = $formatter->format('INFO', 'hello world');
        $this->assertStringContainsString('hello world', $output);
    }

    public function testFormatWithCorrelationId(): void
    {
        $formatter = new LogFormatter();
        $output = $formatter->format('INFO', 'test', [], 'abc-123');
        $this->assertStringContainsString('[abc-123]', $output);
    }

    public function testFormatWithContext(): void
    {
        $formatter = new LogFormatter(false);
        $output = $formatter->format('INFO', 'test', ['key' => 'value']);
        $this->assertStringContainsString('"key":"value"', $output);
    }

    public function testSecretSanitization(): void
    {
        $formatter = new LogFormatter(true);
        $output = $formatter->format('INFO', 'test', [
            'password' => 'secret123',
            'token' => 'abc',
            'safe_key' => 'visible',
        ]);
        $this->assertStringContainsString('***REDACTED***', $output);
        $this->assertStringNotContainsString('secret123', $output);
        $this->assertStringContainsString('visible', $output);
    }

    public function testNestedSecretSanitization(): void
    {
        $formatter = new LogFormatter(true);
        $output = $formatter->format('INFO', 'test', [
            'data' => ['api_key' => 'hidden'],
        ]);
        $this->assertStringContainsString('***REDACTED***', $output);
        $this->assertStringNotContainsString('hidden', $output);
    }
}
