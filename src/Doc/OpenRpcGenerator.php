<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Doc;

final class OpenRpcGenerator
{
    /**
     * @param array<int, MethodDoc> $docs
     */
    public function generate(
        array $docs,
        string $serverName = 'JSON-RPC 2.0 API',
        string $version = '1.0.0',
        string $description = '',
    ): string {
        $spec = [
            'openrpc' => '1.3.2',
            'info' => [
                'title' => $serverName,
                'version' => $version,
                'description' => $description,
            ],
            'servers' => [
                ['name' => 'default', 'url' => 'http://localhost/'],
            ],
            'methods' => [],
        ];

        foreach ($docs as $doc) {
            $spec['methods'][] = $this->methodToOpenRpc($doc);
        }

        return json_encode(
            $spec,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function methodToOpenRpc(MethodDoc $doc): array
    {
        $method = [
            'name' => $doc->name,
            'description' => $doc->description,
            'params' => [],
            'result' => $this->buildResult($doc),
        ];

        foreach ($doc->params as $name => $param) {
            $method['params'][] = $this->buildParam($name, $param);
        }

        if ($doc->requiresAuth) {
            $method['x-requiresAuth'] = true;
        }

        $tag = $this->extractTag($doc->name);
        if ($tag !== null) {
            $method['tags'] = [['name' => $tag]];
        }

        $errors = $this->buildErrors($doc);
        if (!empty($errors)) {
            $method['errors'] = $errors;
        }

        $examples = [];

        if ($doc->exampleRequest !== null) {
            $decoded = json_decode($doc->exampleRequest, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $rawParams = $decoded['params'] ?? $decoded;
                $params = [];
                if (is_array($rawParams)) {
                    if ($this->isAssociative($rawParams)) {
                        foreach ($rawParams as $key => $val) {
                            $params[] = ['name' => (string)$key, 'value' => $val];
                        }
                    } else {
                        foreach ($rawParams as $i => $val) {
                            $params[] = ['name' => (string)$i, 'value' => $val];
                        }
                    }
                }
                $examples[] = [
                    'name' => 'request-example',
                    'params' => $params,
                ];
            }
        }

        if ($doc->exampleResponse !== null) {
            $decoded = json_decode($doc->exampleResponse, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded['result'] ?? $decoded;
                $examples[] = [
                    'name' => 'response-example',
                    'params' => [],
                    'result' => ['name' => 'result', 'value' => $value],
                ];
            }
        }

        if (!empty($examples)) {
            $method['examples'] = $examples;
        }

        return $method;
    }

    /**
     * @param array<string, mixed> $param
     * @return array<string, mixed>
     */
    private function buildParam(string $name, array $param): array
    {
        $p = [
            'name' => $name,
            'description' => (string)($param['description'] ?? ''),
            'required' => ($param['required'] ?? false) === true,
            'schema' => $this->phpTypeToJsonSchema((string)($param['type'] ?? 'mixed')),
        ];

        if (!$p['required'] && array_key_exists('default', $param) && $param['default'] !== null) {
            $p['schema']['default'] = $param['default'];
        }

        return $p;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResult(MethodDoc $doc): array
    {
        $returnType = $doc->returnType ?? 'mixed';
        return [
            'name' => 'result',
            'description' => $doc->returnDescription ?? '',
            'schema' => $this->phpTypeToJsonSchema($returnType),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildErrors(MethodDoc $doc): array
    {
        $errors = [];
        foreach ($doc->errors as $error) {
            $code = $error['code'] ?? null;
            if ($code !== null) {
                $message = $error['message'] ?? '';
                if ($message === '') {
                    $message = $error['description'];
                }
                $built = [
                    'code' => is_numeric($code) ? (int)$code : 0,
                    'message' => (string)$message,
                ];
                $desc = (string)$error['description'];
                if ($desc !== '' && $desc !== (string)$message) {
                    $built['data'] = $desc;
                }
                $errors[] = $built;
            }
        }
        return $errors;
    }

    private function extractTag(string $methodName): ?string
    {
        $dotPos = strpos($methodName, '.');
        if ($dotPos === false) {
            return null;
        }
        return substr($methodName, 0, $dotPos);
    }

    /**
     * @return array<string, mixed>
     */
    private function phpTypeToJsonSchema(string $phpType): array
    {
        $nullable = str_starts_with($phpType, '?');
        $type = $nullable ? substr($phpType, 1) : $phpType;

        if (str_contains($type, '|')) {
            return $this->handleUnionType($type, $nullable);
        }

        $schema = $this->resolveSingleType($type);

        if ($nullable && isset($schema['type'])) {
            $schema = ['oneOf' => [$schema, ['type' => 'null']]];
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSingleType(string $type): array
    {
        if (str_starts_with($type, 'array<') && str_ends_with($type, '>')) {
            return $this->resolveArrayType($type);
        }

        return match ($type) {
            'int', 'integer' => ['type' => 'integer'],
            'float', 'double' => ['type' => 'number'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'string' => ['type' => 'string'],
            'true' => ['const' => true],
            'false' => ['const' => false],
            'array' => ['type' => 'array', 'items' => ['description' => 'Any value']],
            'void', 'null' => ['type' => 'null'],
            'mixed' => ['description' => 'Any value'],
            'object' => ['type' => 'object'],
            default => $this->resolveClassType($type),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveArrayType(string $type): array
    {
        $inner = substr($type, 6, -1);

        if (str_contains($inner, ',')) {
            $parts = array_map('trim', explode(',', $inner, 2));
            $keyType = $parts[0];
            $valueType = $parts[1];

            if (in_array($keyType, ['string', 'int', 'integer'], true)) {
                return [
                    'type' => 'object',
                    'additionalProperties' => $this->resolveSingleType($valueType),
                ];
            }
        }

        return [
            'type' => 'array',
            'items' => $this->resolveSingleType(trim($inner)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveClassType(string $type): array
    {
        if (str_ends_with($type, '[]')) {
            $itemType = substr($type, 0, -2);
            return [
                'type' => 'array',
                'items' => $this->resolveSingleType($itemType),
            ];
        }

        return ['type' => 'object', 'description' => $type];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleUnionType(string $type, bool $alreadyNullable): array
    {
        $parts = array_map('trim', explode('|', $type));
        $schemas = [];
        $hasNull = false;

        foreach ($parts as $part) {
            if ($part === 'null') {
                $hasNull = true;
                continue;
            }
            $schemas[] = $this->resolveSingleType($part);
        }

        if ($hasNull || $alreadyNullable) {
            $schemas[] = ['type' => 'null'];
        }

        if (count($schemas) === 1) {
            return $schemas[0];
        }

        if (count($schemas) === 2 && isset($schemas[1]['type']) && $schemas[1]['type'] === 'null') {
            return ['oneOf' => [$schemas[0], ['type' => 'null']]];
        }

        return ['oneOf' => $schemas];
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $arr
     */
    private function isAssociative(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
