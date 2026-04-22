<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Auth;

use Lumen\JsonRpc\Auth\JwtAuthenticator;
use PHPUnit\Framework\TestCase;

final class JwtAuthenticatorExtendedTest extends TestCase
{
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function createToken(array $payload, string $algorithm = 'HS256', string $secret = 'test-secret'): string
    {
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => $algorithm]));
        $payloadB64 = $this->base64UrlEncode(json_encode($payload));
        $map = ['HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512'];
        $sig = $this->base64UrlEncode(hash_hmac($map[$algorithm] ?? 'sha256', "$header.$payloadB64", $secret, true));
        return "$header.$payloadB64.$sig";
    }

    public function testIatInFutureReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $token = $this->createToken([
            'sub' => 'user-123',
            'iat' => time() + 3600,
        ]);
        $this->assertNull($auth->authenticate($token));
    }

    public function testLeewayAllowsSlightlyExpiredToken(): void
    {
        $auth = new JwtAuthenticator('test-secret', leeway: 120);
        $token = $this->createToken([
            'sub' => 'user-123',
            'exp' => time() - 60,
        ]);
        $user = $auth->authenticate($token);
        $this->assertNotNull($user);
        $this->assertEquals('user-123', $user->userId);
    }

    public function testNonStringNonIntSubjectReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $token = $this->createToken([
            'sub' => ['nested' => 'object'],
        ]);
        $this->assertNull($auth->authenticate($token));
    }

    public function testEmptyStringSubjectReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $token = $this->createToken([
            'sub' => '',
        ]);
        $this->assertNull($auth->authenticate($token));
    }

    public function testUserIdFallbackFromSubToUserId(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $token = $this->createToken([
            'user_id' => 'alt-user-123',
        ]);
        $user = $auth->authenticate($token);
        $this->assertNotNull($user);
        $this->assertEquals('alt-user-123', $user->userId);
    }

    public function testNonArrayRolesIgnored(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $token = $this->createToken([
            'sub' => 'user-123',
            'roles' => 'not-an-array',
        ]);
        $user = $auth->authenticate($token);
        $this->assertNotNull($user);
        $this->assertEquals([], $user->roles);
    }

    public function testRolesFilteredToStrings(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $token = $this->createToken([
            'sub' => 'user-123',
            'roles' => ['admin', 123, true, 'user'],
        ]);
        $user = $auth->authenticate($token);
        $this->assertNotNull($user);
        $this->assertEquals(['admin', 'user'], $user->roles);
    }

    public function testAudienceAsArrayMatch(): void
    {
        $auth = new JwtAuthenticator('test-secret', audience: 'myapi');
        $token = $this->createToken([
            'sub' => 'user-123',
            'aud' => ['otherapi', 'myapi'],
        ]);
        $user = $auth->authenticate($token);
        $this->assertNotNull($user);
    }

    public function testAudienceAsArrayMismatch(): void
    {
        $auth = new JwtAuthenticator('test-secret', audience: 'myapi');
        $token = $this->createToken([
            'sub' => 'user-123',
            'aud' => ['otherapi', 'thirdapi'],
        ]);
        $this->assertNull($auth->authenticate($token));
    }

    public function testAudienceNonStringNonArrayReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret', audience: 'myapi');
        $token = $this->createToken([
            'sub' => 'user-123',
            'aud' => 12345,
        ]);
        $this->assertNull($auth->authenticate($token));
    }

    public function testMalformedTokenWithTwoParts(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $this->assertNull($auth->authenticate('a.b'));
    }

    public function testMalformedTokenWithOnePart(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $this->assertNull($auth->authenticate('justone'));
    }

    public function testInvalidBase64InHeaderReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $this->assertNull($auth->authenticate('!!!invalid!!!.payload.signature'));
    }

    public function testInvalidBase64InPayloadReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $this->assertNull($auth->authenticate("{$header}.!!!invalid!!!.signature"));
    }

    public function testInvalidJsonInHeader(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $header = $this->base64UrlEncode('not-json');
        $payload = $this->base64UrlEncode(json_encode(['sub' => 'user']));
        $this->assertNull($auth->authenticate("{$header}.{$payload}.sig"));
    }

    public function testEmptyTypReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $header = $this->base64UrlEncode(json_encode(['typ' => '', 'alg' => 'HS256']));
        $payload = $this->base64UrlEncode(json_encode(['sub' => 'user-123']));
        $sig = $this->base64UrlEncode(hash_hmac('sha256', "{$header}.{$payload}", 'test-secret', true));
        $this->assertNull($auth->authenticate("{$header}.{$payload}.{$sig}"));
    }

    public function testEmptyAlgReturnsNull(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => '']));
        $payload = $this->base64UrlEncode(json_encode(['sub' => 'user-123']));
        $this->assertNull($auth->authenticate("{$header}.{$payload}.sig"));
    }

    public function testClaimsPopulatedFromPayload(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $token = $this->createToken([
            'sub' => 'user-123',
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);
        $user = $auth->authenticate($token);
        $this->assertNotNull($user);
        $this->assertEquals('test@example.com', $user->claims['email']);
        $this->assertEquals('Test User', $user->claims['name']);
    }

    public function testIntSubjectCastToString(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $token = $this->createToken([
            'sub' => 42,
        ]);
        $user = $auth->authenticate($token);
        $this->assertNotNull($user);
        $this->assertEquals('42', $user->userId);
    }

    public function testIssuerNotSetInConfigAcceptsAnyIssuer(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $token = $this->createToken([
            'sub' => 'user-123',
            'iss' => 'any-issuer',
        ]);
        $user = $auth->authenticate($token);
        $this->assertNotNull($user);
    }

    public function testNbfWithLeewayPasses(): void
    {
        $auth = new JwtAuthenticator('test-secret', leeway: 120);
        $token = $this->createToken([
            'sub' => 'user-123',
            'nbf' => time() + 60,
        ]);
        $user = $auth->authenticate($token);
        $this->assertNotNull($user);
    }
}
