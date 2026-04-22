<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Exception;

use Lumen\JsonRpc\Exception\ServerErrorException;
use PHPUnit\Framework\TestCase;

final class ServerErrorExceptionMutationTest extends TestCase
{
    public function testDefaultCodeIsMinus32000(): void
    {
        $e = new ServerErrorException();
        $this->assertSame(-32000, $e->getErrorCode());
    }

    public function testCustomCodeAtUpperBound(): void
    {
        $e = new ServerErrorException('msg', -32000);
        $this->assertSame(-32000, $e->getErrorCode());
    }

    public function testCustomCodeAtLowerBound(): void
    {
        $e = new ServerErrorException('msg', -32099);
        $this->assertSame(-32099, $e->getErrorCode());
    }

    public function testCustomCodeAboveUpperBoundIsClamped(): void
    {
        $e = new ServerErrorException('msg', -31999);
        $this->assertSame(-32000, $e->getErrorCode());
    }

    public function testCustomCodeBelowLowerBoundIsClamped(): void
    {
        $e = new ServerErrorException('msg', -32100);
        $this->assertSame(-32099, $e->getErrorCode());
    }

    public function testCustomCodeInRangeLowerHalf(): void
    {
        $e = new ServerErrorException('msg', -32050);
        $this->assertSame(-32050, $e->getErrorCode());
    }

    public function testCustomCodeJustInsideUpperBound(): void
    {
        $e = new ServerErrorException('msg', -32001);
        $this->assertSame(-32001, $e->getErrorCode());
    }

    public function testCustomCodeJustInsideLowerBound(): void
    {
        $e = new ServerErrorException('msg', -32098);
        $this->assertSame(-32098, $e->getErrorCode());
    }

    public function testDefaultMessage(): void
    {
        $e = new ServerErrorException();
        $this->assertSame('Server error', $e->getErrorMessage());
    }
}
