<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Dispatcher;

use ReflectionClass;
use ReflectionMethod;

final class HandlerRegistry
{
    /** @var array<string, array{class: string, method: string, file: string, descriptor?: bool}> */
    private array $handlers = [];

    /** @var array<string, ProcedureDescriptor> */
    private array $descriptors = [];

    /**
     * @param array<int, string> $handlerPaths
     */
    public function __construct(
        private readonly array $handlerPaths,
        private readonly string $namespace,
        private readonly string $separator = '.',
    ) {}

    /**
     * @return array<string, array{class: string, method: string, file: string, descriptor?: bool}>
     */
    public function discover(): array
    {
        $descriptors = $this->descriptors;
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

        foreach ($descriptors as $method => $descriptor) {
            $this->descriptors[$method] = $descriptor;
            $this->handlers[$method] = [
                'class' => $descriptor->handlerClass,
                'method' => $descriptor->handlerMethod,
                'file' => '',
                'descriptor' => true,
            ];
        }

        return $this->handlers;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function register(string $method, string $handlerClass, string $handlerMethod, array $metadata = []): void
    {
        $this->descriptors[$method] = new ProcedureDescriptor(
            method: $method,
            handlerClass: $handlerClass,
            handlerMethod: $handlerMethod,
            metadata: $metadata,
        );

        $this->handlers[$method] = [
            'class' => $handlerClass,
            'method' => $handlerMethod,
            'file' => '',
            'descriptor' => true,
        ];
    }

    public function registerDescriptor(ProcedureDescriptor $descriptor): void
    {
        $this->register(
            $descriptor->method,
            $descriptor->handlerClass,
            $descriptor->handlerMethod,
            $descriptor->metadata,
        );
    }

    /**
     * @param array<int, ProcedureDescriptor> $descriptors
     */
    public function registerDescriptors(array $descriptors): void
    {
        foreach ($descriptors as $descriptor) {
            $this->registerDescriptor($descriptor);
        }
    }

    /**
     * @return array<string, array{class: string, method: string, file: string, descriptor?: bool}>
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }

    /**
     * @return array<string, ProcedureDescriptor>
     */
    public function getDescriptors(): array
    {
        return $this->descriptors;
    }

    public function getDescriptor(string $method): ?ProcedureDescriptor
    {
        return $this->descriptors[$method] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function getMethodNames(): array
    {
        return array_keys($this->handlers);
    }

    public function hasMethod(string $method): bool
    {
        return isset($this->handlers[$method]);
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
