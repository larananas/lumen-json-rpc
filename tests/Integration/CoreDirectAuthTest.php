<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Auth\RequestAuthenticatorInterface;
use Lumen\JsonRpc\Auth\UserContext;
use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class CoreDirectAuthTest extends TestCase
{
    use IntegrationTestCase;

    protected function setUp(): void
    {
        $this->initHandlerPath();
    }

    private function createJwtToken(array $payload, string $secret = 'test-secret'): string
    {
        $b64 = fn(string $data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $header = $b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payloadB64 = $b64(json_encode($payload));
        $sig = $b64(hash_hmac('sha256', "$header.$payloadB64", $secret, true));
        return "$header.$payloadB64.$sig";
    }

    public function testCoreDirectJwtAuthFromHeaders(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'jwt',
                'protected_methods' => ['system.'],
                'jwt' => ['secret' => 'test-secret'],
            ],
        ]);
        $token = $this->createJwtToken([
            'sub' => 'user-1',
            'exp' => time() + 3600,
        ]);

        $context = new RequestContext(
            correlationId: 'direct-jwt-test',
            headers: ['Authorization' => "Bearer {$token}"],
            clientIp: '127.0.0.1',
        );
        $context = $server->authenticateContext($context);

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $server->handleJson($json, $context);

        $data = json_decode($result, true);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testCoreDirectProtectedMethodWithoutHeadersFails(): void
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
            correlationId: 'direct-no-auth',
            headers: [],
            clientIp: '127.0.0.1',
        );

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $server->handleJson($json, $context);

        $data = json_decode($result, true);
        $this->assertEquals(-32001, $data['error']['code']);
    }

    public function testCoreDirectApiKeyAuthFromHeaders(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'api_key',
                'protected_methods' => ['system.'],
                'api_key' => [
                    'header' => 'X-API-Key',
                    'keys' => [
                        'test-key-123' => ['user_id' => 'svc-1', 'roles' => ['service']],
                    ],
                ],
            ],
        ]);
        $context = new RequestContext(
            correlationId: 'direct-apikey-test',
            headers: ['X-API-Key' => 'test-key-123'],
            clientIp: '127.0.0.1',
        );
        $context = $server->authenticateContext($context);

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $server->handleJson($json, $context);

        $data = json_decode($result, true);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testCoreDirectBasicAuthFromHeaders(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'basic',
                'protected_methods' => ['system.'],
                'basic' => [
                    'users' => [
                        'admin' => ['password' => 'secret', 'user_id' => 'admin', 'roles' => ['admin']],
                    ],
                ],
            ],
        ]);
        $encoded = base64_encode('admin:secret');
        $context = new RequestContext(
            correlationId: 'direct-basic-test',
            headers: ['Authorization' => "Basic {$encoded}"],
            clientIp: '127.0.0.1',
        );
        $context = $server->authenticateContext($context);

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $server->handleJson($json, $context);

        $data = json_decode($result, true);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testCoreDirectInvalidTokenReturnsProperContext(): void
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
            correlationId: 'direct-bad-token',
            headers: ['Authorization' => 'Bearer invalid.token.here'],
            clientIp: '127.0.0.1',
        );
        $context = $server->authenticateContext($context);

        $this->assertFalse($context->hasAuth());

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $server->handleJson($json, $context);

        $data = json_decode($result, true);
        $this->assertEquals(-32001, $data['error']['code']);
    }

    public function testCustomRequestAuthenticatorCanBeSetViaStableServerApi(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'jwt',
                'protected_methods' => ['system.'],
                'jwt' => ['secret' => 'test-secret'],
            ],
        ]);

        $server->setRequestAuthenticator(new class implements RequestAuthenticatorInterface {
            public function authenticateFromHeaders(array $headers): ?UserContext
            {
                if (($headers['X-Internal-Token'] ?? null) !== 'trusted-token') {
                    return null;
                }

                return new UserContext('internal-service', ['source' => 'internal'], ['service']);
            }
        });

        $context = new RequestContext(
            correlationId: 'direct-custom-auth',
            headers: ['X-Internal-Token' => 'trusted-token'],
            clientIp: '127.0.0.1',
        );
        $context = $server->authenticateContext($context);

        $this->assertTrue($context->hasAuth());
        $this->assertSame('internal-service', $context->authUserId);
        $this->assertSame('internal', $context->getClaim('source'));

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $server->handleJson($json, $context);
        $data = json_decode($result, true);

        $this->assertEquals('ok', $data['result']['status']);
    }
}
