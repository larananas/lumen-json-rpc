<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class FileSchemaValidationTest extends TestCase
{
    use IntegrationTestCase;

    private string $schemaHandlerPath;

    protected function setUp(): void
    {
        $this->initHandlerPath();
        $this->schemaHandlerPath = realpath(__DIR__ . '/../Fixtures/handlers')
            ?: __DIR__ . '/../Fixtures/handlers';
    }

    private function createSchemaServer(): \Lumen\JsonRpc\Server\JsonRpcServer
    {
        return $this->createServer([
            'validation' => [
                'strict' => true,
                'schema' => ['enabled' => true],
            ],
            'handlers' => [
                'paths' => [$this->schemaHandlerPath],
                'namespace' => 'App\\Handlers\\SchemaTest\\',
            ],
        ]);
    }

    public function testSchemaValidationWithFileDiscoveredHandlerValidData(): void
    {
        $server = $this->createSchemaServer();

        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'userhandler.create',
            'id' => 1,
            'params' => ['name' => 'Alice', 'email' => 'alice@example.com'],
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);

        $this->assertArrayHasKey('result', $data);
        $this->assertTrue($data['result']['created']);
        $this->assertEquals('Alice', $data['result']['name']);
    }

    public function testSchemaValidationRejectsMissingRequiredField(): void
    {
        $server = $this->createSchemaServer();

        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'userhandler.create',
            'id' => 2,
            'params' => ['name' => 'Alice'],
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);

        $this->assertEquals(-32602, $data['error']['code']);
        $this->assertStringContainsString('email', implode(' ', $data['error']['data']['validation']));
    }

    public function testSchemaValidationRejectsInvalidType(): void
    {
        $server = $this->createSchemaServer();

        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'userhandler.create',
            'id' => 3,
            'params' => ['name' => 'Alice', 'email' => 12345],
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);

        $this->assertEquals(-32602, $data['error']['code']);
    }

    public function testSchemaValidationRejectsAdditionalProperties(): void
    {
        $server = $this->createSchemaServer();

        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'userhandler.create',
            'id' => 4,
            'params' => ['name' => 'Alice', 'email' => 'a@b.com', 'role' => 'admin'],
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);

        $this->assertEquals(-32602, $data['error']['code']);
        $this->assertStringContainsString('role', implode(' ', $data['error']['data']['validation']));
    }

    public function testSchemaValidationDoesNotAffectMethodWithoutSchema(): void
    {
        $server = $this->createSchemaServer();

        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'userhandler.ping',
            'id' => 5,
        ]);
        $response = $server->handle($this->createRequest($body));
        $data = json_decode($response->body, true);

        $this->assertArrayHasKey('result', $data);
        $this->assertTrue($data['result']['pong']);
    }
}
