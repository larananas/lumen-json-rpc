<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Http;

final class HttpRequest
{
    public function __construct(
        public readonly string $body,
        public readonly array $headers,
        public readonly string $method,
        public readonly string $clientIp,
        public readonly array $server,
    ) {}

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$headerName] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        }

        if (!isset($headers['Authorization'])) {
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['PHP_AUTH_USER'])) {
                $pw = $_SERVER['PHP_AUTH_PW'] ?? '';
                $headers['Authorization'] = 'Basic ' . base64_encode("{$_SERVER['PHP_AUTH_USER']}:{$pw}");
            } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            }
        }

        $body = file_get_contents('php://input') ?: '';

        return new self(
            body: $body,
            headers: $headers,
            method: $_SERVER['REQUEST_METHOD'] ?? 'GET',
            clientIp: $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            server: $_SERVER,
        );
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function getHeaderCaseInsensitive(string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $lower) {
                return $value;
            }
        }
        return null;
    }

    public function getAuthorizationHeader(): ?string
    {
        return $this->getHeaderCaseInsensitive('Authorization');
    }

    public function isGzipped(): bool
    {
        $contentEncoding = $this->getHeaderCaseInsensitive('Content-Encoding');
        return $contentEncoding !== null && stripos($contentEncoding, 'gzip') !== false;
    }
}
