<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class CoreDirectAutoAuthTest extends TestCase
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

    public function testCoreDirectJwtAuthFromHeadersWithoutManualAuthenticateCall(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'jwt',
                'protected_methods' => ['system.'],
                'jwt' => ['secret' => 'test-secret'],
            ],
        ]);
        $engine = $server->getEngine();

        $token = $this->createJwtToken([
            'sub' => 'user-1',
            'exp' => time() + 3600,
        ]);

        $context = new RequestContext(
            correlationId: 'auto-jwt-test',
            headers: ['Authorization' => "Bearer {$token}"],
            clientIp: '127.0.0.1',
        );

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $engine->handleJson($json, $context);

        $data = json_decode($result->json, true);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testCoreDirectApiKeyAuthFromHeadersWithoutManualAuthenticateCall(): void
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
        $engine = $server->getEngine();

        $context = new RequestContext(
            correlationId: 'auto-apikey-test',
            headers: ['X-API-Key' => 'test-key-123'],
            clientIp: '127.0.0.1',
        );

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $engine->handleJson($json, $context);

        $data = json_decode($result->json, true);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testCoreDirectBasicAuthFromHeadersWithoutManualAuthenticateCall(): void
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
        $engine = $server->getEngine();

        $encoded = base64_encode('admin:secret');
        $context = new RequestContext(
            correlationId: 'auto-basic-test',
            headers: ['Authorization' => "Basic {$encoded}"],
            clientIp: '127.0.0.1',
        );

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $engine->handleJson($json, $context);

        $data = json_decode($result->json, true);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testCoreDirectProtectedMethodWithoutHeadersFailsProperly(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'jwt',
                'protected_methods' => ['system.'],
                'jwt' => ['secret' => 'test-secret'],
            ],
        ]);
        $engine = $server->getEngine();

        $context = new RequestContext(
            correlationId: 'auto-no-headers',
            headers: [],
            clientIp: '127.0.0.1',
        );

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $engine->handleJson($json, $context);

        $data = json_decode($result->json, true);
        $this->assertEquals(-32001, $data['error']['code']);
        $this->assertEquals('Authentication required', $data['error']['message']);
    }

    public function testCoreDirectInvalidHeadersFailProperly(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'jwt',
                'protected_methods' => ['system.'],
                'jwt' => ['secret' => 'test-secret'],
            ],
        ]);
        $engine = $server->getEngine();

        $context = new RequestContext(
            correlationId: 'auto-invalid-headers',
            headers: ['Authorization' => 'Bearer invalid.token.here'],
            clientIp: '127.0.0.1',
        );

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $engine->handleJson($json, $context);

        $data = json_decode($result->json, true);
        $this->assertEquals(-32001, $data['error']['code']);
        $this->assertEquals('Authentication required', $data['error']['message']);
    }

    public function testCoreDirectDoesNotOverwriteExistingAuthenticatedContext(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'jwt',
                'protected_methods' => ['system.'],
                'jwt' => ['secret' => 'test-secret'],
            ],
        ]);
        $engine = $server->getEngine();

        $context = new RequestContext(
            correlationId: 'auto-preserve-auth',
            headers: ['Authorization' => 'Bearer some.different.token'],
            clientIp: '127.0.0.1',
            authUserId: 'pre-authenticated-user',
            authClaims: ['preset' => true],
            authRoles: ['admin'],
        );

        $json = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $result = $engine->handleJson($json, $context);

        $data = json_decode($result->json, true);
        $this->assertEquals('ok', $data['result']['status']);
    }
}
