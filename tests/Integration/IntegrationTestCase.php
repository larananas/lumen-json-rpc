<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Config\Config;
use Lumen\JsonRpc\Http\HttpRequest;
use Lumen\JsonRpc\Server\JsonRpcServer;

trait IntegrationTestCase
{
    private string $handlerPath;

    private function initHandlerPath(): void
    {
        $this->handlerPath = realpath(__DIR__ . '/../../examples/handlers') ?: __DIR__ . '/../../examples/handlers';
    }

    private function createServer(array $overrides = []): JsonRpcServer
    {
        $defaults = [
            'handlers' => [
                'paths' => [$this->handlerPath],
                'namespace' => 'App\\Handlers\\',
            ],
            'debug' => true,
            'logging' => ['enabled' => false],
            'auth' => ['enabled' => false],
            'rate_limit' => ['enabled' => false],
            'response_fingerprint' => ['enabled' => false],
            'compression' => ['response_gzip' => false],
        ];
        $config = new Config(array_merge($defaults, $overrides));
        return new JsonRpcServer($config);
    }

    private function createRequest(string $body, string $method = 'POST', array $headers = []): HttpRequest
    {
        return new HttpRequest(
            body: $body,
            headers: array_merge(['Content-Type' => 'application/json'], $headers),
            method: $method,
            clientIp: '127.0.0.1',
            server: [],
        );
    }

    private function createJwt(array $payload, string $secret = 'test-secret', string $algorithm = 'HS256'): string
    {
        $b64Encode = fn(string $data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $algoMap = ['HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512'];
        $header = $b64Encode(json_encode(['typ' => 'JWT', 'alg' => $algorithm]));
        $payloadB64 = $b64Encode(json_encode($payload));
        $sig = $b64Encode(hash_hmac($algoMap[$algorithm], "$header.$payloadB64", $secret, true));
        return "$header.$payloadB64.$sig";
    }
}
