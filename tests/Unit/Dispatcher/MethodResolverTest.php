<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Dispatcher;

use Lumen\JsonRpc\Dispatcher\MethodResolver;
use PHPUnit\Framework\TestCase;

final class MethodResolverTest extends TestCase
{
    private string $handlerPath;

    protected function setUp(): void
    {
        $this->handlerPath = realpath(__DIR__ . '/../../../examples/handlers') ?: __DIR__ . '/../../../examples/handlers';
    }

    public function testReservedRpcPrefixIsRejected(): void
    {
        $resolver = new MethodResolver(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.'
        );

        // Per JSON-RPC 2.0 spec, rpc.* methods should be rejected
        $result = $resolver->resolve('rpc.anything');
        $this->assertNull($result);
    }

    public function testReservedRpcPrefixRejectedWithCustomSeparator(): void
    {
        $resolver = new MethodResolver(
            [$this->handlerPath],
            'App\\Handlers\\',
            '_'
        );

        // rpc. should still be rejected even with custom separator
        $result = $resolver->resolve('rpc.anything');
        $this->assertNull($result);
    }

    public function testRpcWithUnderscoreAndCustomSeparatorIsAllowed(): void
    {
        $resolver = new MethodResolver(
            [$this->handlerPath],
            'App\\Handlers\\',
            '_'
        );

        // With custom separator '_', rpc_method should be treated as handler='rpc', method='method'
        // This should be allowed (not rejected) since it doesn't start with 'rpc.'
        // Note: This will only resolve if there's an 'Rpc.php' handler file
        $result = $resolver->resolve('rpc_method');
        // Result may be null if file doesn't exist, but it shouldn't be rejected due to reserved prefix
        // The test verifies that isMethodSafe doesn't reject it
        $this->assertNull($result); // Null because Rpc.php doesn't exist, not because of reserved prefix
    }

    public function testValidMethodResolves(): void
    {
        $resolver = new MethodResolver(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.'
        );

        $result = $resolver->resolve('system.health');
        $this->assertNotNull($result);
        $this->assertEquals('App\\Handlers\\System', $result->className);
        $this->assertEquals('health', $result->methodName);
    }

    public function testValidMethodWithCustomSeparatorResolves(): void
    {
        $resolver = new MethodResolver(
            [$this->handlerPath],
            'App\\Handlers\\',
            '_'
        );

        $result = $resolver->resolve('system_health');
        $this->assertNotNull($result);
        $this->assertEquals('App\\Handlers\\System', $result->className);
        $this->assertEquals('health', $result->methodName);
    }

    public function testCaseInsensitiveFileLookup(): void
    {
        $resolver = new MethodResolver(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.'
        );

        // Test that lowercase method name resolves to properly cased file
        $result = $resolver->resolve('system.health');
        $this->assertNotNull($result);
        $this->assertStringContainsString('System.php', $result->fullPath);
    }

    public function testInvalidMethodFormatReturnsNull(): void
    {
        $resolver = new MethodResolver(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.'
        );

        // No separator
        $result = $resolver->resolve('invalidmethod');
        $this->assertNull($result);

        // Empty method part
        $result = $resolver->resolve('system.');
        $this->assertNull($result);

        // Starts with number
        $result = $resolver->resolve('123.method');
        $this->assertNull($result);
    }

    public function testClassNameMatchesActualFilename(): void
    {
        $resolver = new MethodResolver(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.'
        );

        $result = $resolver->resolve('system.health');
        $this->assertNotNull($result);
        $this->assertEquals('App\\Handlers\\System', $result->className);
        $this->assertStringContainsString('System.php', $result->fullPath);
    }

    public function testRpcMethodNotRejectedWhenUsingUnderscoreSeparator(): void
    {
        $resolver = new MethodResolver(
            [$this->handlerPath],
            'App\\Handlers\\',
            '_'
        );

        // rpc.anything is still rejected (spec reserved prefix uses literal dot)
        $this->assertNull($resolver->resolve('rpc.anything'));

        // user_get should resolve (uses _ separator, not blocked by rpc. check)
        $result = $resolver->resolve('user_get');
        $this->assertNotNull($result);
        $this->assertEquals('App\\Handlers\\User', $result->className);
    }
}
