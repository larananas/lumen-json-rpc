<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Log;

use Lumen\JsonRpc\Log\Logger;
use Lumen\JsonRpc\Log\LogRotator;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/jsonrpc_logger_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->testDir . '/*') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->testDir);
    }

    private function logPath(): string
    {
        return $this->testDir . '/test.log';
    }

    public function testDebugWritesToFile(): void
    {
        $logger = new Logger($this->logPath(), 'debug');
        $logger->debug('debug message', ['key' => 'value']);
        $content = file_get_contents($this->logPath());
        $this->assertStringContainsString('debug message', $content);
        $this->assertStringContainsString('[DEBUG]', $content);
    }

    public function testInfoWritesToFile(): void
    {
        $logger = new Logger($this->logPath(), 'debug');
        $logger->info('info message');
        $content = file_get_contents($this->logPath());
        $this->assertStringContainsString('info message', $content);
        $this->assertStringContainsString('[INFO]', $content);
    }

    public function testWarningWritesToFile(): void
    {
        $logger = new Logger($this->logPath(), 'debug');
        $logger->warning('warning message');
        $content = file_get_contents($this->logPath());
        $this->assertStringContainsString('warning message', $content);
        $this->assertStringContainsString('[WARNING]', $content);
    }

    public function testErrorWritesToFile(): void
    {
        $logger = new Logger($this->logPath(), 'debug');
        $logger->error('error message');
        $content = file_get_contents($this->logPath());
        $this->assertStringContainsString('error message', $content);
        $this->assertStringContainsString('[ERROR]', $content);
    }

    public function testCorrelationIdInLog(): void
    {
        $logger = new Logger($this->logPath(), 'debug');
        $logger->info('test', [], 'corr-123');
        $content = file_get_contents($this->logPath());
        $this->assertStringContainsString('[corr-123]', $content);
    }

    public function testSilentModeDoesNotWrite(): void
    {
        $logger = new Logger('', 'info');
        $logger->info('should not write');
        $this->assertFileDoesNotExist($this->logPath());
    }

    public function testNoneLevelDoesNotWrite(): void
    {
        $logger = new Logger($this->logPath(), 'none');
        $logger->error('should not write');
        $this->assertFileDoesNotExist($this->logPath());
    }

    public function testMinLevelFiltersMessages(): void
    {
        $logger = new Logger($this->logPath(), 'warning');
        $logger->debug('should not appear');
        $logger->info('should not appear');
        $logger->warning('should appear');
        $logger->error('should appear');

        $content = file_get_contents($this->logPath());
        $this->assertStringNotContainsString('should not appear', $content);
        $this->assertStringContainsString('should appear', $content);
    }

    public function testCreatesDirectoryAutomatically(): void
    {
        $nestedPath = $this->testDir . '/nested/deep/test.log';
        $logger = new Logger($nestedPath, 'debug');
        $logger->info('auto-create dir');
        $this->assertFileExists($nestedPath);
    }

    public function testWithContextArray(): void
    {
        $logger = new Logger($this->logPath(), 'debug', false);
        $logger->info('with context', ['foo' => 'bar', 'baz' => 42]);
        $content = file_get_contents($this->logPath());
        $this->assertStringContainsString('"foo":"bar"', $content);
        $this->assertStringContainsString('"baz":42', $content);
    }

    public function testSecretSanitizationInLog(): void
    {
        $logger = new Logger($this->logPath(), 'debug', true);
        $logger->info('secrets', ['password' => 'hunter2', 'token' => 'abc123']);
        $content = file_get_contents($this->logPath());
        $this->assertStringContainsString('***REDACTED***', $content);
        $this->assertStringNotContainsString('hunter2', $content);
    }

    public function testLogRotatorCalledWhenSet(): void
    {
        $logPath = $this->logPath();
        file_put_contents($logPath, str_repeat('x', 1000));

        $rotator = new LogRotator(maxSize: 100, maxFiles: 3, compress: false);
        $logger = new Logger($logPath, 'debug', false, $rotator);
        $logger->info('trigger rotation');

        $this->assertFileExists($logPath . '.1');
    }

    public function testAppendsWithMultipleCalls(): void
    {
        $logger = new Logger($this->logPath(), 'debug');
        $logger->info('line1');
        $logger->info('line2');
        $content = file_get_contents($this->logPath());
        $this->assertStringContainsString('line1', $content);
        $this->assertStringContainsString('line2', $content);
        $lines = array_filter(explode("\n", trim($content)));
        $this->assertCount(2, $lines);
    }
}
