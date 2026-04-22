<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Auth\RequestAuthenticatorInterface;
use Lumen\JsonRpc\Auth\UserContext;
use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Dispatcher\HandlerFactoryInterface;
use Lumen\JsonRpc\Dispatcher\HandlerRegistry;
use Lumen\JsonRpc\Dispatcher\ProcedureDescriptor;
use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\Http\HttpResponse;
use Lumen\JsonRpc\Log\Logger;
use Lumen\JsonRpc\Middleware\MiddlewareInterface;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Protocol\Response;
use Lumen\JsonRpc\RateLimit\RateLimitResult;
use Lumen\JsonRpc\RateLimit\RateLimiterInterface;
use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\HookManager;
use Lumen\JsonRpc\Support\HookPoint;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

final class StablePublicApiTest extends TestCase
{
    use IntegrationTestCase;

    protected function setUp(): void
    {
        $this->initHandlerPath();
    }

    public function testJsonRpcServerStableMethodsKeepDocumentedSignatures(): void
    {
        $reflection = new ReflectionClass(JsonRpcServer::class);

        $this->assertMethodSignature($reflection, '__construct', [
            ['name' => 'config', 'type' => Config::class, 'nullable' => true, 'optional' => true, 'default' => null],
        ]);
        $this->assertMethodSignature($reflection, 'handle', [
            ['name' => 'httpRequest', 'type' => HttpRequest::class],
        ], HttpResponse::class);
        $this->assertMethodSignature($reflection, 'handleJson', [
            ['name' => 'json', 'type' => 'string'],
            ['name' => 'context', 'type' => RequestContext::class, 'nullable' => true, 'optional' => true, 'default' => null],
        ], 'string', true);
        $this->assertMethodSignature($reflection, 'authenticateContext', [
            ['name' => 'context', 'type' => RequestContext::class],
        ], RequestContext::class);
        $this->assertMethodSignature($reflection, 'run', [], 'void');
        $this->assertMethodSignature($reflection, 'setHandlerFactory', [
            ['name' => 'factory', 'type' => HandlerFactoryInterface::class],
        ], 'self');
        $this->assertMethodSignature($reflection, 'addMiddleware', [
            ['name' => 'middleware', 'type' => MiddlewareInterface::class],
        ], 'self');
        $this->assertMethodSignature($reflection, 'setRequestAuthenticator', [
            ['name' => 'authenticator', 'type' => RequestAuthenticatorInterface::class],
        ], 'self');
        $this->assertMethodSignature($reflection, 'setRateLimiter', [
            ['name' => 'limiter', 'type' => RateLimiterInterface::class],
        ], 'self');
        $this->assertMethodSignature($reflection, 'getHooks', [], HookManager::class);
        $this->assertMethodSignature($reflection, 'getLogger', [], Logger::class);
        $this->assertMethodSignature($reflection, 'getRegistry', [], HandlerRegistry::class);
    }

    public function testStableCollaboratorContractsKeepDocumentedSignatures(): void
    {
        $this->assertMethodSignature(new ReflectionClass(HandlerFactoryInterface::class), 'create', [
            ['name' => 'className', 'type' => 'string'],
            ['name' => 'context', 'type' => RequestContext::class],
        ], 'object');

        $this->assertMethodSignature(new ReflectionClass(MiddlewareInterface::class), 'process', [
            ['name' => 'request', 'type' => Request::class],
            ['name' => 'context', 'type' => RequestContext::class],
            ['name' => 'next', 'type' => 'callable'],
        ], Response::class, true);

        $this->assertMethodSignature(new ReflectionClass(RateLimiterInterface::class), 'check', [
            ['name' => 'key', 'type' => 'string'],
        ], RateLimitResult::class);
        $this->assertMethodSignature(new ReflectionClass(RateLimiterInterface::class), 'checkAndConsume', [
            ['name' => 'key', 'type' => 'string'],
            ['name' => 'weight', 'type' => 'int'],
        ], RateLimitResult::class);

        $this->assertMethodSignature(new ReflectionClass(RequestAuthenticatorInterface::class), 'authenticateFromHeaders', [
            ['name' => 'headers', 'type' => 'array'],
        ], UserContext::class, true);

        $registry = new ReflectionClass(HandlerRegistry::class);
        $this->assertMethodSignature($registry, 'register', [
            ['name' => 'method', 'type' => 'string'],
            ['name' => 'handlerClass', 'type' => 'string'],
            ['name' => 'handlerMethod', 'type' => 'string'],
            ['name' => 'metadata', 'type' => 'array', 'optional' => true, 'default' => []],
        ], 'void');
        $this->assertMethodSignature($registry, 'registerDescriptor', [
            ['name' => 'descriptor', 'type' => ProcedureDescriptor::class],
        ], 'void');
        $this->assertMethodSignature($registry, 'registerDescriptors', [
            ['name' => 'descriptors', 'type' => 'array'],
        ], 'void');

        $hooks = new ReflectionClass(HookManager::class);
        $this->assertMethodSignature($hooks, 'register', [
            ['name' => 'point', 'type' => HookPoint::class],
            ['name' => 'callback', 'type' => 'callable'],
            ['name' => 'priority', 'type' => 'int', 'optional' => true, 'default' => 0],
        ], 'void');
    }

    public function testRequestContextStableShapeMatchesDocumentedUsage(): void
    {
        $reflection = new ReflectionClass(RequestContext::class);

        $this->assertMethodSignature($reflection, '__construct', [
            ['name' => 'correlationId', 'type' => 'string'],
            ['name' => 'headers', 'type' => 'array'],
            ['name' => 'clientIp', 'type' => 'string'],
            ['name' => 'authUserId', 'type' => 'string', 'nullable' => true, 'optional' => true, 'default' => null],
            ['name' => 'authClaims', 'type' => 'array', 'optional' => true, 'default' => []],
            ['name' => 'authRoles', 'type' => 'array', 'optional' => true, 'default' => []],
            ['name' => 'rawBody', 'type' => 'string', 'nullable' => true, 'optional' => true, 'default' => null],
            ['name' => 'requestBody', 'type' => 'string', 'nullable' => true, 'optional' => true, 'default' => null],
            ['name' => 'attributes', 'type' => 'array', 'optional' => true, 'default' => []],
        ]);

        $this->assertReadonlyPublicProperty($reflection, 'correlationId', 'string');
        $this->assertReadonlyPublicProperty($reflection, 'headers', 'array');
        $this->assertReadonlyPublicProperty($reflection, 'clientIp', 'string');
        $this->assertReadonlyPublicProperty($reflection, 'authUserId', 'string', true);
        $this->assertReadonlyPublicProperty($reflection, 'authClaims', 'array');
        $this->assertReadonlyPublicProperty($reflection, 'authRoles', 'array');
        $this->assertReadonlyPublicProperty($reflection, 'rawBody', 'string', true);
        $this->assertReadonlyPublicProperty($reflection, 'requestBody', 'string', true);
        $this->assertReadonlyPublicProperty($reflection, 'attributes', 'array');

        $this->assertMethodSignature($reflection, 'hasAuth', [], 'bool');
        $this->assertMethodSignature($reflection, 'hasRole', [
            ['name' => 'role', 'type' => 'string'],
        ], 'bool');
        $this->assertMethodSignature($reflection, 'withAuth', [
            ['name' => 'userId', 'type' => 'string', 'nullable' => true],
            ['name' => 'claims', 'type' => 'array', 'optional' => true, 'default' => []],
            ['name' => 'roles', 'type' => 'array', 'optional' => true, 'default' => []],
        ], 'self');
        $this->assertMethodSignature($reflection, 'getAttribute', [
            ['name' => 'key', 'type' => 'string'],
            ['name' => 'default', 'type' => 'mixed', 'nullable' => true, 'optional' => true, 'default' => null],
        ], 'mixed', true);
        $this->assertMethodSignature($reflection, 'getClaim', [
            ['name' => 'key', 'type' => 'string'],
            ['name' => 'default', 'type' => 'mixed', 'nullable' => true, 'optional' => true, 'default' => null],
        ], 'mixed', true);
    }

    public function testProcedureDescriptorAndHookPointsRemainStable(): void
    {
        $descriptor = new ReflectionClass(ProcedureDescriptor::class);

        $this->assertMethodSignature($descriptor, '__construct', [
            ['name' => 'method', 'type' => 'string'],
            ['name' => 'handlerClass', 'type' => 'string'],
            ['name' => 'handlerMethod', 'type' => 'string'],
            ['name' => 'metadata', 'type' => 'array', 'optional' => true, 'default' => []],
        ]);
        $this->assertReadonlyPublicProperty($descriptor, 'method', 'string');
        $this->assertReadonlyPublicProperty($descriptor, 'handlerClass', 'string');
        $this->assertReadonlyPublicProperty($descriptor, 'handlerMethod', 'string');
        $this->assertReadonlyPublicProperty($descriptor, 'metadata', 'array');
        $this->assertMethodSignature($descriptor, 'toArray', [], 'array');

        $enum = new ReflectionEnum(HookPoint::class);
        $this->assertTrue($enum->isBacked());
        $this->assertSame('string', $enum->getBackingType()?->getName());
        $cases = [];
        foreach (HookPoint::cases() as $case) {
            $cases[$case->name] = $case->value;
        }

        $this->assertSame([
            'BEFORE_REQUEST' => 'before.request',
            'AFTER_REQUEST' => 'after.request',
            'BEFORE_HANDLER' => 'before.handler',
            'AFTER_HANDLER' => 'after.handler',
            'ON_ERROR' => 'on.error',
            'ON_AUTH_SUCCESS' => 'on.auth.success',
            'ON_AUTH_FAILURE' => 'on.auth.failure',
            'ON_RESPONSE' => 'on.response',
        ], $cases);
    }

    public function testStableHandleJsonKeepsNotificationContract(): void
    {
        $server = $this->createServer();

        $result = $server->handleJson('{"jsonrpc":"2.0","method":"system.health"}');

        $this->assertNull($result);
    }

    public function testStableAuthenticateContextAndHandleJsonSupportProtectedDirectUsage(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'jwt',
                'protected_methods' => ['system.'],
                'jwt' => ['secret' => 'test-secret'],
            ],
        ]);

        $token = $this->createJwt([
            'sub' => 'user-1',
            'exp' => time() + 3600,
        ]);

        $context = new RequestContext(
            correlationId: 'stable-direct-auth',
            headers: ['Authorization' => "Bearer {$token}"],
            clientIp: '127.0.0.1',
        );

        $authenticated = $server->authenticateContext($context);

        $this->assertTrue($authenticated->hasAuth());
        $this->assertSame('user-1', $authenticated->authUserId);

        $result = $server->handleJson('{"jsonrpc":"2.0","method":"system.health","id":1}', $authenticated);
        $decoded = json_decode($result, true);

        $this->assertSame('ok', $decoded['result']['status']);
    }

    public function testStableAuthenticateContextDoesNotOverwriteExistingAuth(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'jwt',
                'protected_methods' => ['system.'],
                'jwt' => ['secret' => 'test-secret'],
            ],
        ]);

        $context = new RequestContext(
            correlationId: 'stable-pre-auth',
            headers: ['Authorization' => 'Bearer invalid.token.here'],
            clientIp: '127.0.0.1',
            authUserId: 'preset-user',
            authClaims: ['source' => 'preset'],
            authRoles: ['admin'],
        );

        $authenticated = $server->authenticateContext($context);

        $this->assertTrue($authenticated->hasAuth());
        $this->assertSame('preset-user', $authenticated->authUserId);
        $this->assertSame('preset', $authenticated->getClaim('source'));
        $this->assertTrue($authenticated->hasRole('admin'));
    }

    public function testStableRequestAuthenticatorExtensionPointSupportsDirectJsonUsage(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'jwt',
                'protected_methods' => ['system.'],
                'jwt' => ['secret' => 'test-secret'],
            ],
        ]);

        $returnedServer = $server->setRequestAuthenticator(new class implements RequestAuthenticatorInterface {
            public function authenticateFromHeaders(array $headers): ?UserContext
            {
                if (($headers['X-Internal-Token'] ?? null) !== 'trusted-token') {
                    return null;
                }

                return new UserContext('internal-service', ['source' => 'stable-api'], ['service']);
            }
        });

        $this->assertSame($server, $returnedServer);

        $context = new RequestContext(
            correlationId: 'stable-custom-auth',
            headers: ['X-Internal-Token' => 'trusted-token'],
            clientIp: '127.0.0.1',
        );

        $authenticated = $server->authenticateContext($context);

        $this->assertTrue($authenticated->hasAuth());
        $this->assertSame('internal-service', $authenticated->authUserId);
        $this->assertSame('stable-api', $authenticated->getClaim('source'));

        $result = $server->handleJson('{"jsonrpc":"2.0","method":"system.health","id":1}', $authenticated);
        $decoded = json_decode($result, true);

        $this->assertSame('ok', $decoded['result']['status']);
    }

    public function testStableRegistryAccessorAllowsExplicitProcedureRegistration(): void
    {
        $server = $this->createServer();

        $server->getRegistry()->register('stable.echo', StablePublicApiEchoHandler::class, 'echoValue');

        $result = $server->handleJson('{"jsonrpc":"2.0","method":"stable.echo","params":{"name":"rpc"},"id":1}');
        $decoded = json_decode($result, true);

        $this->assertSame('hello rpc', $decoded['result']['message']);
    }

    /**
     * @param array<int, array{name: string, type: string, nullable?: bool, optional?: bool, default?: mixed}> $expectedParameters
     */
    private function assertMethodSignature(
        ReflectionClass $class,
        string $methodName,
        array $expectedParameters,
        ?string $expectedReturnType = null,
        bool $returnNullable = false,
    ): void {
        $this->assertTrue($class->hasMethod($methodName), sprintf('%s::%s() must exist', $class->getName(), $methodName));

        $method = $class->getMethod($methodName);
        $this->assertTrue($method->isPublic(), sprintf('%s::%s() must stay public', $class->getName(), $methodName));

        $parameters = $method->getParameters();
        $this->assertCount(
            count($expectedParameters),
            $parameters,
            sprintf('%s::%s() parameter count changed', $class->getName(), $methodName),
        );

        foreach ($expectedParameters as $index => $expected) {
            $this->assertParameter($method, $parameters[$index], $expected);
        }

        $returnType = $method->getReturnType();
        if ($expectedReturnType === null) {
            $this->assertNull($returnType, sprintf('%s::%s() must not declare a return type', $class->getName(), $methodName));
            return;
        }

        $this->assertInstanceOf(ReflectionNamedType::class, $returnType, sprintf('%s::%s() must declare a return type', $class->getName(), $methodName));
        $this->assertSame($expectedReturnType, $returnType->getName(), sprintf('%s::%s() return type changed', $class->getName(), $methodName));
        $this->assertSame($returnNullable, $returnType->allowsNull(), sprintf('%s::%s() return nullability changed', $class->getName(), $methodName));
    }

    /**
     * @param array{name: string, type: string, nullable?: bool, optional?: bool, default?: mixed} $expected
     */
    private function assertParameter(ReflectionMethod $method, ReflectionParameter $parameter, array $expected): void
    {
        $this->assertSame($expected['name'], $parameter->getName(), sprintf('%s::%s() parameter name changed', $method->getDeclaringClass()->getName(), $method->getName()));

        $type = $parameter->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type, sprintf('%s::%s() parameter $%s must declare a type', $method->getDeclaringClass()->getName(), $method->getName(), $parameter->getName()));
        $this->assertSame($expected['type'], $type->getName(), sprintf('%s::%s() parameter $%s type changed', $method->getDeclaringClass()->getName(), $method->getName(), $parameter->getName()));
        $this->assertSame($expected['nullable'] ?? false, $type->allowsNull(), sprintf('%s::%s() parameter $%s nullability changed', $method->getDeclaringClass()->getName(), $method->getName(), $parameter->getName()));
        $this->assertSame($expected['optional'] ?? false, $parameter->isOptional(), sprintf('%s::%s() parameter $%s optionality changed', $method->getDeclaringClass()->getName(), $method->getName(), $parameter->getName()));

        if (array_key_exists('default', $expected)) {
            $this->assertTrue($parameter->isDefaultValueAvailable(), sprintf('%s::%s() parameter $%s default was removed', $method->getDeclaringClass()->getName(), $method->getName(), $parameter->getName()));
            $this->assertSame($expected['default'], $parameter->getDefaultValue(), sprintf('%s::%s() parameter $%s default changed', $method->getDeclaringClass()->getName(), $method->getName(), $parameter->getName()));
        }
    }

    private function assertReadonlyPublicProperty(ReflectionClass $class, string $propertyName, string $typeName, bool $nullable = false): void
    {
        $this->assertTrue($class->hasProperty($propertyName), sprintf('%s::$%s must exist', $class->getName(), $propertyName));

        $property = $class->getProperty($propertyName);
        $this->assertTrue($property->isPublic(), sprintf('%s::$%s must stay public', $class->getName(), $propertyName));
        $this->assertTrue($property->isReadOnly(), sprintf('%s::$%s must stay readonly', $class->getName(), $propertyName));

        $type = $property->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type, sprintf('%s::$%s must declare a type', $class->getName(), $propertyName));
        $this->assertSame($typeName, $type->getName(), sprintf('%s::$%s type changed', $class->getName(), $propertyName));
        $this->assertSame($nullable, $type->allowsNull(), sprintf('%s::$%s nullability changed', $class->getName(), $propertyName));
    }
}

final class StablePublicApiEchoHandler
{
    /**
     * @return array{message: string}
     */
    public function echoValue(string $name): array
    {
        return ['message' => 'hello ' . $name];
    }
}
