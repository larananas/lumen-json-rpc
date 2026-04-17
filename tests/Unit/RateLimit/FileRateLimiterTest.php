<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\RateLimit;

use Lumen\JsonRpc\RateLimit\FileRateLimiter;
use PHPUnit\Framework\TestCase;

final class FileRateLimiterTest extends TestCase
{
  private string $testDir;
  private FileRateLimiter $limiter;

  protected function setUp(): void
  {
    $this->testDir = sys_get_temp_dir() . "/jsonrpc_test_rate_" . uniqid();
    mkdir($this->testDir, 0755, true);
    $this->limiter = new FileRateLimiter(3, 60, $this->testDir);
  }

  protected function tearDown(): void
  {
    $files = glob($this->testDir . "/*") ?: [];
    foreach ($files as $file) {
      @unlink($file);
    }
    @rmdir($this->testDir);
  }

  public function testFirstRequestIsAllowed(): void
  {
    $result = $this->limiter->check("test-key");
    $this->assertTrue($result->allowed);
    $this->assertEquals(2, $result->remaining);
  }

  public function testLimitIsEnforced(): void
  {
    $this->limiter->check("test-key");
    $this->limiter->check("test-key");
    $this->limiter->check("test-key");

    $result = $this->limiter->check("test-key");
    $this->assertFalse($result->allowed);
    $this->assertEquals(0, $result->remaining);
  }

  public function testDifferentKeysAreIndependent(): void
  {
    $result1 = $this->limiter->check("key-a");
    $result2 = $this->limiter->check("key-b");
    $this->assertTrue($result1->allowed);
    $this->assertTrue($result2->allowed);
  }

  public function testKeyIsSanitized(): void
  {
    $result = $this->limiter->check("192.168.1.1");
    $this->assertTrue($result->allowed);
  }

  public function testCheckAndConsumeAtomicWeight(): void
  {
    $limiter = new FileRateLimiter(10, 60, $this->testDir);
    $result = $limiter->checkAndConsume("weight-test", 5);
    $this->assertTrue($result->allowed);
    $this->assertEquals(5, $result->remaining);

    $result2 = $limiter->checkAndConsume("weight-test", 5);
    $this->assertTrue($result2->allowed);
    $this->assertEquals(0, $result2->remaining);

    $result3 = $limiter->checkAndConsume("weight-test", 1);
    $this->assertFalse($result3->allowed);
  }

  public function testCheckAndConsumeRefusalDoesNotConsumeQuota(): void
  {
    $limiter = new FileRateLimiter(5, 60, $this->testDir);

    $result = $limiter->checkAndConsume("atomic-test", 4);
    $this->assertTrue($result->allowed);
    $this->assertEquals(1, $result->remaining);

    $result2 = $limiter->checkAndConsume("atomic-test", 3);
    $this->assertFalse($result2->allowed);

    $result3 = $limiter->checkAndConsume("atomic-test", 1);
    $this->assertTrue($result3->allowed);
    $this->assertEquals(0, $result3->remaining);
  }

  public function testFailOpenOnBadPath(): void
  {
    $limiter = new FileRateLimiter(
      10,
      60,
      "/nonexistent/path/that/cannot/be/created/" . uniqid(),
      failOpen: true,
    );

    $result = @$limiter->check("fail-open-test");

    $this->assertTrue($result->allowed);
  }

  public function testFailClosedOnBadPath(): void
  {
    if (PHP_OS_FAMILY === "Windows") {
      $this->markTestSkipped(
        "Fail-closed behavior cannot be reliably tested on Windows due to POSIX permission differences",
      );
    }

    $readonlyDir = sys_get_temp_dir() . "/jsonrpc_readonly_closed_" . uniqid();
    $subdir = $readonlyDir . "/subdir";

    mkdir($subdir, 0755, true);
    chmod($subdir, 0555);

    $limiter = new FileRateLimiter(10, 60, $subdir . "/deep", failOpen: false);
    $result = @$limiter->check("fail-closed-test");

    chmod($subdir, 0755);
    @rmdir($subdir . "/deep");
    @rmdir($subdir);
    @rmdir($readonlyDir);

    $this->assertFalse($result->allowed);
  }
}
