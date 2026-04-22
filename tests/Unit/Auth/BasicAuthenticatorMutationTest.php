<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Auth;

use Lumen\JsonRpc\Auth\BasicAuthenticator;
use PHPUnit\Framework\TestCase;

final class BasicAuthenticatorMutationTest extends TestCase
{
    public function testNonBasicHeaderThatWouldDecodeCorrectlyReturnsNull(): void
    {
        $creds = base64_encode('admin:secret');
        $auth = new BasicAuthenticator(
            users: ['admin' => ['password' => 'secret']],
        );
        $header = 'Bearer ' . $creds;
        $this->assertNull($auth->authenticateFromHeaders(['Authorization' => $header]));
    }

    public function testNonBasicHeaderWithSixCharsBeforeValidBase64ReturnsNull(): void
    {
        $creds = base64_encode('admin:secret');
        $auth = new BasicAuthenticator(
            users: ['admin' => ['password' => 'secret']],
        );
        $header = 'XXXXXX' . $creds;
        $this->assertNull($auth->authenticateFromHeaders(['Authorization' => $header]));
    }

    public function testBasicWithOnlySpacesAfterPrefixReturnsNull(): void
    {
        $auth = new BasicAuthenticator(
            users: ['admin' => ['password' => 'secret']],
        );
        $this->assertNull($auth->authenticateFromHeaders(['Authorization' => 'Basic ']));
    }

    public function testExactBasicPrefixOffsetIsSix(): void
    {
        $creds = base64_encode('admin:secret');
        $auth = new BasicAuthenticator(
            users: ['admin' => ['password' => 'secret']],
        );
        $result = $auth->authenticateFromHeaders(['Authorization' => 'Basic ' . $creds]);
        $this->assertNotNull($result);
        $this->assertSame('admin', $result->userId);
    }

    public function testColonSplitUsesFirstColonOnly(): void
    {
        $creds = base64_encode('admin:pass:with:colons');
        $auth = new BasicAuthenticator(
            users: ['admin' => ['password' => 'pass:with:colons']],
        );
        $result = $auth->authenticateFromHeaders(['Authorization' => 'Basic ' . $creds]);
        $this->assertNotNull($result);
        $this->assertSame('admin', $result->userId);
    }

    public function testDigestSchemeIsRejected(): void
    {
        $creds = base64_encode('admin:secret');
        $auth = new BasicAuthenticator(
            users: ['admin' => ['password' => 'secret']],
        );
        $this->assertNull($auth->authenticateFromHeaders(['Authorization' => 'Digest ' . $creds]));
    }

    public function testPasswordVerifyWithHash(): void
    {
        $hash = password_hash('mypassword', PASSWORD_BCRYPT);
        $creds = base64_encode('admin:mypassword');
        $auth = new BasicAuthenticator(
            users: ['admin' => ['password_hash' => $hash]],
        );
        $result = $auth->authenticateFromHeaders(['Authorization' => 'Basic ' . $creds]);
        $this->assertNotNull($result);
        $this->assertSame('admin', $result->userId);
    }

    public function testCustomHeaderName(): void
    {
        $creds = base64_encode('admin:secret');
        $auth = new BasicAuthenticator(
            header: 'X-Custom-Auth',
            users: ['admin' => ['password' => 'secret']],
        );
        $this->assertNull($auth->authenticateFromHeaders(['Authorization' => 'Basic ' . $creds]));
        $result = $auth->authenticateFromHeaders(['X-Custom-Auth' => 'Basic ' . $creds]);
        $this->assertNotNull($result);
    }
}
