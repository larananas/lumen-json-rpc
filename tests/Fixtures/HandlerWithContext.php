<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Fixtures;

use Lumen\JsonRpc\Support\RequestContext;

final class HandlerWithContext
{
    public function __construct(
        private readonly RequestContext $context,
    ) {}

    public function testMethod(): string
    {
        return 'with-context';
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }
}
