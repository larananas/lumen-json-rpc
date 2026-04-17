<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Protocol;

final class BatchProcessor
{
    public function __construct(
        private readonly RequestValidator $validator,
        private readonly int $maxItems = 100,
    ) {}

    public function process(mixed $decoded, bool $rawIsObject = false): BatchResult
    {
        if ($decoded === null) {
            return BatchResult::singleError(
                Response::error(null, Error::parseError())
            );
        }

        if (!is_array($decoded)) {
            return BatchResult::singleError(
                Response::error(null, Error::invalidRequest())
            );
        }

        if (empty($decoded) && !$rawIsObject) {
            return BatchResult::singleError(
                Response::error(null, Error::invalidRequest('Empty batch'))
            );
        }

        if ($rawIsObject || $this->isAssociative($decoded)) {
            $validationError = $this->validator->validateArray($decoded);
            if ($validationError !== null) {
                $id = array_key_exists('id', $decoded) ? Request::sanitizeId($decoded['id']) : null;
                return BatchResult::singleError(Response::error($id, $validationError));
            }
            return BatchResult::singleRequest(Request::fromArray($decoded));
        }

        return $this->processBatch($decoded);
    }

    private function processBatch(array $items): BatchResult
    {
        if (count($items) > $this->maxItems) {
            return BatchResult::singleError(
                Response::error(null, Error::invalidRequest(
                    'Batch exceeds maximum of ' . $this->maxItems . ' items'
                ))
            );
        }

        $requests = [];
        $errors = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $errors[] = Response::error(null, Error::invalidRequest());
                continue;
            }

            $validationError = $this->validator->validateArray($item);
            if ($validationError !== null) {
                $id = array_key_exists('id', $item) ? Request::sanitizeId($item['id']) : null;
                $errors[] = Response::error($id, $validationError);
                continue;
            }

            $requests[] = Request::fromArray($item);
        }

        return new BatchResult($requests, $errors, true);
    }

    private function isAssociative(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
