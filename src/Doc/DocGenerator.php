<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Doc;

use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionNamedType;

final class DocGenerator
{
    public function __construct(
        private readonly HandlerRegistry $registry,
    ) {}

    public function generate(): array
    {
        $handlers = $this->registry->discover();
        $docs = [];

        foreach ($handlers as $methodName => $handlerInfo) {
            $docs[] = $this->documentMethod($methodName, $handlerInfo);
        }

        return $docs;
    }

    private function documentMethod(string $methodName, array $handlerInfo): MethodDoc
    {
        if (!class_exists($handlerInfo['class'])) {
            require_once $handlerInfo['file'];
        }

        $reflection = new ReflectionClass($handlerInfo['class']);
        if (!$reflection->hasMethod($handlerInfo['method'])) {
            return new MethodDoc(name: $methodName);
        }

        $method = $reflection->getMethod($handlerInfo['method']);
        $phpDoc = $method->getDocComment() ?: '';
        $classDoc = $reflection->getDocComment() ?: '';

        $description = $this->parseDescription($phpDoc);
        $params = $this->parseParams($method, $phpDoc);
        $returnType = $this->parseReturnType($method, $phpDoc);
        $returnDesc = $this->parseReturnDescription($phpDoc);
        $requiresAuth = $this->parseAuthRequirement($phpDoc, $classDoc);
        $errors = $this->parseErrors($phpDoc);
        $exampleRequest = $this->parseExample($phpDoc, 'request');
        $exampleResponse = $this->parseExample($phpDoc, 'response');

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
        );
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

    private function parseParams(ReflectionMethod $method, string $doc): array
    {
        $params = [];
        $docParams = $this->extractDocTags($doc, 'param');

        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if ($name === 'context' && ($param->getType()?->getName() ?? '') === \Lumen\JsonRpc\Support\RequestContext::class) {
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
        return (string)$type;
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
        $tag = "@example-$type";
        $pos = strpos($doc, $tag);
        if ($pos === false) {
            return null;
        }

        $after = substr($doc, $pos + strlen($tag));
        $after = ltrim($after);

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
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $extracted;
                    }
                    return null;
                }
            }
        }

        return null;
    }

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
