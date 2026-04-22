<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Config;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Config\Defaults;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaultConfigIsLoaded(): void
    {
        $config = new Config();
        $this->assertFalse($config->get('auth.enabled'));
        $this->assertTrue($config->get('logging.enabled'));
        $this->assertEquals('.', $config->get('handlers.method_separator'));
    }

    public function testCustomConfigOverridesDefaults(): void
    {
        $config = new Config(['debug' => true]);
        $this->assertTrue($config->get('debug'));
    }

    public function testNestedConfigOverrides(): void
    {
        $config = new Config([
            'auth' => ['enabled' => true, 'jwt' => ['secret' => 'test-secret']],
        ]);
        $this->assertTrue($config->get('auth.enabled'));
        $this->assertEquals('test-secret', $config->get('auth.jwt.secret'));
        $this->assertEquals('HS256', $config->get('auth.jwt.algorithm'));
    }

    public function testGetReturnsDefaultWhenKeyNotFound(): void
    {
        $config = new Config();
        $this->assertNull($config->get('nonexistent'));
        $this->assertEquals('default', $config->get('nonexistent', 'default'));
    }

    public function testSetUpdatesConfig(): void
    {
        $config = new Config();
        $config->set('debug', true);
        $this->assertTrue($config->get('debug'));
    }

    public function testSetNestedKey(): void
    {
        $config = new Config();
        $config->set('auth.jwt.secret', 'new-secret');
        $this->assertEquals('new-secret', $config->get('auth.jwt.secret'));
    }

    public function testAllReturnsFullConfig(): void
    {
        $config = new Config(['custom' => 'value']);
        $all = $config->all();
        $this->assertArrayHasKey('handlers', $all);
        $this->assertEquals('value', $all['custom']);
    }

    public function testDefaultsReturnsCompleteConfig(): void
    {
        $defaults = Defaults::all();
        $this->assertArrayHasKey('handlers', $defaults);
        $this->assertArrayHasKey('auth', $defaults);
        $this->assertArrayHasKey('logging', $defaults);
        $this->assertArrayHasKey('rate_limit', $defaults);
        $this->assertArrayHasKey('batch', $defaults);
        $this->assertArrayHasKey('debug', $defaults);
        $this->assertArrayHasKey('content_type', $defaults);
    }

    public function testAuthProtectedMethodsDefaultIsEmpty(): void
    {
        $defaults = Defaults::all();
        $this->assertSame([], $defaults['auth']['protected_methods']);
    }

    public function testFromFileThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configuration file not found');
        Config::fromFile('/nonexistent/path/config.php');
    }

    public function testFromFileThrowsOnNonArrayReturn(): void
    {
        $tmpFile = sys_get_temp_dir() . '/jsonrpc_config_test_' . uniqid() . '.php';
        file_put_contents($tmpFile, "<?php return 'not-array';");
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('must return an array');
            Config::fromFile($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testFromFileLoadsValidConfig(): void
    {
        $tmpFile = sys_get_temp_dir() . '/jsonrpc_config_valid_' . uniqid() . '.php';
        file_put_contents($tmpFile, "<?php return ['debug' => true];");
        try {
            $config = Config::fromFile($tmpFile);
            $this->assertTrue($config->get('debug'));
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testDefaultsIncludeFailOpen(): void
    {
        $defaults = Defaults::all();
        $this->assertFalse($defaults['rate_limit']['fail_open']);
    }

    public function testDefaultsIncludeContentTypeStrict(): void
    {
        $defaults = Defaults::all();
        $this->assertFalse($defaults['content_type']['strict']);
    }
}
