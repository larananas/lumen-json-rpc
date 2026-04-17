<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Doc;

final class MethodDoc
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly array $params = [],
        public readonly ?string $returnType = null,
        public readonly string $returnDescription = '',
        public readonly bool $requiresAuth = false,
        public readonly array $errors = [],
        public readonly ?string $exampleRequest = null,
        public readonly ?string $exampleResponse = null,
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'params' => $this->params,
            'returnType' => $this->returnType,
            'returnDescription' => $this->returnDescription,
            'requiresAuth' => $this->requiresAuth,
            'errors' => $this->errors,
            'exampleRequest' => $this->exampleRequest,
            'exampleResponse' => $this->exampleResponse,
        ];
    }
}
