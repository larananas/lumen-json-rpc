<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Log;

use Lumen\JsonRpc\Log\LogRotator;
use PHPUnit\Framework\TestCase;

final class LogRotatorTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/jsonrpc_test_rotate_' . uniqid();
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

    public function testNoRotationNeeded(): void
    {
        $logPath = $this->testDir . '/test.log';
        file_put_contents($logPath, str_repeat('x', 100));

        $rotator = new LogRotator(maxSize: 1000);
        $rotator->rotateIfNeeded($logPath);

        $this->assertFileExists($logPath);
        $this->assertFileDoesNotExist($logPath . '.1.gz');
    }

    public function testRotationWhenExceedingSize(): void
    {
        $logPath = $this->testDir . '/test.log';
        file_put_contents($logPath, str_repeat('x', 1000));

        $rotator = new LogRotator(maxSize: 100, maxFiles: 3, compress: false);
        $rotator->rotateIfNeeded($logPath);

        $this->assertFileExists($logPath . '.1');
        $this->assertFileExists($logPath);
        $this->assertEquals('', file_get_contents($logPath));
    }

    public function testRotationWithCompression(): void
    {
        $logPath = $this->testDir . '/test.log';
        file_put_contents($logPath, str_repeat('x', 1000));

        $rotator = new LogRotator(maxSize: 100, maxFiles: 3, compress: true);
        $rotator->rotateIfNeeded($logPath);

        $this->assertFileExists($logPath . '.1.gz');
    }

    public function testRotationShiftsBackups(): void
    {
        $logPath = $this->testDir . '/test.log';
        $rotator = new LogRotator(maxSize: 50, maxFiles: 3, compress: false);

        file_put_contents($logPath, str_repeat('a', 100));
        $rotator->rotateIfNeeded($logPath);

        file_put_contents($logPath, str_repeat('b', 100));
        $rotator->rotateIfNeeded($logPath);

        $this->assertFileExists($logPath . '.1');
        $this->assertFileExists($logPath . '.2');
    }

    public function testRotationDeletesOldBackups(): void
    {
        $logPath = $this->testDir . '/test.log';
        $rotator = new LogRotator(maxSize: 50, maxFiles: 2, compress: false);

        for ($i = 0; $i < 5; $i++) {
            file_put_contents($logPath, str_repeat((string)$i, 100));
            $rotator->rotateIfNeeded($logPath);
        }

        $this->assertFileExists($logPath . '.1');
        $this->assertFileExists($logPath . '.2');
        $this->assertFileDoesNotExist($logPath . '.3');
    }
}
