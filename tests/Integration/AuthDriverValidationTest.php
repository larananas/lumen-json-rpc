<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class AuthDriverValidationTest extends TestCase
{
    use IntegrationTestCase;

    protected function setUp(): void
    {
        $this->initHandlerPath();
    }

    public function testJwtDriverIsValid(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'jwt',
                'jwt' => ['secret' => 'test-secret'],
            ],
        ]);
        $this->assertInstanceOf(\Lumen\JsonRpc\Server\JsonRpcServer::class, $server);
    }

    public function testApiKeyDriverIsValid(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'api_key',
                'api_key' => [
                    'keys' => ['valid-key' => ['user_id' => 'user1']],
                ],
            ],
        ]);
        $this->assertInstanceOf(\Lumen\JsonRpc\Server\JsonRpcServer::class, $server);
    }

    public function testBasicDriverIsValid(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'basic',
                'basic' => [
                    'users' => ['admin' => ['password' => 'secret', 'user_id' => 'admin']],
                ],
            ],
        ]);
        $this->assertInstanceOf(\Lumen\JsonRpc\Server\JsonRpcServer::class, $server);
    }

    public function testBasicDriverAcceptsPasswordHashOnly(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'basic',
                'basic' => [
                    'users' => ['admin' => ['password_hash' => password_hash('secret', PASSWORD_DEFAULT)]],
                ],
            ],
        ]);

        $this->assertInstanceOf(\Lumen\JsonRpc\Server\JsonRpcServer::class, $server);
    }

    public function testInvalidDriverThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid auth driver');

        $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'invalid_driver',
                'jwt' => ['secret' => 'test-secret'],
            ],
        ]);
    }

    public function testEmptyDriverThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid auth driver');

        $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => '',
            ],
        ]);
    }

    public function testApiKeyDriverWithEmptyKeysThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('auth.api_key.keys must be set');

        $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'api_key',
                'api_key' => ['keys' => []],
            ],
        ]);
    }

    public function testBasicDriverWithEmptyUsersThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('auth.basic.users must be set');

        $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'basic',
                'basic' => ['users' => []],
            ],
        ]);
    }

    public function testBasicDriverUserWithoutPasswordMaterialThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('auth.basic.users.admin must define either password or password_hash');

        $this->createServer([
            'auth' => [
                'enabled' => true,
                'driver' => 'basic',
                'basic' => [
                    'users' => ['admin' => ['user_id' => 'admin']],
                ],
            ],
        ]);
    }

    public function testAuthDisabledDoesNotValidateDriver(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => false,
                'driver' => 'invalid_driver',
            ],
        ]);
        $this->assertInstanceOf(\Lumen\JsonRpc\Server\JsonRpcServer::class, $server);
    }
}
