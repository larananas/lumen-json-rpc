<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Auth;

use Lumen\JsonRpc\Auth\ApiKeyAuthenticator;
use Lumen\JsonRpc\Auth\UserContext;
use PHPUnit\Framework\TestCase;

final class ApiKeyAuthenticatorTest extends TestCase
{
    public function testValidApiKeyReturnsUserContext(): void
    {
        $auth = new ApiKeyAuthenticator(
            header: 'X-API-Key',
            keys: [
                'test-key-123' => [
                    'user_id' => 'service-1',
                    'roles' => ['service'],
                    'claims' => ['source' => 'api_key'],
                ],
            ],
        );

        $result = $auth->authenticateFromHeaders(['X-API-Key' => 'test-key-123']);
        $this->assertNotNull($result);
        $this->assertEquals('service-1', $result->userId);
        $this->assertEquals(['service'], $result->roles);
        $this->assertEquals(['source' => 'api_key'], $result->claims);
    }

    public function testInvalidApiKeyReturnsNull(): void
    {
        $auth = new ApiKeyAuthenticator(
            header: 'X-API-Key',
            keys: ['valid-key' => ['user_id' => 'user']],
        );

        $this->assertNull($auth->authenticateFromHeaders(['X-API-Key' => 'wrong-key']));
    }

    public function testMissingHeaderReturnsNull(): void
    {
        $auth = new ApiKeyAuthenticator(
            header: 'X-API-Key',
            keys: ['valid-key' => ['user_id' => 'user']],
        );

        $this->assertNull($auth->authenticateFromHeaders([]));
    }

    public function testEmptyHeaderReturnsNull(): void
    {
        $auth = new ApiKeyAuthenticator(
            header: 'X-API-Key',
            keys: ['valid-key' => ['user_id' => 'user']],
        );

        $this->assertNull($auth->authenticateFromHeaders(['X-API-Key' => '']));
    }

    public function testCaseInsensitiveHeaderLookup(): void
    {
        $auth = new ApiKeyAuthenticator(
            header: 'X-API-Key',
            keys: ['my-key' => ['user_id' => 'user']],
        );

        $this->assertNotNull($auth->authenticateFromHeaders(['x-api-key' => 'my-key']));
    }
}
