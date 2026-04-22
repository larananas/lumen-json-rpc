<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Doc;

use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Validation\RpcSchemaProviderInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionNamedType;
use ReflectionUnionType;

final class DocGenerator
{
    public function __construct(
        private readonly HandlerRegistry $registry,
    ) {}

    /**
     * @return array<int, MethodDoc>
     */
    public function generate(): array
    {
        $handlers = $this->registry->getHandlers();
        $docs = [];

        foreach ($handlers as $methodName => $handlerInfo) {
            $docs[] = $this->documentMethod($methodName, $handlerInfo);
        }

        return $docs;
    }

    /**
     * @param array{class: string, method: string, file: string, descriptor?: bool} $handlerInfo
     */
    private function documentMethod(string $methodName, array $handlerInfo): MethodDoc
    {
        $descriptor = $this->registry->getDescriptor($methodName);
        $descriptorMetadata = $descriptor !== null ? $descriptor->metadata : [];
        $descriptorDescription = $this->metadataNullableString($descriptorMetadata, 'description');
        $descriptorParams = $this->metadataParams($descriptorMetadata);
        $descriptorReturnType = $this->metadataNullableString($descriptorMetadata, 'returnType');
        $descriptorResultSchema = $this->metadataSchema($descriptorMetadata, 'resultSchema');

        $reflection = null;
        $method = null;
        $phpDoc = '';
        $classDoc = '';

        $classExists = class_exists($handlerInfo['class'])
            || ($handlerInfo['file'] !== '' && file_exists($handlerInfo['file']));

        if ($classExists && !class_exists($handlerInfo['class']) && $handlerInfo['file'] !== '') {
            require_once $handlerInfo['file'];
        }

        if (class_exists($handlerInfo['class'])) {
            $reflection = new ReflectionClass($handlerInfo['class']);
            if ($reflection->hasMethod($handlerInfo['method'])) {
                $method = $reflection->getMethod($handlerInfo['method']);
                $phpDoc = $method->getDocComment() ?: '';
            }
            $classDoc = $reflection->getDocComment() ?: '';
        }

        $requestSchema = $this->extractRequestSchema($handlerInfo);
        $description = $descriptorDescription ?? ($method !== null ? $this->parseDescription($phpDoc) : '');
        $params = $descriptorParams ?? ($method !== null ? $this->parseParams($method, $phpDoc) : []);
        $params = $this->mergeRequestSchemaIntoParams($params, $requestSchema);
        $returnType = $descriptorReturnType ?? ($method !== null ? $this->parseReturnType($method, $phpDoc) : null);
        $returnDesc = $this->metadataString($descriptorMetadata, 'returnDescription', $method !== null ? $this->parseReturnDescription($phpDoc) : '');
        $requiresAuth = $this->metadataBool($descriptorMetadata, 'requiresAuth', $phpDoc !== '' ? $this->parseAuthRequirement($phpDoc, $classDoc) : false);
        $errors = $this->metadataErrors($descriptorMetadata) ?? ($phpDoc !== '' ? $this->parseErrors($phpDoc) : []);
        $exampleRequest = $this->metadataNullableString($descriptorMetadata, 'exampleRequest') ?? ($phpDoc !== '' ? $this->parseExample($phpDoc, 'request') : null);
        $exampleResponse = $this->metadataNullableString($descriptorMetadata, 'exampleResponse') ?? ($phpDoc !== '' ? $this->parseExample($phpDoc, 'response') : null);
        $resultSchema = $descriptorResultSchema ?? ($phpDoc !== '' ? $this->parseJsonObjectTag($phpDoc, 'result-schema') : null);

        return new MethodDoc(
            name: $methodName,
            description: $description,
            params: $params,
            returnType: $returnType,
            returnDescription: $returnDesc,
            requiresAuth: $requiresAuth,
            errors: $errors,
            exampleRequest: $exampleRequest,
            exampleResponse: $exampleResponse,
            requestSchema: $requestSchema,
            resultSchema: $resultSchema,
        );
    }

    /**
     * @param array{class: string, method: string, file: string, descriptor?: bool} $handlerInfo
     * @return ?array<string, mixed>
     */
    private function extractRequestSchema(array $handlerInfo): ?array
    {
        if (!class_exists($handlerInfo['class']) && $handlerInfo['file'] !== '' && file_exists($handlerInfo['file'])) {
            require_once $handlerInfo['file'];
        }

        if (!class_exists($handlerInfo['class'])) {
            return null;
        }

        if (!is_a($handlerInfo['class'], RpcSchemaProviderInterface::class, true)) {
            return null;
        }

        $schemas = $handlerInfo['class']::rpcValidationSchemas();
        $schema = $schemas[$handlerInfo['method']] ?? null;

        /** @var ?array<string, mixed> $schema */
        return is_array($schema) ? $schema : null;
    }

    /**
     * @param array<string, array{type: string, description: string, required: bool, default: mixed, schema?: array<string, mixed>}> $params
     * @param ?array<string, mixed> $requestSchema
     * @return array<string, array{type: string, description: string, required: bool, default: mixed, schema?: array<string, mixed>}>
     */
    private function mergeRequestSchemaIntoParams(array $params, ?array $requestSchema): array
    {
        if ($requestSchema === null || ($requestSchema['type'] ?? null) !== 'object') {
            return $params;
        }

        $properties = $requestSchema['properties'] ?? null;
        if (!is_array($properties)) {
            return $params;
        }

        $required = $requestSchema['required'] ?? [];
        if (!is_array($required)) {
            $required = [];
        }

        foreach ($params as $name => $param) {
            $propertySchema = $properties[$name] ?? null;
            if (!is_array($propertySchema)) {
                continue;
            }

            $param['schema'] = $propertySchema;

            if ($param['description'] === '' && isset($propertySchema['description']) && is_string($propertySchema['description'])) {
                $param['description'] = $propertySchema['description'];
            }

            $param['required'] = in_array($name, $required, true);
            $params[$name] = $param;
        }

        return $params;
    }

    private function parseDescription(string $doc): string
    {
        if ($doc === '') {
            return '';
        }
        $lines = explode("\n", $doc);
        $desc = [];
        foreach ($lines as $line) {
            $line = trim($line, "/* \t");
            if ($line === '' || str_starts_with($line, '@')) {
                if (!empty($desc)) {
                    break;
                }
                continue;
            }
            $desc[] = $line;
        }
        return implode(' ', $desc);
    }

    /**
     * @return array<string, array{type: string, description: string, required: bool, default: mixed}>
     */
    private function parseParams(ReflectionMethod $method, string $doc): array
    {
        $params = [];
        $docParams = $this->extractDocTags($doc, 'param');

        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            $type = $param->getType();
            $typeName = ($type instanceof ReflectionNamedType) ? $type->getName() : '';
            if ($name === 'context' && $typeName === \Lumen\JsonRpc\Support\RequestContext::class) {
                continue;
            }

            $type = $this->getParamType($param);
            $desc = $docParams[$name] ?? '';
            $hasDefault = $param->isDefaultValueAvailable();

            $params[$name] = [
                'type' => $type,
                'description' => $desc,
                'required' => !$hasDefault && !$param->allowsNull(),
                'default' => $hasDefault ? $param->getDefaultValue() : null,
            ];
        }

        return $params;
    }

    private function getParamType(ReflectionParameter $param): string
    {
        $type = $param->getType();
        if ($type === null) {
            return 'mixed';
        }
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            return ($type->allowsNull() && $name !== 'mixed' ? '?' : '') . $name;
        }
        $unionTypes = [];
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $t) {
                if ($t instanceof ReflectionNamedType) {
                    $unionTypes[] = $t->getName();
                }
            }
        }
        return implode('|', $unionTypes) ?: (string)$type;
    }

    private function parseReturnType(ReflectionMethod $method, string $doc): ?string
    {
        $returnTags = $this->extractDocTags($doc, 'return');
        if (!empty($returnTags)) {
            return array_key_first($returnTags);
        }
        $type = $method->getReturnType();
        if ($type !== null) {
            return (string)$type;
        }
        return null;
    }

    private function parseReturnDescription(string $doc): string
    {
        $returnTags = $this->extractDocTags($doc, 'return');
        if (!empty($returnTags)) {
            return reset($returnTags);
        }
        return '';
    }

    private function parseAuthRequirement(string $methodDoc, string $classDoc): bool
    {
        $combined = $methodDoc . ' ' . $classDoc;
        return str_contains($combined, '@requiresAuth')
            || str_contains($combined, '@authenticated')
            || str_contains($combined, '@auth required');
    }

    /**
     * @return array<int, array{type?: string, code?: string, description: string}>
     */
    private function parseErrors(string $doc): array
    {
        $errors = [];
        $errorTags = $this->extractDocTags($doc, 'throws');
        foreach ($errorTags as $type => $desc) {
            $errors[] = ['type' => $type, 'description' => $desc];
        }

        $errorTags2 = $this->extractDocTags($doc, 'error');
        foreach ($errorTags2 as $code => $desc) {
            $errors[] = ['code' => $code, 'description' => $desc];
        }

        return $errors;
    }

    private function parseExample(string $doc, string $type): ?string
    {
        return $this->parseJsonTag($doc, "example-$type");
    }

    /**
     * @return ?array<string, mixed>
     */
    private function parseJsonObjectTag(string $doc, string $tag): ?array
    {
        $json = $this->parseJsonTag($doc, $tag);
        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);
        /** @var ?array<string, mixed> $decoded */
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function metadataString(array $metadata, string $key, string $default = ''): string
    {
        return is_string($metadata[$key] ?? null) ? $metadata[$key] : $default;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function metadataNullableString(array $metadata, string $key): ?string
    {
        return is_string($metadata[$key] ?? null) ? $metadata[$key] : null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function metadataBool(array $metadata, string $key, bool $default = false): bool
    {
        return is_bool($metadata[$key] ?? null) ? $metadata[$key] : $default;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return ?array<string, mixed>
     */
    private function metadataSchema(array $metadata, string $key): ?array
    {
        $schema = $metadata[$key] ?? null;
        if (!is_array($schema)) {
            return null;
        }

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return ?array<string, array{type: string, description: string, required: bool, default: mixed, schema?: array<string, mixed>}>
     */
    private function metadataParams(array $metadata): ?array
    {
        $params = $metadata['params'] ?? null;
        if (!is_array($params)) {
            return null;
        }

        $normalized = [];
        foreach ($params as $name => $param) {
            if (!is_string($name) || !is_array($param)) {
                continue;
            }

            $normalizedParam = [
                'type' => is_string($param['type'] ?? null) ? $param['type'] : 'mixed',
                'description' => is_string($param['description'] ?? null) ? $param['description'] : '',
                'required' => ($param['required'] ?? false) === true,
                'default' => $param['default'] ?? null,
            ];

            if (isset($param['schema']) && is_array($param['schema'])) {
                /** @var array<string, mixed> $schema */
                $schema = $param['schema'];
                $normalizedParam['schema'] = $schema;
            }

            $normalized[$name] = $normalizedParam;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return ?array<int, array{type?: string, code?: string, message?: string, description: string}>
     */
    private function metadataErrors(array $metadata): ?array
    {
        $errors = $metadata['errors'] ?? null;
        if (!is_array($errors)) {
            return null;
        }

        $normalized = [];
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $description = is_string($error['description'] ?? null) ? $error['description'] : '';
            if ($description === '') {
                continue;
            }

            $normalizedError = ['description' => $description];
            if (is_string($error['type'] ?? null)) {
                $normalizedError['type'] = $error['type'];
            }
            if (is_string($error['code'] ?? null) || is_int($error['code'] ?? null)) {
                $normalizedError['code'] = (string) $error['code'];
            }
            if (is_string($error['message'] ?? null)) {
                $normalizedError['message'] = $error['message'];
            }

            $normalized[] = $normalizedError;
        }

        return $normalized;
    }

    private function parseJsonTag(string $doc, string $tag): ?string
    {
        $marker = '@' . $tag;
        $pos = strpos($doc, $marker);
        if ($pos === false) {
            return null;
        }

        $after = ltrim(substr($doc, $pos + strlen($marker)));
        if (!isset($after[0]) || ($after[0] !== '{' && $after[0] !== '[')) {
            return null;
        }

        $openChar = $after[0];
        $closeChar = $openChar === '{' ? '}' : ']';
        $depth = 0;
        $inString = false;
        $escape = false;
        $end = strlen($after);

        for ($i = 0; $i < $end; $i++) {
            $ch = $after[$i];
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($ch === '\\') {
                $escape = true;
                continue;
            }
            if ($ch === '"') {
                $inString = !$inString;
                continue;
            }
            if ($inString) {
                continue;
            }
            if ($ch === $openChar) {
                $depth++;
            } elseif ($ch === $closeChar) {
                $depth--;
                if ($depth === 0) {
                    $extracted = trim(substr($after, 0, $i + 1));
                    json_decode($extracted);
                    return json_last_error() === JSON_ERROR_NONE ? $extracted : null;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function extractDocTags(string $doc, string $tag): array
    {
        $result = [];
        if ($tag === 'param') {
            $pattern = '/@param\s+(\S+)\s+\$(\S+)(?:\s+(.*))?/m';
            if (preg_match_all($pattern, $doc, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $paramName = $match[2];
                    $desc = trim($match[3] ?? '');
                    $result[$paramName] = $desc;
                }
            }
            return $result;
        }

        $pattern = '/@' . preg_quote($tag, '/') . '\s+(\S+)(?:\s+(.*))?/m';
        if (preg_match_all($pattern, $doc, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                $value = trim($match[2] ?? '');
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
