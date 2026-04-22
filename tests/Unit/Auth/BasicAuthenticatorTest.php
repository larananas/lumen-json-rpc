<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Auth;

use Lumen\JsonRpc\Auth\BasicAuthenticator;
use PHPUnit\Framework\TestCase;

final class BasicAuthenticatorTest extends TestCase
{
    public function testValidBasicAuthReturnsUserContext(): void
    {
        $auth = new BasicAuthenticator(
            header: 'Authorization',
            users: [
                'admin' => [
                    'password' => 'secret',
                    'user_id' => 'admin',
                    'roles' => ['admin'],
                    'claims' => ['auth_driver' => 'basic'],
                ],
            ],
        );

        $encoded = base64_encode('admin:secret');
        $result = $auth->authenticateFromHeaders(['Authorization' => "Basic {$encoded}"]);
        $this->assertNotNull($result);
        $this->assertEquals('admin', $result->userId);
        $this->assertEquals(['admin'], $result->roles);
    }

    public function testWrongPasswordReturnsNull(): void
    {
        $auth = new BasicAuthenticator(
            header: 'Authorization',
            users: [
                'admin' => [
                    'password' => 'secret',
                    'user_id' => 'admin',
                    'roles' => ['admin'],
                ],
            ],
        );

        $encoded = base64_encode('admin:wrong');
        $this->assertNull($auth->authenticateFromHeaders(['Authorization' => "Basic {$encoded}"]));
    }

    public function testPasswordHashReturnsUserContext(): void
    {
        $auth = new BasicAuthenticator(
            header: 'Authorization',
            users: [
                'admin' => [
                    'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
                    'user_id' => 'admin',
                    'roles' => ['admin'],
                ],
            ],
        );

        $encoded = base64_encode('admin:secret');
        $result = $auth->authenticateFromHeaders(['Authorization' => "Basic {$encoded}"]);
        $this->assertNotNull($result);
        $this->assertSame('admin', $result->userId);
        $this->assertSame(['admin'], $result->roles);
    }

    public function testUnknownUserReturnsNull(): void
    {
        $auth = new BasicAuthenticator(
            header: 'Authorization',
            users: [
                'admin' => ['password' => 'secret', 'user_id' => 'admin'],
            ],
        );

        $encoded = base64_encode('unknown:secret');
        $this->assertNull($auth->authenticateFromHeaders(['Authorization' => "Basic {$encoded}"]));
    }

    public function testMissingHeaderReturnsNull(): void
    {
        $auth = new BasicAuthenticator(
            header: 'Authorization',
            users: ['admin' => ['password' => 'secret', 'user_id' => 'admin']],
        );
        $this->assertNull($auth->authenticateFromHeaders([]));
    }

    public function testNonBasicHeaderReturnsNull(): void
    {
        $auth = new BasicAuthenticator(
            header: 'Authorization',
            users: ['admin' => ['password' => 'secret', 'user_id' => 'admin']],
        );
        $this->assertNull($auth->authenticateFromHeaders(['Authorization' => 'Bearer token123']));
    }

    public function testMalformedBasicHeaderReturnsNull(): void
    {
        $auth = new BasicAuthenticator(
            header: 'Authorization',
            users: ['admin' => ['password' => 'secret', 'user_id' => 'admin']],
        );
        $this->assertNull($auth->authenticateFromHeaders(['Authorization' => 'Basic not-valid-base64!!!']));
    }

    public function testNoColonInCredentialsReturnsNull(): void
    {
        $auth = new BasicAuthenticator(
            header: 'Authorization',
            users: ['admin' => ['password' => 'secret', 'user_id' => 'admin']],
        );
        $encoded = base64_encode('nocol');
        $this->assertNull($auth->authenticateFromHeaders(['Authorization' => "Basic {$encoded}"]));
    }

    public function testUserIdDefaultsToUsername(): void
    {
        $auth = new BasicAuthenticator(
            header: 'Authorization',
            users: [
                'myuser' => ['password' => 'pass'],
            ],
        );

        $encoded = base64_encode('myuser:pass');
        $result = $auth->authenticateFromHeaders(['Authorization' => "Basic {$encoded}"]);
        $this->assertNotNull($result);
        $this->assertEquals('myuser', $result->userId);
    }
}
