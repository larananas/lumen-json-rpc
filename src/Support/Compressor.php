<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Support;

final class Compressor
{
    public static function decodeGzip(string $data): ?string
    {
        if ($data === '') {
            return null;
        }

        $decoded = @gzdecode($data);
        return $decoded !== false ? $decoded : null;
    }

    public static function encodeGzip(string $data): ?string
    {
        $encoded = @gzencode($data, 6);
        return $encoded !== false ? $encoded : null;
    }

    public static function isGzipped(string $data): bool
    {
        if (strlen($data) < 2) {
            return false;
        }
        $header = substr($data, 0, 2);
        return $header === "\x1f\x8b";
    }
}
