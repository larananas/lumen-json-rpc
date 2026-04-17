<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Fixtures;

final class HandlerOptionalParam
{
    // Constructor with optional parameter
    public function __construct(
        private readonly string $optional = 'default',
    ) {}

    public function testMethod(): string
    {
        return 'optional-param';
    }
}
