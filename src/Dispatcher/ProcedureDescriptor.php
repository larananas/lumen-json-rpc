<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Dispatcher;

final class ProcedureDescriptor
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $method,
        public readonly string $handlerClass,
        public readonly string $handlerMethod,
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'handlerClass' => $this->handlerClass,
            'handlerMethod' => $this->handlerMethod,
            'metadata' => $this->metadata,
        ];
    }
}
