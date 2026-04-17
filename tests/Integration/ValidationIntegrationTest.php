<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use Lumen\JsonRpc\Server\JsonRpcServer;
use Lumen\JsonRpc\Tests\Fixtures\ValidatedHandler;
use Lumen\JsonRpc\Validation\RpcSchemaProviderInterface;
use PHPUnit\Framework\TestCase;

final class ValidationIntegrationTest extends TestCase
{
    use IntegrationTestCase;

    private string $validatedHandlerPath;

    protected function setUp(): void
    {
        $this->initHandlerPath();
        $this->validatedHandlerPath = realpath(__DIR__ . '/../../tests/Fixtures') ?: __DIR__ . '/../../tests/Fixtures';
    }

    public function testSchemaValidationNotActiveByDefault(): void
    {
        $server = $this->createServer();
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'system.health',
            'id' => 1,
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals('ok', $data['result']['status']);
    }

    public function testSchemaValidationEnabledWithValidData(): void
    {
        $handlerPath = $this->validatedHandlerPath;
        $server = $this->createServer([
            'validation' => [
                'strict' => true,
                'schema' => ['enabled' => true],
            ],
            'handlers' => [
                'paths' => [$handlerPath],
                'namespace' => 'Lumen\\JsonRpc\\Tests\\Fixtures\\',
            ],
        ]);

        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'validatedhandler.create',
            'id' => 1,
            'params' => ['email' => 'test@example.com', 'roles' => ['admin']],
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertArrayHasKey('result', $data);
        $this->assertEquals('created', $data['result']['status']);
    }

    public function testSchemaValidationRejectsMissingRequired(): void
    {
        $handlerPath = $this->validatedHandlerPath;
        $server = $this->createServer([
            'validation' => [
                'strict' => true,
                'schema' => ['enabled' => true],
            ],
            'handlers' => [
                'paths' => [$handlerPath],
                'namespace' => 'Lumen\\JsonRpc\\Tests\\Fixtures\\',
            ],
        ]);

        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'validatedhandler.create',
            'id' => 1,
            'params' => ['email' => 'test@example.com'],
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32602, $data['error']['code']);
    }

    public function testSchemaValidationRejectsInvalidType(): void
    {
        $handlerPath = $this->validatedHandlerPath;
        $server = $this->createServer([
            'validation' => [
                'strict' => true,
                'schema' => ['enabled' => true],
            ],
            'handlers' => [
                'paths' => [$handlerPath],
                'namespace' => 'Lumen\\JsonRpc\\Tests\\Fixtures\\',
            ],
        ]);

        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'validatedhandler.create',
            'id' => 1,
            'params' => ['email' => 123, 'roles' => ['admin']],
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32602, $data['error']['code']);
    }

    public function testSchemaValidationRejectsAdditionalProperties(): void
    {
        $handlerPath = $this->validatedHandlerPath;
        $server = $this->createServer([
            'validation' => [
                'strict' => true,
                'schema' => ['enabled' => true],
            ],
            'handlers' => [
                'paths' => [$handlerPath],
                'namespace' => 'Lumen\\JsonRpc\\Tests\\Fixtures\\',
            ],
        ]);

        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'validatedhandler.create',
            'id' => 1,
            'params' => ['email' => 'test@example.com', 'roles' => ['admin'], 'extra' => 'value'],
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);
        $this->assertEquals(-32602, $data['error']['code']);
    }
}
