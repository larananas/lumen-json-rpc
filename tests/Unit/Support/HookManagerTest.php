<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Support;

use Lumen\JsonRpc\Support\HookManager;
use Lumen\JsonRpc\Support\HookPoint;
use PHPUnit\Framework\TestCase;

final class HookManagerTest extends TestCase
{
    private HookManager $hooks;

    protected function setUp(): void
    {
        $this->hooks = new HookManager();
    }

    public function testRegisterAndFireHook(): void
    {
        $called = false;
        $this->hooks->register(HookPoint::BEFORE_HANDLER, function () use (&$called) {
            $called = true;
            return [];
        });

        $this->hooks->fire(HookPoint::BEFORE_HANDLER);
        $this->assertTrue($called);
    }

    public function testFireReturnsMergedContext(): void
    {
        $this->hooks->register(HookPoint::BEFORE_HANDLER, function (array $ctx) {
            return ['added' => 'value'];
        });

        $result = $this->hooks->fire(HookPoint::BEFORE_HANDLER, ['initial' => 'data']);
        $this->assertEquals(['initial' => 'data', 'added' => 'value'], $result);
    }

    public function testMultipleHooksExecuteInOrder(): void
    {
        $order = [];
        $this->hooks->register(HookPoint::BEFORE_HANDLER, function () use (&$order) {
            $order[] = 1;
            return [];
        }, 0);
        $this->hooks->register(HookPoint::BEFORE_HANDLER, function () use (&$order) {
            $order[] = 2;
            return [];
        }, 1);

        $this->hooks->fire(HookPoint::BEFORE_HANDLER);
        $this->assertEquals([1, 2], $order);
    }

    public function testHasHooksReturnsCorrectState(): void
    {
        $this->assertFalse($this->hooks->hasHooks(HookPoint::BEFORE_HANDLER));
        $this->hooks->register(HookPoint::BEFORE_HANDLER, fn () => []);
        $this->assertTrue($this->hooks->hasHooks(HookPoint::BEFORE_HANDLER));
    }

    public function testFireWithNoHooksReturnsOriginalContext(): void
    {
        $result = $this->hooks->fire(HookPoint::ON_ERROR, ['test' => true]);
        $this->assertEquals(['test' => true], $result);
    }
}
