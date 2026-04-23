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
        $enum = $schema['enum'] ?? null;
        $type = $schema['type'] ?? null;

        if (isset($schema['const']) && $data !== $schema['const']) {
            $expected = var_export($schema['const'], true);
            $actual = var_export($data, true);
            $errors[] = "Value must be {$expected}, got {$actual}";
        }

        if (is_array($enum) && !in_array($data, $enum, true)) {
            $allowed = implode(', ', array_map(fn($v) => var_export($v, true), $enum));
            $errors[] = "Value must be one of: {$allowed}";
        }

        if (is_string($type)) {
            $typeErrors = $this->validateType($data, $type);
            $errors = array_merge($errors, $typeErrors);
        }

        if ($this->isObjectType($schema, $data)) {
            /** @var array<string, mixed> $data */
            $errors = array_merge($errors, $this->validateObject($data, $schema, $depth));
        }

        if ($this->isArrayType($schema, $data)) {
            /** @var array<int|string, mixed> $data */
            $errors = array_merge($errors, $this->validateArray($data, $schema, $depth));
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $index => $subSchema) {
                if (is_array($subSchema)) {
                    /** @var array<string, mixed> $subSchema */
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
                if (is_array($subSchema)) {
                    /** @var array<string, mixed> $subSchema */
                    if (empty($this->validate($data, $subSchema, $depth + 1))) {
                        $anyValid = true;
                        break;
                    }
                }
            }
            if (!$anyValid) {
                $errors[] = 'Value must match at least one anyOf schema';
            }
        }

        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            $matchCount = 0;
            foreach ($schema['oneOf'] as $subSchema) {
                if (is_array($subSchema)) {
                    /** @var array<string, mixed> $subSchema */
                    if (empty($this->validate($data, $subSchema, $depth + 1))) {
                        $matchCount++;
                    }
                }
            }
            if ($matchCount !== 1) {
                $errors[] = "Value must match exactly one oneOf schema, matched {$matchCount}";
            }
        }

        if (is_numeric($data)) {
            $minimum = $schema['minimum'] ?? null;
            if (is_int($minimum) || is_float($minimum)) {
                if ($data < $minimum) {
                    $errors[] = "Value must be >= {$minimum}";
                }
            }

            $maximum = $schema['maximum'] ?? null;
            if (is_int($maximum) || is_float($maximum)) {
                if ($data > $maximum) {
                    $errors[] = "Value must be <= {$maximum}";
                }
            }

            $exclusiveMinimum = $schema['exclusiveMinimum'] ?? null;
            if (is_int($exclusiveMinimum) || is_float($exclusiveMinimum)) {
                if ($data <= $exclusiveMinimum) {
                    $errors[] = "Value must be > {$exclusiveMinimum}";
                }
            }

            $exclusiveMaximum = $schema['exclusiveMaximum'] ?? null;
            if (is_int($exclusiveMaximum) || is_float($exclusiveMaximum)) {
                if ($data >= $exclusiveMaximum) {
                    $errors[] = "Value must be < {$exclusiveMaximum}";
                }
            }

            if (isset($schema['multipleOf']) && is_numeric($schema['multipleOf']) && $schema['multipleOf'] != 0) {
                $quotient = $data / $schema['multipleOf'];
                if (abs($quotient - round($quotient)) > 1e-9) {
                    $errors[] = "Value must be a multiple of {$schema['multipleOf']}";
                }
            }
        }

        if (is_string($data)) {
            $minLength = $schema['minLength'] ?? null;
            if (is_int($minLength) && strlen($data) < $minLength) {
                $errors[] = "String must be at least {$minLength} characters";
            }

            $maxLength = $schema['maxLength'] ?? null;
            if (is_int($maxLength) && strlen($data) > $maxLength) {
                $errors[] = "String must be at most {$maxLength} characters";
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
            /** @var array<string, mixed> $notSchema */
            $notSchema = $schema['not'];
            $notErrors = $this->validate($data, $notSchema, $depth + 1);
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
        $required = $schema['required'] ?? null;
        $properties = $schema['properties'] ?? null;

        if (is_array($required)) {
            foreach ($required as $field) {
                if (!is_string($field) || $field === '') {
                    continue;
                }

                if (!array_key_exists($field, $data)) {
                    $errors[] = "Missing required field: {$field}";
                }
            }
        }

        if (is_array($properties)) {
            foreach ($properties as $propName => $propSchema) {
                if (!is_string($propName) || !is_array($propSchema)) {
                    continue;
                }

                /** @var array<string, mixed> $propSchema */
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
            $allowed = is_array($properties)
                ? array_values(array_filter(array_keys($properties), static fn (mixed $key): bool => is_string($key)))
                : [];

            if ($additional === false) {
                foreach (array_keys($data) as $key) {
                    if (!in_array($key, $allowed, true)) {
                        $errors[] = "Unexpected field: {$key}";
                    }
                }
            } elseif (is_array($additional)) {
                /** @var array<string, mixed> $additionalSchema */
                $additionalSchema = $additional;
                foreach (array_keys($data) as $key) {
                    if (!in_array($key, $allowed, true)) {
                        $addErrors = $this->validate($data[$key], $additionalSchema, $depth + 1);
                        foreach ($addErrors as $err) {
                            $errors[] = "{$key}.{$err}";
                        }
                    }
                }
            }
        }

        $minProperties = $schema['minProperties'] ?? null;
        if (is_int($minProperties) && count($data) < $minProperties) {
            $errors[] = "Object must have at least {$minProperties} properties";
        }

        $maxProperties = $schema['maxProperties'] ?? null;
        if (is_int($maxProperties) && count($data) > $maxProperties) {
            $errors[] = "Object must have at most {$maxProperties} properties";
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
        $items = $schema['items'] ?? null;

        if (is_array($items)) {
            /** @var array<string, mixed> $itemSchema */
            $itemSchema = $items;
            foreach ($data as $index => $item) {
                $itemErrors = $this->validate($item, $itemSchema, $depth + 1);
                foreach ($itemErrors as $err) {
                    $errors[] = "[{$index}].{$err}";
                }
            }
        }

        $minItems = $schema['minItems'] ?? null;
        if (is_int($minItems) && count($data) < $minItems) {
            $errors[] = "Array must have at least {$minItems} items";
        }

        $maxItems = $schema['maxItems'] ?? null;
        if (is_int($maxItems) && count($data) > $maxItems) {
            $errors[] = "Array must have at most {$maxItems} items";
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
        $indexedArray = false;
        if (is_array($data)) {
            /** @var array<int|string, mixed> $data */
            $indexedArray = $this->isIndexedArray($data);
        }

        $valid = match ($expectedType) {
            'string' => is_string($data),
            'integer', 'int' => is_int($data),
            'number' => is_int($data) || is_float($data),
            'boolean', 'bool' => is_bool($data),
            'array' => is_array($data),
            'object' => is_array($data) && !$indexedArray,
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
        return is_string($schema['type'] ?? null) && $schema['type'] === 'object' && is_array($data);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function isArrayType(array $schema, mixed $data): bool
    {
        if (!is_string($schema['type'] ?? null) || $schema['type'] !== 'array' || !is_array($data)) {
            return false;
        }

        /** @var array<int|string, mixed> $data */
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
            if (!ctype_digit($timeParts[0]) || !ctype_digit($timeParts[1]) || !ctype_digit($timeParts[2])) {
                $errors[] = "date-time time components must be numeric";
            } else {
                $hour = (int)$timeParts[0];
                $minute = (int)$timeParts[1];
                $second = (int)$timeParts[2];
                if (!$this->isTimeInRange($hour, $minute, $second)) {
                    $errors[] = "date-time time components out of range";
                }
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
            if (!ctype_digit($parts[0]) || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
                return ["time components must be numeric"];
            }
            $hour = (int)$parts[0];
            $minute = (int)$parts[1];
            $second = (int)$parts[2];
            if (!$this->isTimeInRange($hour, $minute, $second)) {
                return ["time components out of range"];
            }
        }
        return [];
    }

    private function isTimeInRange(int $hour, int $minute, int $second): bool
    {
        return $hour <= 23 && $minute <= 59 && $second <= 59;
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
