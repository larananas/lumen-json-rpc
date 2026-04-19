<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Support;

use Lumen\JsonRpc\Support\CorrelationId;
use PHPUnit\Framework\TestCase;

final class CorrelationIdTest extends TestCase
{
    public function testGenerateReturns32CharacterHexString(): void
    {
        $id = CorrelationId::generate();
        $this->assertIsString($id);
        $this->assertEquals(32, strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function testGenerateProducesUniqueIds(): void
    {
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = CorrelationId::generate();
        }
        $unique = array_unique($ids);
        $this->assertCount(100, $unique);
    }
}
