<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Http;

final class HttpResponse
{
    public function __construct(
        public readonly string $body,
        public readonly int $statusCode = 200,
        public readonly array $headers = [],
    ) {}

    public static function json(string $json, int $statusCode = 200, array $extraHeaders = []): self
    {
        return new self(
            body: $json,
            statusCode: $statusCode,
            headers: array_merge(['Content-Type' => 'application/json'], $extraHeaders),
        );
    }

    public static function noContent(): self
    {
        return new self(body: '', statusCode: 204, headers: []);
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->body;
    }
}
