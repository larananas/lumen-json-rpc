<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Log;

use Lumen\JsonRpc\Log\LogRotator;
use PHPUnit\Framework\TestCase;

final class LogRotatorExtendedTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/jsonrpc_rotator_ext_' . uniqid();
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

    public function testNonExistentFileDoesNothing(): void
    {
        $rotator = new LogRotator(maxSize: 1);
        $rotator->rotateIfNeeded($this->testDir . '/nonexistent.log');
        $this->assertFileDoesNotExist($this->testDir . '/nonexistent.log');
        $this->assertFileDoesNotExist($this->testDir . '/nonexistent.log.1');
    }

    public function testFileBelowThresholdDoesNotRotate(): void
    {
        $logPath = $this->testDir . '/test.log';
        file_put_contents($logPath, 'small');

        $rotator = new LogRotator(maxSize: 1000);
        $rotator->rotateIfNeeded($logPath);

        $content = file_get_contents($logPath);
        $this->assertEquals('small', $content);
        $this->assertFileDoesNotExist($logPath . '.1.gz');
    }

    public function testCompressedRotationCreatesGzFile(): void
    {
        $logPath = $this->testDir . '/test.log';
        file_put_contents($logPath, str_repeat('a', 500));

        $rotator = new LogRotator(maxSize: 100, maxFiles: 3, compress: true);
        $rotator->rotateIfNeeded($logPath);

        $this->assertFileExists($logPath . '.1.gz');
        $compressed = file_get_contents($logPath . '.1.gz');
        $this->assertNotEmpty($compressed);

        $decompressed = @gzdecode($compressed);
        $this->assertNotEmpty($decompressed);
        $this->assertStringContainsString(str_repeat('a', 500), $decompressed);
    }

    public function testUncompressedRotationRenameFile(): void
    {
        $logPath = $this->testDir . '/test.log';
        $content = str_repeat('b', 200);
        file_put_contents($logPath, $content);

        $rotator = new LogRotator(maxSize: 100, maxFiles: 3, compress: false);
        $rotator->rotateIfNeeded($logPath);

        $this->assertFileExists($logPath . '.1');
        $backup = file_get_contents($logPath . '.1');
        $this->assertEquals($content, $backup);

        $mainContent = file_get_contents($logPath);
        $this->assertEquals('', $mainContent);
    }

    public function testMultipleRotationsShiftFiles(): void
    {
        $logPath = $this->testDir . '/test.log';
        $rotator = new LogRotator(maxSize: 10, maxFiles: 3, compress: false);

        file_put_contents($logPath, str_repeat('a', 50));
        $rotator->rotateIfNeeded($logPath);

        file_put_contents($logPath, str_repeat('b', 50));
        $rotator->rotateIfNeeded($logPath);

        file_put_contents($logPath, str_repeat('c', 50));
        $rotator->rotateIfNeeded($logPath);

        $this->assertFileExists($logPath . '.1');
        $this->assertFileExists($logPath . '.2');
        $this->assertFileExists($logPath . '.3');
    }

    public function testOldestBackupDeleted(): void
    {
        $logPath = $this->testDir . '/test.log';
        $rotator = new LogRotator(maxSize: 10, maxFiles: 2, compress: false);

        for ($i = 0; $i < 5; $i++) {
            file_put_contents($logPath, str_repeat((string)$i, 50));
            $rotator->rotateIfNeeded($logPath);
        }

        $this->assertFileExists($logPath . '.1');
        $this->assertFileExists($logPath . '.2');
        $this->assertFileDoesNotExist($logPath . '.3');
        $this->assertFileDoesNotExist($logPath . '.4');
    }

    public function testCompressedRotationWithMultipleShifts(): void
    {
        $logPath = $this->testDir . '/test.log';
        $rotator = new LogRotator(maxSize: 50, maxFiles: 2, compress: true);

        for ($i = 0; $i < 3; $i++) {
            file_put_contents($logPath, str_repeat((string)$i, 200));
            $rotator->rotateIfNeeded($logPath);
        }

        $this->assertFileExists($logPath . '.1.gz');
        $this->assertFileExists($logPath . '.2.gz');
        $this->assertFileDoesNotExist($logPath . '.3.gz');
    }

    public function testLogPathWithEmptyFileAfterRotation(): void
    {
        $logPath = $this->testDir . '/test.log';
        file_put_contents($logPath, str_repeat('x', 200));

        $rotator = new LogRotator(maxSize: 100, maxFiles: 2, compress: false);
        $rotator->rotateIfNeeded($logPath);

        $this->assertSame('', file_get_contents($logPath));
    }

    public function testExactSizeBoundaryTriggersRotation(): void
    {
        $logPath = $this->testDir . '/test.log';
        $content = str_repeat('a', 100);
        file_put_contents($logPath, $content);

        $rotator = new LogRotator(maxSize: 100, maxFiles: 3, compress: false);
        $rotator->rotateIfNeeded($logPath);

        $this->assertFileExists($logPath . '.1');
    }

    public function testDefaultConstructorCompressProducesGzExtension(): void
    {
        $logPath = $this->testDir . '/test.log';
        file_put_contents($logPath, str_repeat('x', 10_485_761));

        $rotator = new LogRotator();
        $rotator->rotateIfNeeded($logPath);

        $this->assertFileExists($logPath . '.1.gz');
        $this->assertFileDoesNotExist($logPath . '.1');
    }
}
