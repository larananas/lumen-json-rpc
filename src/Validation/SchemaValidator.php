<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Validation;

final class SchemaValidator
{
    private const MAX_DEPTH = 32;

    private const FORMAT_PATTERNS = [
        'email' => '/^[a-zA-Z0-9.!#$%&\'*+\\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/',
        'uri' => '/^[a-zA-Z][a-zA-Z0-9+.-]*:.+/s',
        'url' => '/^https?:\\/\\/.+/s',
        'uuid' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        'ipv4' => '/^(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?)$/',
        'ipv6' => '/^(([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}|(::)?([0-9a-fA-F]{1,4}:){0,6}[0-9a-fA-F]{1,4}(::)?|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:)|fe80:(:[0-9a-fA-F]{0,4}){0,4}%[0-9a-zA-Z]+|::(ffff(:0{1,4})?:)?((25[0-5]|(2[0-4]|1?\d)?\d)\.){3}(25[0-5]|(2[0-4]|1?\d)?\d))$/',
        'date-time' => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
        'date' => '/^\d{4}-\d{2}-\d{2}$/',
        'time' => '/^\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/',
    ];

    /**
     * @return array<int, string>
     */
    public static function supportedFormats(): array
    {
        return array_keys(self::FORMAT_PATTERNS);
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    public function validate(mixed $data, array $schema, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return ['Maximum validation depth exceeded'];
        }

        $errors = [];

        if (isset($schema['const']) && $data !== $schema['const']) {
            $expected = var_export($schema['const'], true);
            $actual = var_export($data, true);
            $errors[] = "Value must be {$expected}, got {$actual}";
        }

        if (isset($schema['enum']) && !in_array($data, $schema['enum'], true)) {
            $allowed = implode(', ', array_map(fn($v) => var_export($v, true), $schema['enum']));
            $errors[] = "Value must be one of: {$allowed}";
        }

        if (isset($schema['type'])) {
            $typeErrors = $this->validateType($data, $schema['type']);
            $errors = array_merge($errors, $typeErrors);
        }

        if ($this->isObjectType($schema, $data)) {
            $errors = array_merge($errors, $this->validateObject($data, $schema, $depth));
        }

        if ($this->isArrayType($schema, $data)) {
            $errors = array_merge($errors, $this->validateArray($data, $schema, $depth));
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $index => $subSchema) {
                if (is_array($subSchema)) {
                    $subErrors = $this->validate($data, $subSchema, $depth + 1);
                    foreach ($subErrors as $err) {
                        $errors[] = "allOf[{$index}]: {$err}";
                    }
                }
            }
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            $anyValid = false;
            foreach ($schema['anyOf'] as $subSchema) {
                if (is_array($subSchema) && empty($this->validate($data, $subSchema, $depth + 1))) {
                    $anyValid = true;
                    break;
                }
            }
            if (!$anyValid) {
                $errors[] = 'Value must match at least one anyOf schema';
            }
        }

        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            $matchCount = 0;
            foreach ($schema['oneOf'] as $subSchema) {
                if (is_array($subSchema) && empty($this->validate($data, $subSchema, $depth + 1))) {
                    $matchCount++;
                }
            }
            if ($matchCount !== 1) {
                $errors[] = "Value must match exactly one oneOf schema, matched {$matchCount}";
            }
        }

        if (is_numeric($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $errors[] = "Value must be >= {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $errors[] = "Value must be <= {$schema['maximum']}";
            }
            if (isset($schema['exclusiveMinimum']) && $data <= $schema['exclusiveMinimum']) {
                $errors[] = "Value must be > {$schema['exclusiveMinimum']}";
            }
            if (isset($schema['exclusiveMaximum']) && $data >= $schema['exclusiveMaximum']) {
                $errors[] = "Value must be < {$schema['exclusiveMaximum']}";
            }
            if (isset($schema['multipleOf']) && is_numeric($schema['multipleOf']) && $schema['multipleOf'] != 0) {
                $quotient = $data / $schema['multipleOf'];
                if (abs($quotient - round($quotient)) > 1e-9) {
                    $errors[] = "Value must be a multiple of {$schema['multipleOf']}";
                }
            }
        }

        if (is_string($data)) {
            if (isset($schema['minLength']) && strlen($data) < $schema['minLength']) {
                $errors[] = "String must be at least {$schema['minLength']} characters";
            }
            if (isset($schema['maxLength']) && strlen($data) > $schema['maxLength']) {
                $errors[] = "String must be at most {$schema['maxLength']} characters";
            }
            if (isset($schema['pattern']) && is_string($schema['pattern'])) {
                $pattern = $schema['pattern'];
                $escaped = str_replace('/', '\\/', $pattern);
                if (!preg_match('/' . $escaped . '/', $data)) {
                    $errors[] = "String must match pattern: {$schema['pattern']}";
                }
            }
            if (isset($schema['format']) && is_string($schema['format'])) {
                $formatErrors = $this->validateFormat($data, $schema['format']);
                $errors = array_merge($errors, $formatErrors);
            }
        }

        if (isset($schema['not']) && is_array($schema['not'])) {
            $notErrors = $this->validate($data, $schema['not'], $depth + 1);
            if (empty($notErrors)) {
                $errors[] = 'Value must not match the "not" schema';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private function validateObject(array $data, array $schema, int $depth): array
    {
        $errors = [];

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
                    $propErrors = $this->validate($data[$propName], $propSchema, $depth + 1);
                    foreach ($propErrors as $err) {
                        $errors[] = "{$propName}.{$err}";
                    }
                }
            }
        }

        if (array_key_exists('additionalProperties', $schema)) {
            $additional = $schema['additionalProperties'];
            $allowed = array_keys($schema['properties'] ?? []);

            if ($additional === false) {
                foreach (array_keys($data) as $key) {
                    if (!in_array($key, $allowed, true)) {
                        $errors[] = "Unexpected field: {$key}";
                    }
                }
            } elseif (is_array($additional)) {
                foreach (array_keys($data) as $key) {
                    if (!in_array($key, $allowed, true)) {
                        $addErrors = $this->validate($data[$key], $additional, $depth + 1);
                        foreach ($addErrors as $err) {
                            $errors[] = "{$key}.{$err}";
                        }
                    }
                }
            }
        }

        if (isset($schema['minProperties']) && count($data) < $schema['minProperties']) {
            $errors[] = "Object must have at least {$schema['minProperties']} properties";
        }
        if (isset($schema['maxProperties']) && count($data) > $schema['maxProperties']) {
            $errors[] = "Object must have at most {$schema['maxProperties']} properties";
        }

        return $errors;
    }

    /**
     * @param array<int|string, mixed> $data
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private function validateArray(array $data, array $schema, int $depth): array
    {
        $errors = [];

        if (isset($schema['items'])) {
            foreach ($data as $index => $item) {
                $itemErrors = $this->validate($item, $schema['items'], $depth + 1);
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

        if (($schema['uniqueItems'] ?? false) === true) {
            $seen = [];
            foreach ($data as $item) {
                $serialized = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (in_array($serialized, $seen, true)) {
                    $errors[] = 'Array items must be unique';
                    break;
                }
                $seen[] = $serialized;
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    private function validateType(mixed $data, string $expectedType): array
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

    /**
     * @param array<string, mixed> $schema
     */
    private function isObjectType(array $schema, mixed $data): bool
    {
        return ($schema['type'] ?? null) === 'object' && is_array($data);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function isArrayType(array $schema, mixed $data): bool
    {
        if (($schema['type'] ?? null) !== 'array' || !is_array($data)) {
            return false;
        }
        return $this->isIndexedArray($data);
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $arr
     */
    private function isIndexedArray(array $arr): bool
    {
        if (empty($arr)) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /**
     * @return array<int, string>
     */
    private function validateFormat(string $data, string $format): array
    {
        if (!isset(self::FORMAT_PATTERNS[$format])) {
            return [];
        }

        if (!preg_match(self::FORMAT_PATTERNS[$format], $data)) {
            return ["String does not match format: {$format}"];
        }

        return match ($format) {
            'date' => $this->validateDateComponents($data),
            'date-time' => $this->validateDateTimeComponents($data),
            'time' => $this->validateTimeComponents($data),
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    private function validateDateComponents(string $data): array
    {
        [$year, $month, $day] = explode('-', $data);
        return $this->checkDateRanges((int)$month, (int)$day);
    }

    /**
     * @return array<int, string>
     */
    private function validateDateTimeComponents(string $data): array
    {
        $parts = explode('T', $data, 2);
        if (count($parts) !== 2) {
            return [];
        }
        [$datePart] = $parts;
        [$year, $month, $day] = explode('-', $datePart);
        $errors = $this->checkDateRanges((int)$month, (int)$day);

        $timePart = $parts[1];
        $tzPos = strrpos($timePart, 'Z');
        if ($tzPos === false) {
            $tzPos = strrpos($timePart, '+');
            if ($tzPos === false) {
                $tzPos = strrpos($timePart, '-', 1);
            }
        }
        if ($tzPos !== false) {
            $timePart = substr($timePart, 0, $tzPos);
        }
        $dotPos = strpos($timePart, '.');
        if ($dotPos !== false) {
            $timePart = substr($timePart, 0, $dotPos);
        }
        $timeParts = explode(':', $timePart);
        if (count($timeParts) >= 3) {
            $hour = (int)$timeParts[0];
            $minute = (int)$timeParts[1];
            $second = (int)$timeParts[2];
            if ($hour > 23 || $minute > 59 || $second > 59) {
                $errors[] = "date-time time components out of range";
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    private function validateTimeComponents(string $data): array
    {
        $timePart = $data;
        $tzPos = strrpos($timePart, 'Z');
        if ($tzPos === false) {
            $tzPos = strrpos($timePart, '+');
            if ($tzPos === false) {
                $tzPos = strrpos($timePart, '-', 1);
            }
        }
        if ($tzPos !== false) {
            $timePart = substr($timePart, 0, $tzPos);
        }
        $dotPos = strpos($timePart, '.');
        if ($dotPos !== false) {
            $timePart = substr($timePart, 0, $dotPos);
        }
        $parts = explode(':', $timePart);
        if (count($parts) >= 3) {
            $hour = (int)$parts[0];
            $minute = (int)$parts[1];
            $second = (int)$parts[2];
            if ($hour > 23 || $minute > 59 || $second > 59) {
                return ["time components out of range"];
            }
        }
        return [];
    }

    /**
     * @return array<int, string>
     */
    private function checkDateRanges(int $month, int $day): array
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return ["date components out of range"];
        }
        return [];
    }
}
