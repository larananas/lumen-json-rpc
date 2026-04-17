<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Auth;

use Lumen\JsonRpc\Auth\JwtAuthenticator;
use Lumen\JsonRpc\Auth\UserContext;
use PHPUnit\Framework\TestCase;

final class JwtAuthenticatorTest extends TestCase
{
    private function createTestToken(array $payload, string $algorithm = 'HS256', string $secret = 'test-secret'): string
    {
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => $algorithm]));
        $payloadB64 = $this->base64UrlEncode(json_encode($payload));
        $signature = $this->computeSignature($header, $payloadB64, $algorithm, $secret);
        return "$header.$payloadB64.$signature";
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function computeSignature(string $header, string $payload, string $algorithm, string $secret): string
    {
        $map = ['HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512'];
        $hashAlg = $map[$algorithm] ?? 'sha256';
        return $this->base64UrlEncode(hash_hmac($hashAlg, "$header.$payload", $secret, true));
    }

    public function testValidTokenReturnsUserContext(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $token = $this->createTestToken([
            'sub' => 'user-123',
            'name' => 'Test User',
            'roles' => ['admin'],
        ]);
        $user = $auth->authenticate($token);
        $this->assertNotNull($user);
        $this->assertEquals('user-123', $user->userId);
        $this->assertEquals(['admin'], $user->roles);
    }

    public function testInvalidSignatureReturnsNull(): void
    {
        $auth = new JwtAuthenticator('wrong-secret');
        $token = $this->createTestToken(['sub' => 'user-123']);
        $user = $auth->authenticate($token);
        $this->assertNull($user);
    }

    public function testExpiredTokenReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $token = $this->createTestToken([
            'sub' => 'user-123',
            'exp' => time() - 3600,
        ]);
        $user = $auth->authenticate($token);
        $this->assertNull($user);
    }

    public function testNbfInFutureReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $token = $this->createTestToken([
            'sub' => 'user-123',
            'nbf' => time() + 3600,
        ]);
        $user = $auth->authenticate($token);
        $this->assertNull($user);
    }

    public function testIssuerValidation(): void
    {
        $auth = new JwtAuthenticator('test-secret', issuer: 'myapp');
        $token = $this->createTestToken([
            'sub' => 'user-123',
            'iss' => 'myapp',
        ]);
        $user = $auth->authenticate($token);
        $this->assertNotNull($user);
    }

    public function testIssuerMismatchReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret', issuer: 'myapp');
        $token = $this->createTestToken([
            'sub' => 'user-123',
            'iss' => 'otherapp',
        ]);
        $user = $auth->authenticate($token);
        $this->assertNull($user);
    }

    public function testAudienceMismatchReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret', audience: 'myapi');
        $token = $this->createTestToken([
            'sub' => 'user-123',
            'aud' => 'otherapi',
        ]);
        $user = $auth->authenticate($token);
        $this->assertNull($user);
    }

    public function testAudienceMatchSucceeds(): void
    {
        $auth = new JwtAuthenticator('test-secret', audience: 'myapi');
        $token = $this->createTestToken([
            'sub' => 'user-123',
            'aud' => 'myapi',
        ]);
        $user = $auth->authenticate($token);
        $this->assertNotNull($user);
    }

    public function testTokenWithoutSubjectReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $token = $this->createTestToken(['name' => 'No Subject']);
        $user = $auth->authenticate($token);
        $this->assertNull($user);
    }

    public function testMalformedTokenReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $this->assertNull($auth->authenticate('not-a-jwt'));
        $this->assertNull($auth->authenticate(''));
        $this->assertNull($auth->authenticate('a.b'));
    }

    public function testUserContextHasRole(): void
    {
        $user = new UserContext('1', [], ['admin', 'user']);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('superadmin'));
    }

    public function testAlgorithmMismatchReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret', algorithm: 'HS256');
        $token = $this->createTestToken(['sub' => 'user-123'], 'HS512', 'test-secret');
        $user = $auth->authenticate($token);
        $this->assertNull($user);
    }

    public function testAlgorithmNoneReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'none']));
        $payload = $this->base64UrlEncode(json_encode(['sub' => 'user-123']));
        $token = "$header.$payload.";
        $user = $auth->authenticate($token);
        $this->assertNull($user);
    }

    public function testUnsupportedAlgorithmReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'RS256']));
        $payload = $this->base64UrlEncode(json_encode(['sub' => 'user-123']));
        $token = "$header.$payload.fakesig";
        $user = $auth->authenticate($token);
        $this->assertNull($user);
    }

    public function testConfiguredHS384Works(): void
    {
        $auth = new JwtAuthenticator('test-secret', algorithm: 'HS384');
        $token = $this->createTestToken(['sub' => 'user-123'], 'HS384', 'test-secret');
        $user = $auth->authenticate($token);
        $this->assertNotNull($user);
        $this->assertEquals('user-123', $user->userId);
    }

    public function testConfiguredHS512Works(): void
    {
        $auth = new JwtAuthenticator('test-secret', algorithm: 'HS512');
        $token = $this->createTestToken(['sub' => 'user-123'], 'HS512', 'test-secret');
        $user = $auth->authenticate($token);
        $this->assertNotNull($user);
        $this->assertEquals('user-123', $user->userId);
    }

    public function testMissingTypReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256']));
        $payload = $this->base64UrlEncode(json_encode(['sub' => 'user-123']));
        $sig = $this->computeSignature($header, $payload, 'HS256', 'test-secret');
        $token = "$header.$payload.$sig";
        $user = $auth->authenticate($token);
        $this->assertNull($user);
    }

    public function testUserContextGetClaim(): void
    {
        $user = new UserContext('1', ['email' => 'test@example.com', 'name' => 'Test'], []);
        $this->assertEquals('test@example.com', $user->getClaim('email'));
        $this->assertEquals('Test', $user->getClaim('name'));
        $this->assertNull($user->getClaim('nonexistent'));
        $this->assertEquals('default', $user->getClaim('nonexistent', 'default'));
    }
}
