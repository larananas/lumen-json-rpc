<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Auth;

use Lumen\JsonRpc\Auth\BasicAuthenticator;
use PHPUnit\Framework\TestCase;

final class BasicAuthenticatorExtendedTest extends TestCase
{
    public function testCaseInsensitiveHeaderLookup(): void
    {
        $auth = new BasicAuthenticator(
            header: 'X-Api-Key',
            users: ['user' => ['password' => 'pass', 'user_id' => 'user1']],
        );

        $encoded = base64_encode('user:pass');
        $result = $auth->authenticateFromHeaders(['x-api-key' => "Basic {$encoded}"]);
        $this->assertNotNull($result);
        $this->assertEquals('user1', $result->userId);
    }

    public function testEmptyEncodedPartReturnsNull(): void
    {
        $auth = new BasicAuthenticator(
            users: ['admin' => ['password' => 'secret']],
        );
        $result = $auth->authenticateFromHeaders(['Authorization' => 'Basic ']);
        $this->assertNull($result);
    }

    public function testInvalidBase64ReturnsNull(): void
    {
        $auth = new BasicAuthenticator(
            users: ['admin' => ['password' => 'secret']],
        );
        $result = $auth->authenticateFromHeaders(['Authorization' => 'Basic !!!invalid!!!']);
        $this->assertNull($result);
    }

    public function testEmptyPasswordWithPlainTextConfig(): void
    {
        $auth = new BasicAuthenticator(
            users: ['admin' => ['password' => '', 'user_id' => 'admin']],
        );

        $encoded = base64_encode('admin:');
        $result = $auth->authenticateFromHeaders(['Authorization' => "Basic {$encoded}"]);
        $this->assertNull($result);
    }

    public function testNoPasswordKeyInConfig(): void
    {
        $auth = new BasicAuthenticator(
            users: ['admin' => ['user_id' => 'admin']],
        );

        $encoded = base64_encode('admin:anything');
        $result = $auth->authenticateFromHeaders(['Authorization' => "Basic {$encoded}"]);
        $this->assertNull($result);
    }

    public function testPasswordHashWithWrongPasswordReturnsNull(): void
    {
        $auth = new BasicAuthenticator(
            users: ['admin' => [
                'password_hash' => password_hash('correct', PASSWORD_DEFAULT),
                'user_id' => 'admin',
            ]],
        );

        $encoded = base64_encode('admin:wrong');
        $result = $auth->authenticateFromHeaders(['Authorization' => "Basic {$encoded}"]);
        $this->assertNull($result);
    }

    public function testPasswordHashWithEmptyStringReturnsNull(): void
    {
        $auth = new BasicAuthenticator(
            users: ['admin' => [
                'password_hash' => '',
                'user_id' => 'admin',
            ]],
        );

        $encoded = base64_encode('admin:whatever');
        $result = $auth->authenticateFromHeaders(['Authorization' => "Basic {$encoded}"]);
        $this->assertNull($result);
    }

    public function testClaimsAndRolesPopulatedFromConfig(): void
    {
        $auth = new BasicAuthenticator(
            users: ['admin' => [
                'password' => 'secret',
                'user_id' => 'admin-user',
                'claims' => ['email' => 'admin@example.com', 'department' => 'IT'],
                'roles' => ['admin', 'superuser'],
            ]],
        );

        $encoded = base64_encode('admin:secret');
        $result = $auth->authenticateFromHeaders(['Authorization' => "Basic {$encoded}"]);
        $this->assertNotNull($result);
        $this->assertEquals('admin-user', $result->userId);
        $this->assertEquals(['email' => 'admin@example.com', 'department' => 'IT'], $result->claims);
        $this->assertEquals(['admin', 'superuser'], $result->roles);
    }

    public function testDefaultClaimsAndRolesWhenNotProvided(): void
    {
        $auth = new BasicAuthenticator(
            users: ['admin' => ['password' => 'secret']],
        );

        $encoded = base64_encode('admin:secret');
        $result = $auth->authenticateFromHeaders(['Authorization' => "Basic {$encoded}"]);
        $this->assertNotNull($result);
        $this->assertEquals('admin', $result->userId);
        $this->assertEquals([], $result->claims);
        $this->assertEquals([], $result->roles);
    }

    public function testNonBasicSchemeReturnsNull(): void
    {
        $auth = new BasicAuthenticator(
            users: ['admin' => ['password' => 'secret']],
        );
        $result = $auth->authenticateFromHeaders(['Authorization' => 'Digest username=admin']);
        $this->assertNull($result);
    }

    public function testPasswordWithColonInPassword(): void
    {
        $auth = new BasicAuthenticator(
            users: ['admin' => ['password' => 'pass:word', 'user_id' => 'admin']],
        );

        $encoded = base64_encode('admin:pass:word');
        $result = $auth->authenticateFromHeaders(['Authorization' => "Basic {$encoded}"]);
        $this->assertNotNull($result);
    }
}
