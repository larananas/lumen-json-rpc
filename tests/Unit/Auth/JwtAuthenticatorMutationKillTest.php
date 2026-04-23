<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Auth;

use Lumen\JsonRpc\Auth\JwtAuthenticator;
use PHPUnit\Framework\TestCase;

final class JwtAuthenticatorMutationKillTest extends TestCase
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

    public function testDefaultLeewayIsZeroUsedInExpCheck(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $token = $this->createToken(['sub' => 'user-1', 'exp' => time() - 1]);
        $this->assertNull($auth->authenticate($token));
    }

    public function testLeewayZeroRejectsTokenExpiredByOneSecond(): void
    {
        $auth = new JwtAuthenticator('test-secret', leeway: 0);
        $token = $this->createToken(['sub' => 'user-1', 'exp' => time() - 1]);
        $this->assertNull($auth->authenticate($token));
    }

    public function testDecodeJwtReturningNullCausesAuthenticateToReturnNull(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $this->assertNull($auth->authenticate('not-even-close'));
    }

    public function testExpBoundaryStrictLessThan(): void
    {
        $auth = new JwtAuthenticator('test-secret', leeway: 0);
        $token = $this->createToken(['sub' => 'user-1', 'exp' => time()]);
        $user = $auth->authenticate($token);
        $this->assertNotNull($user, 'Token with exp == now should pass (strict < check)');
    }

    public function testExpOneSecondPastIsRejected(): void
    {
        $auth = new JwtAuthenticator('test-secret', leeway: 0);
        $token = $this->createToken(['sub' => 'user-1', 'exp' => time() - 1]);
        $this->assertNull($auth->authenticate($token));
    }

    public function testNbfBoundaryStrictGreaterThan(): void
    {
        $auth = new JwtAuthenticator('test-secret', leeway: 0);
        $token = $this->createToken(['sub' => 'user-1', 'nbf' => time()]);
        $user = $auth->authenticate($token);
        $this->assertNotNull($user, 'Token with nbf == now should pass (strict > check)');
    }

    public function testNbfOneSecondInFutureIsRejected(): void
    {
        $auth = new JwtAuthenticator('test-secret', leeway: 0);
        $token = $this->createToken(['sub' => 'user-1', 'nbf' => time() + 1]);
        $this->assertNull($auth->authenticate($token));
    }

    public function testSubTakesPriorityOverUserId(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $token = $this->createToken([
            'sub' => 'from-sub',
            'user_id' => 'from-user-id',
        ]);
        $user = $auth->authenticate($token);
        $this->assertNotNull($user);
        $this->assertSame('from-sub', $user->userId);
    }

    public function testUserIdUsedWhenSubMissing(): void
    {
        $auth = new JwtAuthenticator('test-secret');
        $token = $this->createToken(['user_id' => 'from-user-id']);
        $user = $auth->authenticate($token);
        $this->assertNotNull($user);
        $this->assertSame('from-user-id', $user->userId);
    }

    public function testHeaderNotArrayCausesNullReturn(): void
    {
        $header = $this->base64UrlEncode('"not-an-object"');
        $payload = $this->base64UrlEncode(json_encode(['sub' => 'user-1']));
        $sig = $this->base64UrlEncode(hash_hmac('sha256', "$header.$payload", 'test-secret', true));
        $sig = $this->base64UrlEncode(hash_hmac('sha256', "$header.$payload", 'test-secret', true));
        $auth = new JwtAuthenticator('test-secret');
        $this->assertNull($auth->authenticate("$header.$payload.$sig"));
    }

    public function testPayloadNotArrayCausesNullReturn(): void
    {
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = $this->base64UrlEncode('"not-an-object"');
        $sig = $this->base64UrlEncode(hash_hmac('sha256', "$header.$payload", 'test-secret', true));
        $auth = new JwtAuthenticator('test-secret');
        $this->assertNull($auth->authenticate("$header.$payload.$sig"));
    }

    public function testBothHeaderAndPayloadNotArrayCausesNullReturn(): void
    {
        $header = $this->base64UrlEncode('"a"');
        $payload = $this->base64UrlEncode('"b"');
        $sig = $this->base64UrlEncode('sig');
        $auth = new JwtAuthenticator('test-secret');
        $this->assertNull($auth->authenticate("$header.$payload.$sig"));
    }

    public function testAlgNoneIsRejectedEvenWithValidPayload(): void
    {
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'none']));
        $payload = $this->base64UrlEncode(json_encode(['sub' => 'hacker']));
        $this->assertNull((new JwtAuthenticator('test-secret'))->authenticate("$header.$payload."));
    }

    public function testUnsupportedAlgorithmIsRejected(): void
    {
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'RS256']));
        $payload = $this->base64UrlEncode(json_encode(['sub' => 'user-1']));
        $sig = $this->base64UrlEncode('fakesignature');
        $this->assertNull((new JwtAuthenticator('test-secret'))->authenticate("$header.$payload.$sig"));
    }

    public function testEmptyAlgIsRejected(): void
    {
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => '']));
        $payload = $this->base64UrlEncode(json_encode(['sub' => 'user-1']));
        $this->assertNull((new JwtAuthenticator('test-secret'))->authenticate("$header.$payload.sig"));
    }
}
