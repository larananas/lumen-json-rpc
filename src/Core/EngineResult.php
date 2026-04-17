<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Core;

final class EngineResult
{
    public function __construct(
        public readonly ?string $json,
        public readonly int $statusCode = 200,
        public readonly array $headers = [],
    ) {}

    public function isNoContent(): bool
    {
        return $this->json === null;
    }
}
