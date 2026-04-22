<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Http;

final class HttpRequest
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $server
     */
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
                if (is_scalar($value)) {
                    $headers[$headerName] = (string) $value;
                }
            }
        }

        $contentType = self::serverValueAsString('CONTENT_TYPE');
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }
        $contentLength = self::serverValueAsString('CONTENT_LENGTH');
        if ($contentLength !== null) {
            $headers['Content-Length'] = $contentLength;
        }

        if (!isset($headers['Authorization'])) {
            $authorization = self::serverValueAsString('HTTP_AUTHORIZATION');
            if ($authorization !== null) {
                $headers['Authorization'] = $authorization;
            } elseif (($user = self::serverValueAsString('PHP_AUTH_USER')) !== null) {
                $pw = self::serverValueAsString('PHP_AUTH_PW') ?? '';
                $headers['Authorization'] = 'Basic ' . base64_encode("{$user}:{$pw}");
            } elseif (($redirectAuthorization = self::serverValueAsString('REDIRECT_HTTP_AUTHORIZATION')) !== null) {
                $headers['Authorization'] = $redirectAuthorization;
            }
        }

        $body = file_get_contents('php://input') ?: '';

        return new self(
            body: $body,
            headers: $headers,
            method: is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET',
            clientIp: is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1',
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

    private static function serverValueAsString(string $key): ?string
    {
        $value = $_SERVER[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return null;
    }
}
