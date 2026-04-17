<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Fixtures;

final class HandlerNoConstructor
{
    public function testMethod(): string
    {
        return 'no-constructor';
    }
}
