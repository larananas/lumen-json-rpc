<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Dispatcher;

use Lumen\JsonRpc\Exception\InternalErrorException;
use Lumen\JsonRpc\Exception\InvalidParamsException;
use Lumen\JsonRpc\Exception\JsonRpcException;
use Lumen\JsonRpc\Exception\MethodNotFoundException;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Support\RequestContext;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

final class HandlerDispatcher
{
    public function __construct(
        private readonly MethodResolver $resolver,
        private readonly ParameterBinder $parameterBinder,
        private readonly ?HandlerRegistry $registry = null,
    ) {}

    public function dispatch(Request $request, RequestContext $context): mixed
    {
        $resolution = $this->resolver->resolve($request->method);

        if ($resolution === null) {
            throw new MethodNotFoundException("Method not found: {$request->method}");
        }

        if ($this->registry !== null) {
            $handlers = $this->registry->getHandlers();
            if (!isset($handlers[$request->method])) {
                throw new MethodNotFoundException("Method not found: {$request->method}");
            }
        }

        if (!class_exists($resolution->className)) {
            require_once $resolution->fullPath;
        }

        if (!class_exists($resolution->className)) {
            throw new MethodNotFoundException("Handler class not found: {$resolution->className}");
        }

        $instance = $this->createInstance($resolution->className, $context);
        $reflection = new ReflectionClass($instance);

        if (!$reflection->hasMethod($resolution->methodName)) {
            throw new MethodNotFoundException("Method not found: {$resolution->methodName}");
        }

        $method = $reflection->getMethod($resolution->methodName);

        $this->validateMethod($method, $resolution->methodName);

        $args = $this->parameterBinder->bind($method, $request->params, $context);

        try {
            return $method->invokeArgs($instance, $args);
        } catch (JsonRpcException $e) {
            throw $e;
        } catch (\TypeError $e) {
            throw new InvalidParamsException($e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new InternalErrorException(
                $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    private function validateMethod(ReflectionMethod $method, string $name): void
    {
        if (!$method->isPublic()) {
            throw new MethodNotFoundException("Method not accessible: $name");
        }

        if ($method->isStatic()) {
            throw new MethodNotFoundException("Static methods are not callable: $name");
        }

        if (str_starts_with($name, '__')) {
            throw new MethodNotFoundException("Magic methods are not callable: $name");
        }

        $declaringClass = $method->getDeclaringClass()->getName();
        if ($declaringClass === 'stdClass' || $declaringClass === \Closure::class) {
            throw new MethodNotFoundException("Invalid handler method: $name");
        }
    }

    private function createInstance(string $className, RequestContext $context): object
    {
        $reflection = new ReflectionClass($className);

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            // No constructor, can instantiate without arguments
            $instance = $reflection->newInstance();
        } else {
            $params = $constructor->getParameters();

            if ($params === []) {
                // Constructor exists but has no parameters
                $instance = $reflection->newInstance();
            } else {
                $firstParam = $params[0];
                $firstParamType = $firstParam->getType();

                // Check if first parameter accepts RequestContext
                if ($firstParamType instanceof \ReflectionNamedType) {
                    $typeName = $firstParamType->getName();
                    if ($typeName === RequestContext::class || is_a($typeName, RequestContext::class, true)) {
                        $instance = $reflection->newInstance($context);
                    } elseif ($firstParam->isOptional()) {
                        // First param is not RequestContext but is optional, try without args
                        $instance = $reflection->newInstance();
                    } else {
                        throw new MethodNotFoundException(
                            "Handler class '{$className}' constructor is not compatible with required signature"
                        );
                    }
                } elseif ($firstParam->isOptional()) {
                    // No type hint on first param, but it's optional
                    $instance = $reflection->newInstance();
                } else {
                    throw new MethodNotFoundException(
                        "Handler class '{$className}' constructor is not compatible with required signature"
                    );
                }
            }
        }

        if ($this->registry !== null && method_exists($instance, 'setRegistry')) {
            $instance->setRegistry($this->registry);
        }

        return $instance;
    }
}
