<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Config;

use Lumen\JsonRpc\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigMutationTest extends TestCase
{
    public function testSetNestedKeyOverwritesExistingNonArrayValue(): void
    {
        $config = new Config(['a' => 'string_value']);
        $config->set('a.b', 'nested');
        $this->assertSame('nested', $config->get('a.b'));
        $this->assertIsArray($config->get('a'));
    }

    public function testSetNestedKeyCreatesIntermediateArrays(): void
    {
        $config = new Config([]);
        $config->set('x.y.z', 'deep');
        $this->assertSame('deep', $config->get('x.y.z'));
        $this->assertIsArray($config->get('x'));
        $this->assertIsArray($config->get('x.y'));
    }

    public function testSetNestedKeyPreservesExistingArrayIntermediate(): void
    {
        $config = new Config(['a' => ['existing' => 'value']]);
        $config->set('a.new_key', 'new_value');
        $this->assertSame('value', $config->get('a.existing'));
        $this->assertSame('new_value', $config->get('a.new_key'));
    }

    public function testSetDeepNestedKeyWithPath(): void
    {
        $config = new Config([]);
        $config->set('level1.level2.level3.level4', 'deep_value');
        $this->assertSame('deep_value', $config->get('level1.level2.level3.level4'));
        $this->assertNull($config->get('level1.level2.level3.nonexistent'));
    }

    public function testSetOverwritesIntermediateStringWithArray(): void
    {
        $config = new Config(['parent' => 'not_array']);
        $config->set('parent.child', 'value');
        $this->assertSame('value', $config->get('parent.child'));
        $this->assertNull($config->get('parent.nonexistent'));
    }

    public function testGetReturnsNullForMissingNestedKey(): void
    {
        $config = new Config([]);
        $this->assertNull($config->get('does.not.exist'));
        $this->assertSame('default', $config->get('does.not.exist', 'default'));
    }

    public function testSetTopLevelKey(): void
    {
        $config = new Config([]);
        $config->set('top', 'value');
        $this->assertSame('value', $config->get('top'));
    }
}
