<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Log;

use Lumen\JsonRpc\Log\LogFormatter;
use PHPUnit\Framework\TestCase;

final class LogFormatterMutationKillTest extends TestCase
{
    public function testDefaultSanitizeSecretsIsTrue(): void
    {
        $formatter = new LogFormatter();
        $output = $formatter->format('INFO', 'test', ['password' => 'secret123']);
        $this->assertStringContainsString('***REDACTED***', $output);
        $this->assertStringNotContainsString('secret123', $output);
    }

    public function testSanitizeSecretsFalsePreservesValues(): void
    {
        $formatter = new LogFormatter(sanitizeSecrets: false);
        $output = $formatter->format('INFO', 'test', ['password' => 'secret123']);
        $this->assertStringNotContainsString('***REDACTED***', $output);
        $this->assertStringContainsString('secret123', $output);
    }

    public function testFormatContainsLevel(): void
    {
        $formatter = new LogFormatter();
        $output = $formatter->format('WARNING', 'test message');
        $this->assertStringContainsString('[WARNING]', $output);
    }

    public function testFormatContainsMessage(): void
    {
        $formatter = new LogFormatter();
        $output = $formatter->format('INFO', 'hello world');
        $this->assertStringContainsString('hello world', $output);
    }

    public function testFormatContainsCorrelationId(): void
    {
        $formatter = new LogFormatter();
        $output = $formatter->format('INFO', 'test', [], 'corr-123');
        $this->assertStringContainsString('[corr-123]', $output);
    }

    public function testFormatWithoutCorrelationId(): void
    {
        $formatter = new LogFormatter();
        $output = $formatter->format('INFO', 'test');
        $this->assertStringNotContainsString('[]', trim($output));
    }

    public function testNewlinesInMessageAreEscaped(): void
    {
        $formatter = new LogFormatter();
        $output = $formatter->format('INFO', "line1\nline2");
        $this->assertStringContainsString('\\n', $output);
        $this->assertStringNotContainsString("line1\nline2", $output);
    }

    public function testSensitiveKeysAreRedacted(): void
    {
        $formatter = new LogFormatter();
        $output = $formatter->format('INFO', 'test', [
            'token' => 'abc',
            'api_key' => 'def',
            'Authorization' => 'Bearer xyz',
            'safe_key' => 'visible',
        ]);
        $this->assertStringContainsString('***REDACTED***', $output);
        $this->assertStringContainsString('visible', $output);
        $this->assertStringNotContainsString('"abc"', $output);
        $this->assertStringNotContainsString('"def"', $output);
    }

    public function testNestedSensitiveKeysAreRedacted(): void
    {
        $formatter = new LogFormatter();
        $output = $formatter->format('INFO', 'test', [
            'nested' => ['secret' => 'hidden'],
        ]);
        $this->assertStringContainsString('***REDACTED***', $output);
    }
}
