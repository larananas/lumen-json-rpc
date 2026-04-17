<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Http;

final class RequestReader
{
    public function __construct(
        private readonly int $maxBodySize = 1_048_576,
        private readonly bool $requestGzipEnabled = true,
    ) {}

    public function read(HttpRequest $request): string
    {
        $body = $request->body;

        if (strlen($body) > $this->maxBodySize) {
            return '';
        }

        if ($this->requestGzipEnabled && $request->isGzipped()) {
            if (!function_exists('gzdecode')) {
                return '';
            }
            $decoded = @gzdecode($body);
            if ($decoded === false) {
                return '';
            }
            if (strlen($decoded) > $this->maxBodySize) {
                return '';
            }
            $body = $decoded;
        }

        return $body;
    }
}
