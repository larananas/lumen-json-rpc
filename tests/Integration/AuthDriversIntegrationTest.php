<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Server\JsonRpcServer;
use PHPUnit\Framework\TestCase;

final class AuthDriversIntegrationTest extends TestCase
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

    public function testJwtDriverAuthenticates(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'jwt',
                'protected_methods' => ['system.'],
                'jwt' => [
                    'secret' => 'test-secret',
                    'algorithm' => 'HS256',
                    'header' => 'Authorization',
                    'prefix' => 'Bearer ',
                ],
            ],
        ]);

        $token = $this->createJwtToken([
            'sub' => 'user-1',
            'roles' => ['user'],
            'exp' => time() + 3600,
        ]);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', [
            'Authorization' => "Bearer {$token}",
        ]));
        $data = json_decode($response->body, true);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testJwtProtectedMethodWithoutTokenFails(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'jwt',
                'protected_methods' => ['system.'],
                'jwt' => ['secret' => 'test-secret'],
            ],
        ]);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32001, $data['error']['code']);
    }

    public function testApiKeyDriverAuthenticates(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'api_key',
                'protected_methods' => ['system.'],
                'api_key' => [
                    'header' => 'X-API-Key',
                    'keys' => [
                        'demo-key-123' => [
                            'user_id' => 'demo-service',
                            'roles' => ['service'],
                            'claims' => ['auth_driver' => 'api_key'],
                        ],
                    ],
                ],
            ],
        ]);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', [
            'X-API-Key' => 'demo-key-123',
        ]));
        $data = json_decode($response->body, true);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testApiKeyInvalidKeyFails(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'api_key',
                'protected_methods' => ['system.'],
                'api_key' => [
                    'header' => 'X-API-Key',
                    'keys' => [
                        'valid-key' => ['user_id' => 'user'],
                    ],
                ],
            ],
        ]);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', [
            'X-API-Key' => 'wrong-key',
        ]));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32001, $data['error']['code']);
    }

    public function testBasicDriverAuthenticates(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'basic',
                'protected_methods' => ['system.'],
                'basic' => [
                    'users' => [
                        'admin' => [
                            'password' => 'secret',
                            'user_id' => 'admin',
                            'roles' => ['admin'],
                        ],
                    ],
                ],
            ],
        ]);

        $encoded = base64_encode('admin:secret');
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', [
            'Authorization' => "Basic {$encoded}",
        ]));
        $data = json_decode($response->body, true);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testBasicWrongPasswordFails(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'basic',
                'protected_methods' => ['system.'],
                'basic' => [
                    'users' => [
                        'admin' => [
                            'password' => 'secret',
                            'user_id' => 'admin',
                        ],
                    ],
                ],
            ],
        ]);

        $encoded = base64_encode('admin:wrong');
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', [
            'Authorization' => "Basic {$encoded}",
        ]));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32001, $data['error']['code']);
    }

    public function testAuthDisabledAllowsAll(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => false,
            ],
        ]);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals('ok', $data['result']['status']);
    }
}
