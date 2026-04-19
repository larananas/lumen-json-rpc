<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Dispatcher;

/**
 * @internal
 */
final class MethodResolution
{
    public function __construct(
        public readonly string $className,
        public readonly string $methodName,
        public readonly string $fullPath,
    ) {}
}
