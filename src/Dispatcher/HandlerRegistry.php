<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Dispatcher;

use ReflectionClass;
use ReflectionMethod;

final class HandlerRegistry
{
    private array $handlers = [];

    public function __construct(
        private readonly array $handlerPaths,
        private readonly string $namespace,
        private readonly string $separator = '.',
    ) {}

    public function discover(): array
    {
        $this->handlers = [];

        foreach ($this->handlerPaths as $path) {
            $realPath = realpath($path);
            if ($realPath === false || !is_dir($realPath)) {
                continue;
            }

            $files = glob($realPath . DIRECTORY_SEPARATOR . '*.php') ?: [];
            foreach ($files as $file) {
                $baseName = basename($file, '.php');
                $prefix = strtolower($baseName);
                $className = $this->namespace . $baseName;

                if (!class_exists($className)) {
                    require_once $file;
                }

                if (!class_exists($className)) {
                    continue;
                }

                $reflection = new ReflectionClass($className);

                if (!$reflection->isInstantiable()) {
                    continue;
                }

                foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    $methodName = $method->getName();

                    if (str_starts_with($methodName, '__')) {
                        continue;
                    }

                    if ($method->isStatic()) {
                        continue;
                    }

                    if ($method->getDeclaringClass()->getName() !== $className) {
                        continue;
                    }

                    if ($this->hasInternalFrameworkParams($method)) {
                        continue;
                    }

                    $rpcMethod = $prefix . $this->separator . $methodName;
                    $this->handlers[$rpcMethod] = [
                        'class' => $className,
                        'method' => $methodName,
                        'file' => $file,
                    ];
                }
            }
        }

        return $this->handlers;
    }

    public function getHandlers(): array
    {
        return $this->handlers;
    }

    public function getMethodNames(): array
    {
        return array_keys($this->handlers);
    }

    private function hasInternalFrameworkParams(ReflectionMethod $method): bool
    {
        $internalTypes = [
            self::class,
            HandlerRegistry::class,
        ];
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType) {
                $typeName = $type->getName();
                foreach ($internalTypes as $internal) {
                    if (is_a($typeName, $internal, true) || $typeName === $internal) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
