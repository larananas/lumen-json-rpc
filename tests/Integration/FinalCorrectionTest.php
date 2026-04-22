<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\Protocol\BatchProcessor;
use Lumen\JsonRpc\Protocol\Error;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Protocol\RequestValidator;
use Lumen\JsonRpc\Protocol\Response;
use Lumen\JsonRpc\RateLimit\FileRateLimiter;
use Lumen\JsonRpc\RateLimit\RateLimitManager;

use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\HookPoint;
use Lumen\JsonRpc\Support\RequestContext;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;

final class FinalCorrectionTest extends TestCase
{
    use IntegrationTestCase;

    protected function setUp(): void
    {
        $this->initHandlerPath();
    }

    // =========================================================================
    // Priority A: Batch partial serialization isolation
    // =========================================================================

    public function testBatchSerializationIsolationOneInvalid(): void
    {
        $resource = fopen('php://memory', 'r');
        try {
            $responses = [
                Response::success(1, 'valid_result'),
                Response::success(2, ['resource' => $resource]),
            ];
            $encodedParts = [];
            foreach ($responses as $r) {
                $encodedParts[] = $r->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $json = '[' . implode(',', $encodedParts) . ']';
            $decoded = json_decode($json, true);
            $this->assertIsArray($decoded);
            $this->assertCount(2, $decoded);
            $this->assertArrayHasKey('result', $decoded[0]);
            $this->assertEquals('valid_result', $decoded[0]['result']);
            $this->assertEquals(-32603, $decoded[1]['error']['code']);
        } finally {
            fclose($resource);
        }
    }

    public function testBatchSerializationAllInvalidStillReturnsArray(): void
    {
        $r1 = fopen('php://memory', 'r');
        $r2 = fopen('php://memory', 'r');
        try {
            $responses = [
                Response::success(1, ['r' => $r1]),
                Response::success(2, ['r' => $r2]),
            ];
            $encodedParts = [];
            foreach ($responses as $r) {
                $encodedParts[] = $r->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $json = '[' . implode(',', $encodedParts) . ']';
            $decoded = json_decode($json, true);
            $this->assertIsArray($decoded);
            $this->assertCount(2, $decoded);
            $this->assertEquals(-32603, $decoded[0]['error']['code']);
            $this->assertEquals(-32603, $decoded[1]['error']['code']);
        } finally {
            fclose($r1);
            fclose($r2);
        }
    }

    public function testBatchSerializationMixedValidAndInvalid(): void
    {
        $resource = fopen('php://memory', 'r');
        try {
            $responses = [
                Response::success(1, 'ok'),
                Response::error(2, Error::methodNotFound()),
                Response::success(3, ['bad' => $resource]),
                Response::success(4, ['another' => 'valid']),
            ];
            $encodedParts = [];
            foreach ($responses as $r) {
                $encodedParts[] = $r->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $json = '[' . implode(',', $encodedParts) . ']';
            $decoded = json_decode($json, true);
            $this->assertCount(4, $decoded);
            $this->assertEquals('ok', $decoded[0]['result']);
            $this->assertEquals(-32601, $decoded[1]['error']['code']);
            $this->assertEquals(-32603, $decoded[2]['error']['code']);
            $this->assertEquals('valid', $decoded[3]['result']['another']);
        } finally {
            fclose($resource);
        }
    }

    public function testBatchSerializationCircularReference(): void
    {
        $data = [];
        $data['self'] = &$data;
        $responses = [
            Response::success(1, 'fine'),
            Response::success(2, $data),
        ];
        $encodedParts = [];
        foreach ($responses as $r) {
            $encodedParts[] = $r->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $json = '[' . implode(',', $encodedParts) . ']';
        $decoded = json_decode($json, true);
        $this->assertCount(2, $decoded);
        $this->assertEquals('fine', $decoded[0]['result']);
        $this->assertEquals(-32603, $decoded[1]['error']['code']);
    }

    // =========================================================================
    // Priority B: Rate limiting fixes
    // =========================================================================

    public function testComputeRawItemCountSingleRequest(): void
    {
        $this->assertEquals(1, RateLimitManager::computeRawItemCount(['jsonrpc' => '2.0', 'method' => 'test']));
    }

    public function testComputeRawItemCountBatch(): void
    {
        $batch = [
            ['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'test', 'id' => 2],
            ['jsonrpc' => '2.0', 'method' => 'test', 'id' => 3],
        ];
        $this->assertEquals(3, RateLimitManager::computeRawItemCount($batch));
    }

    public function testComputeRawItemCountNonArray(): void
    {
        $this->assertEquals(1, RateLimitManager::computeRawItemCount(null));
        $this->assertEquals(1, RateLimitManager::computeRawItemCount('string'));
    }

    public function testOversizedBatchCountsActualItemsForRateLimit(): void
    {
        $tmpDir = sys_get_temp_dir() . '/jsonrpc_test_rate_oversize_' . uniqid();
        mkdir($tmpDir, 0755, true);
        try {
            $server = $this->createServer([
                'batch' => ['max_items' => 3],
                'rate_limit' => [
                    'enabled' => true,
                    'max_requests' => 10,
                    'window_seconds' => 60,
                    'strategy' => 'ip',
                    'storage_path' => $tmpDir,
                    'batch_weight' => 1,
                ],
            ]);

            $largeBatch = [];
            for ($i = 0; $i < 8; $i++) {
                $largeBatch[] = ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => $i + 1];
            }
            $body = json_encode($largeBatch);
            $response = $server->handle($this->createRequest($body));
            $data = json_decode($response->body, true);
            $this->assertEquals(-32600, $data['error']['code']);

            $secondBatch = json_encode([
                ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
                ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 2],
            ]);
            $response2 = $server->handle($this->createRequest($secondBatch));
            $data2 = json_decode($response2->body, true);
            $this->assertEquals(200, $response2->statusCode);
            $this->assertIsArray($data2);

            $thirdRequest = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 99]);
            $response3 = $server->handle($this->createRequest($thirdRequest));
            $this->assertEquals(429, $response3->statusCode);
        } finally {
            $files = glob($tmpDir . '/*') ?: [];
            foreach ($files as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    public function testAtomicWeightedRateLimitConsumption(): void
    {
        $tmpDir = sys_get_temp_dir() . '/jsonrpc_test_rate_atomic_' . uniqid();
        mkdir($tmpDir, 0755, true);
        try {
            $limiter = new FileRateLimiter(maxRequests: 5, windowSeconds: 60, storagePath: $tmpDir);
            $result = $limiter->checkAndConsume('test_key', 3);
            $this->assertTrue($result->allowed);
            $this->assertEquals(2, $result->remaining);

            $result = $limiter->checkAndConsume('test_key', 3);
            $this->assertFalse($result->allowed);

            $result = $limiter->checkAndConsume('test_key', 1);
            $this->assertTrue($result->allowed);
            $this->assertEquals(1, $result->remaining);
        } finally {
            $files = glob($tmpDir . '/*') ?: [];
            foreach ($files as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    public function testWeightedRefusalDoesNotConsumeQuota(): void
    {
        $tmpDir = sys_get_temp_dir() . '/jsonrpc_test_rate_weighted_' . uniqid();
        mkdir($tmpDir, 0755, true);
        try {
            $limiter = new FileRateLimiter(maxRequests: 5, windowSeconds: 60, storagePath: $tmpDir);

            $result = $limiter->checkAndConsume('test_key', 4);
            $this->assertTrue($result->allowed);
            $this->assertEquals(1, $result->remaining);

            $result = $limiter->checkAndConsume('test_key', 3);
            $this->assertFalse($result->allowed);

            $result = $limiter->checkAndConsume('test_key', 1);
            $this->assertTrue($result->allowed);
            $this->assertEquals(0, $result->remaining);
        } finally {
            $files = glob($tmpDir . '/*') ?: [];
            foreach ($files as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    #[WithoutErrorHandler]
    public function testFailOpenAllowsOnStorageError(): void
    {
        $limiter = new FileRateLimiter(
            maxRequests: 5,
            windowSeconds: 60,
            storagePath: '/nonexistent/path/that/cannot/be/created',
            failOpen: true,
        );

        set_error_handler(static function (int $severity): bool {
            return $severity === E_USER_WARNING;
        });
        try {
            $result = $limiter->check('test_key');
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($result->allowed);
    }

    #[WithoutErrorHandler]
    public function testFailClosedDeniesOnStorageError(): void
    {
        $blockingPath = sys_get_temp_dir() . '/jsonrpc_ro_closed_' . uniqid() . '.tmp';
        touch($blockingPath);
        $limiter = new FileRateLimiter(
            maxRequests: 5,
            windowSeconds: 60,
            storagePath: $blockingPath,
            failOpen: false,
        );

        set_error_handler(static function (int $severity): bool {
            return $severity === E_USER_WARNING;
        });
        try {
            $result = $limiter->check('test_key');
        } finally {
            restore_error_handler();
        }

        @unlink($blockingPath);
        $this->assertFalse($result->allowed);
    }

    public function testRateLimiterInterfaceCheckAndConsumeExists(): void
    {
        $tmpDir = sys_get_temp_dir() . '/jsonrpc_test_rate_iface_' . uniqid();
        mkdir($tmpDir, 0755, true);
        try {
            $limiter = new FileRateLimiter(maxRequests: 10, windowSeconds: 60, storagePath: $tmpDir);
            $this->assertInstanceOf(\Lumen\JsonRpc\RateLimit\RateLimiterInterface::class, $limiter);
            $result = $limiter->checkAndConsume('test', 1);
            $this->assertTrue($result->allowed);
        } finally {
            $files = glob($tmpDir . '/*') ?: [];
            foreach ($files as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    public function testBatchWithInvalidItemsCountedForRateLimit(): void
    {
        $tmpDir = sys_get_temp_dir() . '/jsonrpc_test_rate_batch_' . uniqid();
        mkdir($tmpDir, 0755, true);
        try {
            $server = $this->createServer([
                'rate_limit' => [
                    'enabled' => true,
                    'max_requests' => 6,
                    'window_seconds' => 60,
                    'strategy' => 'ip',
                    'storage_path' => $tmpDir,
                    'batch_weight' => 1,
                ],
            ]);

            $batchBody = json_encode([
                ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1],
                ['foo' => 'bar'],
                ['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 2],
            ]);
            $response = $server->handle($this->createRequest($batchBody));
            $this->assertEquals(200, $response->statusCode);

            $response2 = $server->handle($this->createRequest($batchBody));
            $this->assertEquals(200, $response2->statusCode);

            $response3 = $server->handle($this->createRequest($batchBody));
            $this->assertEquals(429, $response3->statusCode);
        } finally {
            $files = glob($tmpDir . '/*') ?: [];
            foreach ($files as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    // =========================================================================
    // Priority C: JWT leeway
    // =========================================================================

    public function testJwtLeewayAppliedToExpiredToken(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256', 'leeway' => 300],
                'protected_methods' => ['user.'],
            ],
        ]);
        $token = $this->createJwt(['sub' => 'user-1', 'exp' => time() - 120, 'roles' => ['admin']]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', ['Authorization' => "Bearer $token"]));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
    }

    public function testJwtLeewayAppliedToNbf(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256', 'leeway' => 300],
                'protected_methods' => ['user.'],
            ],
        ]);
        $token = $this->createJwt(['sub' => 'user-1', 'nbf' => time() + 120, 'roles' => ['admin']]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', ['Authorization' => "Bearer $token"]));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
    }

    public function testJwtLeewayAppliedToIat(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256', 'leeway' => 300],
                'protected_methods' => ['user.'],
            ],
        ]);
        $token = $this->createJwt(['sub' => 'user-1', 'iat' => time() + 120, 'roles' => ['admin']]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', ['Authorization' => "Bearer $token"]));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
    }

    public function testJwtLeewayZeroRejectsExpiredToken(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256', 'leeway' => 0],
                'protected_methods' => ['user.'],
            ],
        ]);
        $token = $this->createJwt(['sub' => 'user-1', 'exp' => time() - 1, 'roles' => ['admin']]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', ['Authorization' => "Bearer $token"]));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32001, $data['error']['code']);
    }

    public function testJwtAudienceStringMatch(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256', 'audience' => 'myapi'],
                'protected_methods' => ['user.'],
            ],
        ]);
        $token = $this->createJwt(['sub' => 'user-1', 'aud' => 'myapi', 'roles' => ['admin']]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', ['Authorization' => "Bearer $token"]));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
    }

    public function testJwtAudienceArrayMatch(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256', 'audience' => 'myapi'],
                'protected_methods' => ['user.'],
            ],
        ]);
        $token = $this->createJwt(['sub' => 'user-1', 'aud' => ['myapi', 'otherapi'], 'roles' => ['admin']]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', ['Authorization' => "Bearer $token"]));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
    }

    public function testJwtAudienceArrayMismatch(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256', 'audience' => 'myapi'],
                'protected_methods' => ['user.'],
            ],
        ]);
        $token = $this->createJwt(['sub' => 'user-1', 'aud' => ['otherapi', 'third'], 'roles' => ['admin']]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', ['Authorization' => "Bearer $token"]));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32001, $data['error']['code']);
    }

    // =========================================================================
    // Priority D: Hooks lifecycle on GET health
    // =========================================================================

    public function testGetHealthFiresBeforeRequestHook(): void
    {
        $server = $this->createServer();
        $fired = false;
        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, function (array $ctx) use (&$fired) {
            $fired = true;
            return [];
        });
        $server->handle($this->createRequest('', 'GET'));
        $this->assertTrue($fired, 'BEFORE_REQUEST hook should fire on GET health');
    }

    public function testGetHealthFiresOnResponseHook(): void
    {
        $server = $this->createServer();
        $fired = false;
        $server->getHooks()->register(HookPoint::ON_RESPONSE, function (array $ctx) use (&$fired) {
            $fired = true;
            return [];
        });
        $server->handle($this->createRequest('', 'GET'));
        $this->assertTrue($fired, 'ON_RESPONSE hook should fire on GET health');
    }

    public function testGetHealthFiresAfterRequestHook(): void
    {
        $server = $this->createServer();
        $fired = false;
        $server->getHooks()->register(HookPoint::AFTER_REQUEST, function (array $ctx) use (&$fired) {
            $fired = true;
            return [];
        });
        $server->handle($this->createRequest('', 'GET'));
        $this->assertTrue($fired, 'AFTER_REQUEST hook should fire on GET health');
    }

    public function testGetHealthPassesHealthContext(): void
    {
        $server = $this->createServer();
        $captured = [];
        $server->getHooks()->register(HookPoint::BEFORE_REQUEST, function (array $ctx) use (&$captured) {
            $captured = $ctx;
            return [];
        });
        $server->handle($this->createRequest('', 'GET'));
        $this->assertTrue($captured['health'] ?? false, 'GET health should set health=true in hook context');
    }

    // =========================================================================
    // Priority F: Config::fromFile
    // =========================================================================

    public function testConfigFromFileThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configuration file not found');
        Config::fromFile('/nonexistent/path/config.php');
    }

    public function testConfigFromFileThrowsOnNonArrayReturn(): void
    {
        $tmpFile = sys_get_temp_dir() . '/jsonrpc_test_config_' . uniqid() . '.php';
        file_put_contents($tmpFile, "<?php return 'not-an-array';");
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('must return an array');
            Config::fromFile($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testConfigFromFileLoadsValidArray(): void
    {
        $tmpFile = sys_get_temp_dir() . '/jsonrpc_test_config_valid_' . uniqid() . '.php';
        file_put_contents($tmpFile, "<?php return ['debug' => true];");
        try {
            $config = Config::fromFile($tmpFile);
            $this->assertTrue($config->get('debug'));
        } finally {
            @unlink($tmpFile);
        }
    }

    // =========================================================================
    // Priority G: RequestContext rawBody vs requestBody
    // =========================================================================

    public function testRequestContextHasRequestBodyField(): void
    {
        $ctx = new RequestContext(
            correlationId: 'test',
            headers: [],
            clientIp: '127.0.0.1',
            rawBody: 'original-gzipped',
            requestBody: 'decompressed-json',
        );
        $this->assertEquals('original-gzipped', $ctx->rawBody);
        $this->assertEquals('decompressed-json', $ctx->requestBody);
    }

    public function testRequestContextWithAuthPreservesRequestBody(): void
    {
        $ctx = new RequestContext(
            correlationId: 'test',
            headers: [],
            clientIp: '127.0.0.1',
            rawBody: 'raw',
            requestBody: 'decoded',
        );
        $withAuth = $ctx->withAuth('user1', ['sub' => 'user1'], ['admin']);
        $this->assertEquals('raw', $withAuth->rawBody);
        $this->assertEquals('decoded', $withAuth->requestBody);
    }

    // =========================================================================
    // Priority H: Content-Type contract
    // =========================================================================

    public function testContentTypeStrictRejectsNonJson(): void
    {
        $server = $this->createServer([
            'content_type' => ['strict' => true],
        ]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle(new HttpRequest(
            body: $body,
            headers: ['Content-Type' => 'text/plain'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        ));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
        $errorData = $data['error']['data'] ?? $data['error']['message'] ?? '';
        $this->assertStringContainsStringIgnoringCase('Content-Type', $errorData);
    }

    public function testContentTypeStrictAcceptsJson(): void
    {
        $server = $this->createServer([
            'content_type' => ['strict' => true],
        ]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
    }

    public function testContentTypeStrictRejectsMissingHeader(): void
    {
        $server = $this->createServer([
            'content_type' => ['strict' => true],
        ]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle(new HttpRequest(
            body: $body,
            headers: [],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        ));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testContentTypeLenientAcceptsNonJson(): void
    {
        $server = $this->createServer([
            'content_type' => ['strict' => false],
        ]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle(new HttpRequest(
            body: $body,
            headers: ['Content-Type' => 'text/plain'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        ));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
    }

    public function testContentTypeDefaultIsLenient(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle(new HttpRequest(
            body: $body,
            headers: ['Content-Type' => 'text/plain'],
            method: 'POST',
            clientIp: '127.0.0.1',
            server: [],
        ));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
    }

    // =========================================================================
    // RequestContext requestBody in server flow
    // =========================================================================

    public function testRequestContextReceivesDecodedBody(): void
    {
        $capturedContext = null;
        $server = $this->createServer();
        $server->getHooks()->register(HookPoint::BEFORE_HANDLER, function (array $ctx) use (&$capturedContext) {
            $capturedContext = $ctx['context'] ?? null;
            return [];
        });

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $server->handle($this->createRequest($body));

        $this->assertNotNull($capturedContext);
        $this->assertInstanceOf(RequestContext::class, $capturedContext);
        $this->assertEquals($body, $capturedContext->requestBody);
    }
}
