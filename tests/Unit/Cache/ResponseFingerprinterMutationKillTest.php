<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Cache;

use Lumen\JsonRpc\Cache\ResponseFingerprinter;
use PHPUnit\Framework\TestCase;

final class ResponseFingerprinterMutationKillTest extends TestCase
{
    public function testDefaultConstructorHasEnabledFalse(): void
    {
        $fp = new ResponseFingerprinter();
        $this->assertFalse($fp->isEnabled());
    }
}
