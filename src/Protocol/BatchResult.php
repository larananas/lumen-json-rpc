<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Protocol;

/**
 * @internal
 */
final class BatchResult
{
    /**
     * @param array<int, Request> $requests
     * @param array<int, Response> $errors
     */
    public function __construct(
        public readonly array $requests,
        public readonly array $errors,
        public readonly bool $isBatch,
    ) {}

    public static function singleRequest(Request $request): self
    {
        return new self([$request], [], false);
    }

    public static function singleError(Response $error): self
    {
        return new self([], [$error], false);
    }

    public function hasRequests(): bool
    {
        return !empty($this->requests);
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function hasOnlyNotifications(): bool
    {
        foreach ($this->requests as $request) {
            if (!$request->isNotification) {
                return false;
            }
        }
        return !empty($this->requests);
    }
}
