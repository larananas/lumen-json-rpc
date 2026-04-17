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
    private ?HandlerFactoryInterface $factory = null;

    public function __construct(
        private readonly MethodResolver $resolver,
        private readonly ParameterBinder $parameterBinder,
        private readonly ?HandlerRegistry $registry = null,
    ) {}

    public function setFactory(HandlerFactoryInterface $factory): void
    {
        $this->factory = $factory;
    }

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

    public function resolveMethod(string $method): ?MethodResolution
    {
        $resolution = $this->resolver->resolve($method);
        if ($resolution === null) {
            return null;
        }

        if ($this->registry !== null) {
            $handlers = $this->registry->getHandlers();
            if (!isset($handlers[$method])) {
                return null;
            }
        }

        return $resolution;
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
        $factory = $this->factory ?? new DefaultHandlerFactory();
        $instance = $factory->create($className, $context);

        if ($this->registry !== null && method_exists($instance, 'setRegistry')) {
            $instance->setRegistry($this->registry);
        }

        return $instance;
    }
}
