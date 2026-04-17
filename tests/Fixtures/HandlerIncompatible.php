<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Fixtures;

final class HandlerIncompatible
{
    // Constructor requires a non-optional parameter that is not RequestContext
    public function __construct(
        private readonly string $requiredParam,
    ) {}

    public function testMethod(): string
    {
        return 'incompatible';
    }
}
