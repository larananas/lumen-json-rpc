<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Support;

final class Compressor
{
    private static ?bool $zlibAvailable = null;

    public static function isZlibAvailable(): bool
    {
        if (self::$zlibAvailable === null) {
            self::$zlibAvailable = function_exists('gzdecode') && function_exists('gzencode');
        }
        return self::$zlibAvailable;
    }

    public static function decodeGzip(string $data): ?string
    {
        if ($data === '') {
            return null;
        }

        if (!self::isZlibAvailable()) {
            return null;
        }

        $decoded = @gzdecode($data);
        return $decoded !== false ? $decoded : null;
    }

    public static function encodeGzip(string $data): ?string
    {
        if (!self::isZlibAvailable()) {
            return null;
        }

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
