<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Config;

use Lumen\JsonRpc\Config\Defaults;
use PHPUnit\Framework\TestCase;

final class DefaultsMutationKillTest extends TestCase
{
    private array $defaults;

    protected function setUp(): void
    {
        $this->defaults = Defaults::all();
    }

    public function testHandlersPathsExistsAndIsEmptyArray(): void
    {
        $this->assertArrayHasKey('paths', $this->defaults['handlers']);
        $this->assertSame([], $this->defaults['handlers']['paths']);
    }

    public function testHandlersNamespace(): void
    {
        $this->assertSame('App\\Handlers\\', $this->defaults['handlers']['namespace']);
    }

    public function testHandlersMethodSeparator(): void
    {
        $this->assertSame('.', $this->defaults['handlers']['method_separator']);
    }

    public function testAuthEnabledIsFalse(): void
    {
        $this->assertFalse($this->defaults['auth']['enabled']);
    }

    public function testAuthDriverIsJwt(): void
    {
        $this->assertSame('jwt', $this->defaults['auth']['driver']);
    }

    public function testAuthProtectedMethodsIsEmptyArray(): void
    {
        $this->assertSame([], $this->defaults['auth']['protected_methods']);
    }

    public function testAuthJwtSecretIsEmptyString(): void
    {
        $this->assertSame('', $this->defaults['auth']['jwt']['secret']);
    }

    public function testAuthJwtAlgorithmIsHS256(): void
    {
        $this->assertSame('HS256', $this->defaults['auth']['jwt']['algorithm']);
    }

    public function testAuthJwtHeaderIsAuthorization(): void
    {
        $this->assertSame('Authorization', $this->defaults['auth']['jwt']['header']);
    }

    public function testAuthJwtPrefixIsBearerSpace(): void
    {
        $this->assertSame('Bearer ', $this->defaults['auth']['jwt']['prefix']);
    }

    public function testAuthJwtIssuerIsEmptyString(): void
    {
        $this->assertSame('', $this->defaults['auth']['jwt']['issuer']);
    }

    public function testAuthJwtAudienceIsEmptyString(): void
    {
        $this->assertSame('', $this->defaults['auth']['jwt']['audience']);
    }

    public function testAuthJwtLeewayIsZero(): void
    {
        $this->assertSame(0, $this->defaults['auth']['jwt']['leeway']);
    }

    public function testAuthApiKeyHeaderIsXApiKey(): void
    {
        $this->assertSame('X-API-Key', $this->defaults['auth']['api_key']['header']);
    }

    public function testAuthApiKeyKeysIsEmptyArray(): void
    {
        $this->assertSame([], $this->defaults['auth']['api_key']['keys']);
    }

    public function testAuthBasicHeaderIsAuthorization(): void
    {
        $this->assertSame('Authorization', $this->defaults['auth']['basic']['header']);
    }

    public function testAuthBasicUsersIsEmptyArray(): void
    {
        $this->assertSame([], $this->defaults['auth']['basic']['users']);
    }

    public function testBatchMaxItemsIs100(): void
    {
        $this->assertSame(100, $this->defaults['batch']['max_items']);
    }

    public function testLimitsMaxBodySizeIs1048576(): void
    {
        $this->assertSame(1_048_576, $this->defaults['limits']['max_body_size']);
    }

    public function testLimitsMaxJsonDepthIs64(): void
    {
        $this->assertSame(64, $this->defaults['limits']['max_json_depth']);
    }

    public function testLoggingEnabledIsTrue(): void
    {
        $this->assertTrue($this->defaults['logging']['enabled']);
    }

    public function testLoggingLevelIsInfo(): void
    {
        $this->assertSame('info', $this->defaults['logging']['level']);
    }

    public function testLoggingPath(): void
    {
        $this->assertSame('logs/app.log', $this->defaults['logging']['path']);
    }

    public function testLoggingSanitizeSecretsIsTrue(): void
    {
        $this->assertTrue($this->defaults['logging']['sanitize_secrets']);
    }

    public function testLogRotationEnabledIsTrue(): void
    {
        $this->assertTrue($this->defaults['log_rotation']['enabled']);
    }

    public function testLogRotationMaxSize(): void
    {
        $this->assertSame(10_485_760, $this->defaults['log_rotation']['max_size']);
    }

    public function testLogRotationMaxFilesIs5(): void
    {
        $this->assertSame(5, $this->defaults['log_rotation']['max_files']);
    }

    public function testLogRotationCompressIsTrue(): void
    {
        $this->assertTrue($this->defaults['log_rotation']['compress']);
    }

    public function testCompressionRequestGzipIsTrue(): void
    {
        $this->assertTrue($this->defaults['compression']['request_gzip']);
    }

    public function testCompressionResponseGzipIsFalse(): void
    {
        $this->assertFalse($this->defaults['compression']['response_gzip']);
    }

    public function testRateLimitEnabledIsFalse(): void
    {
        $this->assertFalse($this->defaults['rate_limit']['enabled']);
    }

    public function testRateLimitMaxRequestsIs100(): void
    {
        $this->assertSame(100, $this->defaults['rate_limit']['max_requests']);
    }

    public function testRateLimitWindowSecondsIs60(): void
    {
        $this->assertSame(60, $this->defaults['rate_limit']['window_seconds']);
    }

    public function testRateLimitStrategyIsIp(): void
    {
        $this->assertSame('ip', $this->defaults['rate_limit']['strategy']);
    }

    public function testRateLimitStoragePath(): void
    {
        $this->assertSame('storage/rate_limit', $this->defaults['rate_limit']['storage_path']);
    }

    public function testRateLimitBatchWeightIs1(): void
    {
        $this->assertSame(1, $this->defaults['rate_limit']['batch_weight']);
    }

    public function testRateLimitFailOpenIsFalse(): void
    {
        $this->assertFalse($this->defaults['rate_limit']['fail_open']);
    }

    public function testDebugIsFalse(): void
    {
        $this->assertFalse($this->defaults['debug']);
    }

    public function testNotificationsEnabledIsTrue(): void
    {
        $this->assertTrue($this->defaults['notifications']['enabled']);
    }

    public function testNotificationsLogIsTrue(): void
    {
        $this->assertTrue($this->defaults['notifications']['log']);
    }

    public function testResponseFingerprintEnabledIsFalse(): void
    {
        $this->assertFalse($this->defaults['response_fingerprint']['enabled']);
    }

    public function testResponseFingerprintAlgorithmIsSha256(): void
    {
        $this->assertSame('sha256', $this->defaults['response_fingerprint']['algorithm']);
    }

    public function testHealthEnabledIsTrue(): void
    {
        $this->assertTrue($this->defaults['health']['enabled']);
    }

    public function testValidationStrictIsFalse(): void
    {
        $this->assertFalse($this->defaults['validation']['strict']);
    }

    public function testValidationSchemaEnabledIsFalse(): void
    {
        $this->assertFalse($this->defaults['validation']['schema']['enabled']);
    }

    public function testContentTypeStrictIsFalse(): void
    {
        $this->assertFalse($this->defaults['content_type']['strict']);
    }

    public function testHooksEnabledIsTrue(): void
    {
        $this->assertTrue($this->defaults['hooks']['enabled']);
    }

    public function testHooksIsolateExceptionsIsTrue(): void
    {
        $this->assertTrue($this->defaults['hooks']['isolate_exceptions']);
    }

    public function testServerVersionIs100(): void
    {
        $this->assertSame('1.0.0', $this->defaults['server']['version']);
    }

    public function testServerName(): void
    {
        $this->assertSame('Lumen JSON-RPC', $this->defaults['server']['name']);
    }

    public function testServerUrlIsEmptyString(): void
    {
        $this->assertSame('', $this->defaults['server']['url']);
    }

    public function testAllTopLevelKeysExist(): void
    {
        $expectedKeys = [
            'handlers', 'auth', 'batch', 'limits', 'logging', 'log_rotation',
            'compression', 'rate_limit', 'debug', 'notifications',
            'response_fingerprint', 'health', 'validation', 'content_type',
            'hooks', 'server',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $this->defaults, "Missing top-level key: $key");
        }
    }

    public function testValidationSchemaSubStructureExists(): void
    {
        $this->assertArrayHasKey('schema', $this->defaults['validation']);
        $this->assertArrayHasKey('enabled', $this->defaults['validation']['schema']);
    }
}
