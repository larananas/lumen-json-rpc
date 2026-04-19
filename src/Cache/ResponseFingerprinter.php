<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Cache;

final class ResponseFingerprinter
{
    public function __construct(
        private readonly bool $enabled = false,
        private readonly string $algorithm = 'sha256',
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function fingerprint(mixed $data): string
    {
        $canonical = $this->canonicalize($data);
        $json = json_encode($canonical, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        return hash($this->algorithm, $json);
    }

    private function canonicalize(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = $this->canonicalize($value);
        }
        ksort($result);
        return $result;
    }

    public function isUnchanged(mixed $data, string $knownFingerprint): bool
    {
        return $this->fingerprint($data) === $knownFingerprint;
    }
}
