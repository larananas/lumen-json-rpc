<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Dispatcher;

use Lumen\JsonRpc\Exception\InvalidParamsException;
use Lumen\JsonRpc\Support\RequestContext;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionNamedType;
use ReflectionUnionType;

final class ParameterBinder
{
    /**
     * @param array<string, mixed>|array<int, mixed>|null $params
     * @return array<int, mixed>
     */
    public function bind(ReflectionMethod $method, array|null $params, RequestContext $context): array
    {
        $parameters = $method->getParameters();
        $args = [];

        if (empty($parameters)) {
            return $args;
        }

        $firstType = $parameters[0]->getType();
        if ($firstType instanceof ReflectionNamedType && $firstType->getName() === RequestContext::class) {
            $args[] = $context;
            array_shift($parameters);
        }

        if (empty($parameters)) {
            return $args;
        }

        if ($params === null) {
            $params = [];
        }

        $isAssoc = $this->isAssociative($params);

        if ($isAssoc) {
            $paramNames = [];
            foreach ($parameters as $param) {
                $paramNames[] = $param->getName();
            }
            $unknownKeys = array_diff(array_keys($params), $paramNames);
            if (!empty($unknownKeys)) {
                throw new InvalidParamsException('Unknown parameters: ' . implode(', ', $unknownKeys));
            }
        } else {
            if (count($params) > count($parameters)) {
                throw new InvalidParamsException('Too many positional parameters');
            }
        }

        $namedParams = array_combine(array_map('strval', array_keys($params)), $params) ?: [];

        foreach ($parameters as $position => $param) {
            if ($isAssoc) {
                $args[] = $this->bindNamed($param, $namedParams);
            } else {
                $args[] = $this->bindPositional($param, $params, $position);
            }
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bindNamed(ReflectionParameter $param, array $params): mixed
    {
        $name = $param->getName();

        if (array_key_exists($name, $params)) {
            return $this->coerceType($params[$name], $param);
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($param->allowsNull()) {
            return null;
        }

        throw new InvalidParamsException("Missing parameter: $name");
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function bindPositional(ReflectionParameter $param, array $params, int $position): mixed
    {
        if (array_key_exists($position, $params)) {
            return $this->coerceType($params[$position], $param);
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($param->allowsNull()) {
            return null;
        }

        throw new InvalidParamsException("Missing parameter at position $position: {$param->getName()}");
    }

    private function coerceType(mixed $value, ReflectionParameter $param): mixed
    {
        $type = $param->getType();
        if ($type === null) {
            return $value;
        }

        if ($value === null) {
            if ($param->allowsNull()) {
                return null;
            }
            throw new InvalidParamsException("Parameter {$param->getName()} cannot be null");
        }

        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();

            if ($typeName === 'mixed') {
                return $value;
            }

            $scalarMap = [
                'int' => 'is_int',
                'float' => 'is_float',
                'string' => 'is_string',
                'bool' => 'is_bool',
            ];

            if (isset($scalarMap[$typeName])) {
                if ($typeName === 'float' && is_int($value)) {
                    return (float)$value;
                }
                if (!$scalarMap[$typeName]($value)) {
                    $actualType = gettype($value);
                    throw new InvalidParamsException(
                        "Parameter {$param->getName()} expects $typeName, got $actualType"
                    );
                }
            } elseif ($typeName === 'array') {
                if (!is_array($value)) {
                    throw new InvalidParamsException(
                        "Parameter {$param->getName()} expects array, got " . gettype($value)
                    );
                }
            }
        }

        return $value;
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
