<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Validation;

final class SchemaValidator
{
    public function validate(mixed $data, array $schema): array
    {
        $errors = [];

        if (isset($schema['type'])) {
            $typeErrors = $this->validateType($data, $schema['type'], '');
            $errors = array_merge($errors, $typeErrors);
        }

        if ($schema['type'] ?? null === 'object' && is_array($data)) {
            if (isset($schema['required'])) {
                foreach ($schema['required'] as $field) {
                    if (!array_key_exists($field, $data)) {
                        $errors[] = "Missing required field: {$field}";
                    }
                }
            }

            if (isset($schema['properties'])) {
                foreach ($schema['properties'] as $propName => $propSchema) {
                    if (array_key_exists($propName, $data)) {
                        $propErrors = $this->validate($data[$propName], $propSchema);
                        foreach ($propErrors as $err) {
                            $errors[] = "{$propName}.{$err}";
                        }
                    }
                }
            }

            if (($schema['additionalProperties'] ?? null) === false) {
                $allowed = array_keys($schema['properties'] ?? []);
                foreach (array_keys($data) as $key) {
                    if (!in_array($key, $allowed, true)) {
                        $errors[] = "Unexpected field: {$key}";
                    }
                }
            }
        }

        if (($schema['type'] ?? null) === 'array' && is_array($data)) {
            if ($this->isIndexedArray($data)) {
                if (isset($schema['items'])) {
                    foreach ($data as $index => $item) {
                        $itemErrors = $this->validate($item, $schema['items']);
                        foreach ($itemErrors as $err) {
                            $errors[] = "[{$index}].{$err}";
                        }
                    }
                }
                if (isset($schema['minItems']) && count($data) < $schema['minItems']) {
                    $errors[] = "Array must have at least {$schema['minItems']} items";
                }
                if (isset($schema['maxItems']) && count($data) > $schema['maxItems']) {
                    $errors[] = "Array must have at most {$schema['maxItems']} items";
                }
            }
        }

        if (isset($schema['enum']) && !in_array($data, $schema['enum'], true)) {
            $allowed = implode(', ', array_map(fn($v) => var_export($v, true), $schema['enum']));
            $errors[] = "Value must be one of: {$allowed}";
        }

        if (isset($schema['minLength']) && is_string($data) && strlen($data) < $schema['minLength']) {
            $errors[] = "String must be at least {$schema['minLength']} characters";
        }

        if (isset($schema['maxLength']) && is_string($data) && strlen($data) > $schema['maxLength']) {
            $errors[] = "String must be at most {$schema['maxLength']} characters";
        }

        return $errors;
    }

    private function validateType(mixed $data, string $expectedType, string $path): array
    {
        $valid = match ($expectedType) {
            'string' => is_string($data),
            'integer', 'int' => is_int($data),
            'number' => is_int($data) || is_float($data),
            'boolean', 'bool' => is_bool($data),
            'array' => is_array($data),
            'object' => is_array($data) && !$this->isIndexedArray($data),
            'null' => $data === null,
            default => true,
        };

        if (!$valid) {
            $actual = gettype($data);
            return ["Expected type {$expectedType}, got {$actual}"];
        }

        return [];
    }

    private function isIndexedArray(array $arr): bool
    {
        if (empty($arr)) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
