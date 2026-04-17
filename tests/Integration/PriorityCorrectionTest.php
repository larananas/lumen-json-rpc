<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Cache\ResponseFingerprinter;
use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\Log\LogFormatter;
use Lumen\JsonRpc\Log\LogRotator;
use Lumen\JsonRpc\Protocol\BatchProcessor;
use Lumen\JsonRpc\Protocol\Error;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Protocol\RequestValidator;
use Lumen\JsonRpc\Protocol\Response;
use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Support\HookPoint;
use PHPUnit\Framework\TestCase;

final class PriorityCorrectionTest extends TestCase
{
    use IntegrationTestCase;

    protected function setUp(): void
    {
        $this->initHandlerPath();
    }

    // ========== A: JSON serialization robustness ==========

    public function testResponseToJsonHandlesNonSerializableResult(): void
    {
        $resource = fopen('php://memory', 'r');
        try {
            $response = Response::success(1, ['data' => $resource]);
            $json = $response->toJson();
            $decoded = json_decode($json, true);
            $this->assertNotNull($decoded);
            $this->assertEquals('2.0', $decoded['jsonrpc']);
            $this->assertEquals(-32603, $decoded['error']['code']);
        } finally {
            fclose($resource);
        }
    }

    public function testResponseToJsonHandlesErrorGracefully(): void
    {
        $response = Response::success(1, 'ok');
        $json = $response->toJson();
        $this->assertNotFalse(json_decode($json));
    }

    public function testResponseToJsonHandlesCircularReference(): void
    {
        $data = [];
        $data['self'] = &$data;
        $response = Response::success(1, $data);
        $json = $response->toJson();
        $decoded = json_decode($json, true);
        $this->assertNotNull($decoded);
        $this->assertEquals(-32603, $decoded['error']['code']);
    }

    // ========== B: Rate limiting weight on invalid batch ==========

    public function testBatchWithInvalidItemsCountedForRateLimit(): void
    {
        $tmpDir = sys_get_temp_dir() . '/jsonrpc_test_rate_' . uniqid();
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

    // ========== C: Empty POST body ==========

    public function testEmptyPostBodyReturnsInvalidRequestError(): void
    {
        $server = $this->createServer();
        $response = $server->handle($this->createRequest(''));
        $this->assertEquals(200, $response->statusCode);
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
        $this->assertArrayHasKey('data', $data['error']);
    }

    public function testEmptyPostBodyIsNotNoContent(): void
    {
        $server = $this->createServer();
        $response = $server->handle($this->createRequest(''));
        $this->assertNotEquals(204, $response->statusCode);
        $this->assertNotEmpty($response->body);
    }

    // ========== D: Auth required error code ==========

    public function testAuthRequiredReturnsServerErrorCode(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256'],
                'protected_methods' => ['user.'],
            ],
        ]);

        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32001, $data['error']['code']);
        $this->assertStringContainsString('Authentication required', $data['error']['message']);
        $this->assertLessThanOrEqual(-32000, $data['error']['code']);
        $this->assertGreaterThanOrEqual(-32099, $data['error']['code']);
    }

    // ========== E: Parameter binder strictness ==========

    public function testUnknownNamedParamsRejected(): void
    {
        $server = $this->createServer();
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'user.get',
            'params' => ['id' => 1, 'unknown_field' => 'value'],
            'id' => 1,
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32602, $data['error']['code']);
    }

    public function testSurplusPositionalParamsRejected(): void
    {
        $server = $this->createServer();
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'user.get',
            'params' => [1, 2, 3],
            'id' => 1,
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32602, $data['error']['code']);
    }

    public function testExactNamedParamsAccepted(): void
    {
        $server = $this->createServer();
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'user.get',
            'params' => ['id' => 1],
            'id' => 1,
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
    }

    public function testExactPositionalParamsAccepted(): void
    {
        $server = $this->createServer();
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'user.get',
            'params' => [1],
            'id' => 1,
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
    }

    // ========== F: {} vs [] distinction ==========

    public function testEmptyObjectTreatedAsInvalidRequest(): void
    {
        $server = $this->createServer();
        $response = $server->handle($this->createRequest('{}'));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
        $this->assertNull($data['id']);
    }

    public function testEmptyArrayReturnsEmptyBatchError(): void
    {
        $server = $this->createServer();
        $response = $server->handle($this->createRequest('[]'));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32600, $data['error']['code']);
        $this->assertStringContainsString('Empty batch', $data['error']['data'] ?? '');
    }

    public function testNonEmptyInvalidBatchReturnsArrayError(): void
    {
        $server = $this->createServer();
        $response = $server->handle($this->createRequest('[1]'));
        $data = json_decode($response->body, true);
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertEquals(-32600, $data[0]['error']['code']);
    }

    public function testEmptyObjectNotConfusedWithBatch(): void
    {
        $processor = new BatchProcessor(new RequestValidator(), 100);
        $result = $processor->process([], rawIsObject: false);
        $this->assertFalse($result->isBatch);
        $errorData = $result->errors[0]->error->data;
        $this->assertStringContainsString('Empty batch', $errorData);

        $result2 = $processor->process([], rawIsObject: true);
        $this->assertFalse($result2->isBatch);
        $this->assertEquals(-32600, $result2->errors[0]->error->code);
        $this->assertStringContainsString('jsonrpc', $result2->errors[0]->error->data ?? '');
    }

    // ========== G: JWT leeway + audience array ==========

    public function testJwtLeewayAllowsSlightlyExpiredExp(): void
    {
        $server = $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256', 'leeway' => 120],
                'protected_methods' => ['user.'],
            ],
        ]);
        $token = $this->createJwt(['sub' => 'user-1', 'exp' => time() - 60, 'roles' => ['admin']]);
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
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256', 'leeway' => 120],
                'protected_methods' => ['user.'],
            ],
        ]);
        $token = $this->createJwt(['sub' => 'user-1', 'nbf' => time() + 60, 'roles' => ['admin']]);
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
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256', 'leeway' => 120],
                'protected_methods' => ['user.'],
            ],
        ]);
        $token = $this->createJwt(['sub' => 'user-1', 'iat' => time() + 60, 'roles' => ['admin']]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', ['Authorization' => "Bearer $token"]));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
    }

    public function testJwtAudienceArraySupported(): void
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
        $token = $this->createJwt(['sub' => 'user-1', 'aud' => ['otherapi', 'thirdapi'], 'roles' => ['admin']]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['id' => 1], 'id' => 1]);
        $response = $server->handle($this->createRequest($body, 'POST', ['Authorization' => "Bearer $token"]));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32001, $data['error']['code']);
    }

    // ========== H: Log injection prevention ==========

    public function testLogFormatterSanitizesNewlines(): void
    {
        $formatter = new LogFormatter(false);
        $output = $formatter->format('INFO', "line1\nline2\rline3");
        $this->assertStringNotContainsString("\nline2", $output);
        $this->assertStringNotContainsString("\rline3", $output);
        $this->assertStringContainsString('line1\\nline2\\rline3', $output);
    }

    public function testLogFormatterOutputIsSingleLine(): void
    {
        $formatter = new LogFormatter(false);
        $output = $formatter->format('INFO', "msg\nwith\nnewlines", ['key' => "val\nue"]);
        $lines = explode("\n", trim($output));
        $this->assertCount(1, $lines);
    }

    public function testLogRotatorNoFakeGzExtensionOnCompressionFailure(): void
    {
        $testDir = sys_get_temp_dir() . '/jsonrpc_test_rotate_' . uniqid();
        mkdir($testDir, 0755, true);
        try {
            $logPath = $testDir . '/test.log';
            file_put_contents($logPath, str_repeat('x', 1000));

            $rotator = new LogRotator(maxSize: 100, maxFiles: 3, compress: true);
            $rotator->rotateIfNeeded($logPath);

            $backup = $logPath . '.1.gz';
            if (file_exists($backup)) {
                $content = file_get_contents($backup);
                $decoded = @gzdecode($content);
                $this->assertNotFalse($decoded, 'File with .gz extension must be valid gzip');
            }
        } finally {
            $files = glob($testDir . '/*') ?: [];
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($testDir);
        }
    }

    // ========== I: Fingerprint stability ==========

    public function testFingerprintStableWithDifferentKeyOrder(): void
    {
        $fp = new ResponseFingerprinter(true);
        $data1 = ['jsonrpc' => '2.0', 'result' => 'hello', 'id' => 1];
        $data2 = ['id' => 1, 'result' => 'hello', 'jsonrpc' => '2.0'];
        $this->assertEquals($fp->fingerprint($data1), $fp->fingerprint($data2));
    }

    public function testFingerprintStableWithNestedDifferentKeyOrder(): void
    {
        $fp = new ResponseFingerprinter(true);
        $data1 = ['result' => ['a' => 1, 'b' => 2], 'id' => 1, 'jsonrpc' => '2.0'];
        $data2 = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['b' => 2, 'a' => 1]];
        $this->assertEquals($fp->fingerprint($data1), $fp->fingerprint($data2));
    }

    // ========== J: system.version single source of truth ==========

    public function testSystemVersionReadsFromConfig(): void
    {
        $server = $this->createServer([
            'server' => ['version' => '2.5.3'],
        ]);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.version', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals('2.5.3', $data['result']['version']);
    }

    public function testSystemVersionDefaultMatchesConfig(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.version', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals('1.0.0', $data['result']['version']);
    }

    // ========== K: Config validation ==========

    public function testInvalidJwtAlgorithmThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported JWT algorithm');
        $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'RS256'],
                'protected_methods' => ['user.'],
            ],
        ]);
    }

    public function testNegativeLeewayThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('leeway');
        $this->createServer([
            'auth' => [
                'enabled' => true,
                'jwt' => ['secret' => 'test-secret', 'algorithm' => 'HS256', 'leeway' => -1],
                'protected_methods' => ['user.'],
            ],
        ]);
    }

    public function testZeroBatchMaxItemsThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('batch.max_items');
        $this->createServer([
            'batch' => ['max_items' => 0],
        ]);
    }

    public function testEmptyRateLimitStoragePathThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rate_limit.storage_path');
        $this->createServer([
            'rate_limit' => [
                'enabled' => true,
                'storage_path' => '',
                'max_requests' => 100,
                'window_seconds' => 60,
            ],
        ]);
    }

    public function testZeroRateLimitMaxRequestsThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rate_limit.max_requests');
        $this->createServer([
            'rate_limit' => [
                'enabled' => true,
                'storage_path' => sys_get_temp_dir() . '/rl_test',
                'max_requests' => 0,
                'window_seconds' => 60,
            ],
        ]);
    }

    // ========== Spec compliance edge cases ==========

    public function testNotificationReturnsNoContentNotError(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health']);
        $response = $server->handle($this->createRequest($body));
        $this->assertEquals(204, $response->statusCode);
        $this->assertEquals('', $response->body);
    }

    public function testBatchAllNotificationsReturnsNoContent(): void
    {
        $server = $this->createServer();
        $body = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'system.health'],
            ['jsonrpc' => '2.0', 'method' => 'system.version'],
        ]);
        $response = $server->handle($this->createRequest($body));
        $this->assertEquals(204, $response->statusCode);
    }

    public function testResponseResultAndErrorMutuallyExclusive(): void
    {
        $server = $this->createServer();
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'system.health', 'id' => 1]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertTrue(
            (array_key_exists('result', $data) && !array_key_exists('error', $data)) ||
            (!array_key_exists('result', $data) && array_key_exists('error', $data))
        );
    }
}
