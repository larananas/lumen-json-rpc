<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Core;

use Lumen\JsonRpc\Core\EngineResult;
use PHPUnit\Framework\TestCase;

final class EngineResultMutationKillTest extends TestCase
{
    public function testDefaultStatusCodeIs200(): void
    {
        $result = new EngineResult(json: '{"result":1}');
        $this->assertSame(200, $result->statusCode);
    }

    public function testDefaultHeadersIsEmptyArray(): void
    {
        $result = new EngineResult(json: '{}');
        $this->assertSame([], $result->headers);
    }

    public function testIsNoContentReturnsTrueWhenJsonIsNull(): void
    {
        $result = new EngineResult(json: null);
        $this->assertTrue($result->isNoContent());
    }

    public function testIsNoContentReturnsFalseWhenJsonIsString(): void
    {
        $result = new EngineResult(json: '{}');
        $this->assertFalse($result->isNoContent());
    }
}
