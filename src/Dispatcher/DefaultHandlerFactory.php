<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Dispatcher;

use Lumen\JsonRpc\Exception\MethodNotFoundException;
use Lumen\JsonRpc\Support\RequestContext;
use ReflectionClass;

final class DefaultHandlerFactory implements HandlerFactoryInterface
{
    public function create(string $className, RequestContext $context): object
    {
        /** @var class-string $className */
        $reflection = new ReflectionClass($className);


        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $params = $constructor->getParameters();

        if ($params === []) {
            return $reflection->newInstance();
        }

        $firstParam = $params[0];
        $firstParamType = $firstParam->getType();

        if ($firstParamType instanceof \ReflectionNamedType) {
            $typeName = $firstParamType->getName();
            if ($typeName === RequestContext::class || is_a($typeName, RequestContext::class, true)) {
                return $reflection->newInstance($context);
            }
            if ($firstParam->isOptional()) {
                return $reflection->newInstance();
            }
            throw new MethodNotFoundException(
                "Handler class '{$className}' constructor is not compatible with required signature"
            );
        }

        if ($firstParam->isOptional()) {
            return $reflection->newInstance();
        }

        throw new MethodNotFoundException(
            "Handler class '{$className}' constructor is not compatible with required signature"
        );
    }
}
