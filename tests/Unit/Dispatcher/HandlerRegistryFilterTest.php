<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Dispatcher;

use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use PHPUnit\Framework\TestCase;

final class HandlerRegistryFilterTest extends TestCase
{
    private string $handlerPath;

    protected function setUp(): void
    {
        $this->handlerPath = realpath(__DIR__ . '/../../../examples/handlers') ?: __DIR__ . '/../../../examples/handlers';
    }

    public function testDiscoverFindsPublicMethodsOnly(): void
    {
        $registry = new HandlerRegistry(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.',
        );
        $handlers = $registry->discover();
        $this->assertNotEmpty($handlers, 'Should discover at least one handler');

        foreach ($handlers as $method => $info) {
            $parts = explode('.', $method);
            $this->assertFalse(
                str_starts_with($parts[1], '__'),
                "Magic method should not be discovered: $method"
            );
        }
    }

    public function testDiscoverExcludesSetRegistry(): void
    {
        $registry = new HandlerRegistry(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.',
        );
        $handlers = $registry->discover();
        $methods = array_keys($handlers);

        $this->assertNotContains('system.setRegistry', $methods, 'setRegistry should not be discoverable');
    }

    public function testDiscoverUsesLowercasePrefix(): void
    {
        $registry = new HandlerRegistry(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.',
        );
        $handlers = $registry->discover();
        $methods = array_keys($handlers);

        $this->assertContains('system.health', $methods);
        $this->assertContains('user.get', $methods);
        $this->assertContains('order.get', $methods);
    }

    public function testDiscoverWithCustomSeparator(): void
    {
        $registry = new HandlerRegistry(
            [$this->handlerPath],
            'App\\Handlers\\',
            '_',
        );
        $handlers = $registry->discover();
        $methods = array_keys($handlers);

        $this->assertContains('system_health', $methods);
        $this->assertContains('user_get', $methods);
        $this->assertNotContains('system.health', $methods);
    }

    public function testDiscoverSkipsNonInstantiableClasses(): void
    {
        $registry = new HandlerRegistry(
            [$this->handlerPath],
            'NonExistent\\Namespace\\',
            '.',
        );
        $handlers = $registry->discover();
        $this->assertEmpty($handlers);
    }

    public function testDiscoverSkipsInvalidPath(): void
    {
        $registry = new HandlerRegistry(
            ['/nonexistent/path'],
            'App\\Handlers\\',
            '.',
        );
        $handlers = $registry->discover();
        $this->assertEmpty($handlers);
    }

    public function testGetHandlersReturnsDiscoveredHandlers(): void
    {
        $registry = new HandlerRegistry(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.',
        );
        $registry->discover();

        $handlers = $registry->getHandlers();
        $this->assertNotEmpty($handlers);
        $this->assertArrayHasKey('system.health', $handlers);
        $this->assertEquals('App\\Handlers\\System', $handlers['system.health']['class']);
        $this->assertEquals('health', $handlers['system.health']['method']);
    }

    public function testGetMethodNamesReturnsKeys(): void
    {
        $registry = new HandlerRegistry(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.',
        );
        $registry->discover();

        $names = $registry->getMethodNames();
        $this->assertContains('system.health', $names);
        $this->assertContains('user.get', $names);
        $this->assertContains('order.get', $names);
    }

    public function testDiscoverReplacesPreviousHandlers(): void
    {
        $registry = new HandlerRegistry(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.',
        );

        $first = $registry->discover();
        $second = $registry->discover();

        $this->assertEquals(array_keys($first), array_keys($second));
    }

    public function testDiscoverOnlyListsDeclaredMethodsNotInherited(): void
    {
        $registry = new HandlerRegistry(
            [$this->handlerPath],
            'App\\Handlers\\',
            '.',
        );
        $handlers = $registry->discover();
        $this->assertNotEmpty($handlers);

        foreach ($handlers as $method => $info) {
            $this->assertEquals(
                $info['class'],
                $handlers[$method]['class'],
                "Method $method should be declared on the handler class itself"
            );
        }
    }
}
