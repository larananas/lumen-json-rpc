<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Support;

use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\TestCase;

final class RequestContextTest extends TestCase
{
    public function testWithAuthReturnsNewInstance(): void
    {
        $original = new RequestContext('cid', [], '127.0.0.1');
        $withAuth = $original->withAuth('user-1', ['sub' => 'user-1'], ['admin']);

        $this->assertNotSame($original, $withAuth);
        $this->assertFalse($original->hasAuth());
        $this->assertTrue($withAuth->hasAuth());
    }

    public function testWithAuthPreservesImmutableFields(): void
    {
        $original = new RequestContext(
            'cid', ['X-Test' => 'yes'], '10.0.0.1',
            null, [], [],
            'raw-body', 'decoded-body',
            ['key' => 'value']
        );
        $withAuth = $original->withAuth('user-1', ['sub' => 'user-1'], ['admin']);

        $this->assertEquals('cid', $withAuth->correlationId);
        $this->assertEquals(['X-Test' => 'yes'], $withAuth->headers);
        $this->assertEquals('10.0.0.1', $withAuth->clientIp);
        $this->assertEquals('raw-body', $withAuth->rawBody);
        $this->assertEquals('decoded-body', $withAuth->requestBody);
        $this->assertEquals(['key' => 'value'], $withAuth->attributes);
    }

    public function testHasAuthReturnsFalseWhenNoUserId(): void
    {
        $ctx = new RequestContext('cid', [], '127.0.0.1');
        $this->assertFalse($ctx->hasAuth());
    }

    public function testHasAuthReturnsTrueWhenUserIdSet(): void
    {
        $ctx = new RequestContext('cid', [], '127.0.0.1', 'user-1');
        $this->assertTrue($ctx->hasAuth());
    }

    public function testHasRoleChecksRoles(): void
    {
        $ctx = new RequestContext('cid', [], '127.0.0.1', 'user-1', [], ['admin', 'user']);
        $this->assertTrue($ctx->hasRole('admin'));
        $this->assertTrue($ctx->hasRole('user'));
        $this->assertFalse($ctx->hasRole('superadmin'));
    }

    public function testHasRoleIsCaseSensitive(): void
    {
        $ctx = new RequestContext('cid', [], '127.0.0.1', 'user-1', [], ['Admin']);
        $this->assertTrue($ctx->hasRole('Admin'));
        $this->assertFalse($ctx->hasRole('admin'));
    }

    public function testGetClaimReturnsClaimValue(): void
    {
        $ctx = new RequestContext('cid', [], '127.0.0.1', 'user-1', ['email' => 'test@example.com'], []);
        $this->assertEquals('test@example.com', $ctx->getClaim('email'));
    }

    public function testGetClaimReturnsDefaultWhenMissing(): void
    {
        $ctx = new RequestContext('cid', [], '127.0.0.1');
        $this->assertNull($ctx->getClaim('missing'));
        $this->assertEquals('default', $ctx->getClaim('missing', 'default'));
    }

    public function testGetAttributeReturnsAttributeValue(): void
    {
        $ctx = new RequestContext('cid', [], '127.0.0.1', null, [], [], null, null, ['version' => '2.0']);
        $this->assertEquals('2.0', $ctx->getAttribute('version'));
    }

    public function testGetAttributeReturnsDefaultWhenMissing(): void
    {
        $ctx = new RequestContext('cid', [], '127.0.0.1');
        $this->assertNull($ctx->getAttribute('missing'));
        $this->assertEquals('fallback', $ctx->getAttribute('missing', 'fallback'));
    }
}
