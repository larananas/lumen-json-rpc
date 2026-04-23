<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\RateLimit;

use Lumen\JsonRpc\RateLimit\FileRateLimiter;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
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

  #[WithoutErrorHandler]
  public function testFailOpenOnBadPath(): void
  {
    $blockingPath = $this->createBlockingStoragePath('fail-open');

    $limiter = new FileRateLimiter(
      10,
      60,
      $blockingPath,
      failOpen: true,
    );

    $warnings = [];

    set_error_handler(static function (int $severity, string $message) use (
      &$warnings,
    ): bool {
      if ($severity === E_USER_WARNING) {
        $warnings[] = $message;
        return true;
      }

      return false;
    });

    try {
      $result = $limiter->check("fail-open-test");
    } finally {
      restore_error_handler();
    }

    $this->assertTrue($result->allowed);
    $this->assertNotEmpty($warnings);
    $this->assertStringContainsString(
      "fail-open allowing request",
      $warnings[0],
    );
  }

  #[WithoutErrorHandler]
  public function testFailClosedOnBadPath(): void
  {
    $blockingPath = $this->createBlockingStoragePath('fail-closed');

    $limiter = new FileRateLimiter(
      10,
      60,
      $blockingPath,
      failOpen: false,
    );

    $warnings = [];

    set_error_handler(static function (int $severity, string $message) use (
      &$warnings,
    ): bool {
      if ($severity === E_USER_WARNING) {
        $warnings[] = $message;
        return true;
      }

      return false;
    });

    try {
      $result = $limiter->check("fail-closed-test");
    } finally {
      restore_error_handler();
    }

    $this->assertFalse($result->allowed);
    $this->assertNotEmpty($warnings);
    $this->assertStringContainsString(
      "fail-closed denying request",
      $warnings[0],
    );
  }

  private function createBlockingStoragePath(string $prefix): string
  {
    $path = $this->testDir . '/' . $prefix . '-' . uniqid('', true) . '.tmp';
    touch($path);

    return $path;
  }

  #[WithoutErrorHandler]
  public function testFailOpenRemainingIsMaxRequestsMinusOne(): void
  {
    $blockingPath = $this->createBlockingStoragePath('remaining-test');

    $limiter = new FileRateLimiter(
      10,
      60,
      $blockingPath,
      failOpen: true,
    );

    set_error_handler(static function (int $severity): bool {
      return $severity === E_USER_WARNING;
    });

    try {
      $result = $limiter->check("remaining-test");
    } finally {
      restore_error_handler();
    }

    $this->assertTrue($result->allowed);
    $this->assertSame(9, $result->remaining);
    $this->assertSame(10, $result->limit);
  }
}
