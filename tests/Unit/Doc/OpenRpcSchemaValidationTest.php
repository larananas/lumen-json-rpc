<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Doc;

use Lumen\JsonRpc\Doc\MethodDoc;
use Lumen\JsonRpc\Doc\OpenRpcGenerator;
use PHPUnit\Framework\TestCase;

final class OpenRpcSchemaValidationTest extends TestCase
{
    private static ?array $schemaDef = null;

    public static function setUpBeforeClass(): void
    {
        $pinnedPath = dirname(__DIR__, 2) . '/Fixtures/openrpc-schema-1.3.2.json';

        if (!file_exists($pinnedPath)) {
            return;
        }

        $json = file_get_contents($pinnedPath);
        if ($json === false) {
            return;
        }

        self::$schemaDef = json_decode($json, true);
    }

    public function testPinnedSchemaFileIsValid(): void
    {
        $pinnedPath = dirname(__DIR__, 2) . '/Fixtures/openrpc-schema-1.3.2.json';
        $this->assertFileExists($pinnedPath, 'Pinned OpenRPC schema fixture must exist');

        $decoded = json_decode(file_get_contents($pinnedPath), true);
        $this->assertNotNull($decoded, 'Pinned schema must be valid JSON');
        $this->assertSame('openrpcDocument', $decoded['title'] ?? null);
        $this->assertContains('1.3.2', $decoded['properties']['openrpc']['enum'] ?? []);
        $this->assertArrayHasKey('definitions', $decoded);
        $this->assertArrayHasKey('methodObject', $decoded['definitions']);
        $this->assertArrayHasKey('errorObject', $decoded['definitions']);
        $this->assertArrayHasKey('contentDescriptorObject', $decoded['definitions']);
        $this->assertArrayHasKey('tagObject', $decoded['definitions']);
    }

    public function testOutputConformsToPinnedOpenRpcSchemaStructuralRules(): void
    {
        if (self::$schemaDef === null) {
            $this->markTestSkipped('OpenRPC pinned schema fixture missing.');
        }

        $schema = self::$schemaDef;
        $generator = new OpenRpcGenerator();
        $docs = $this->createComprehensiveDocs();
        $output = $generator->generate($docs, 'Test API', '1.0.0', 'A test API');
        $spec = json_decode($output, true);

        $topLevelProps = array_keys($schema['properties'] ?? []);
        $topLevelAllowed = array_merge(
            $topLevelProps,
            ['$schema'],
            ['components'],
        );

        foreach (array_keys($spec) as $key) {
            if (!in_array($key, $topLevelAllowed, true)) {
                $this->fail("Unexpected top-level key: {$key}");
            }
        }

        $required = $schema['required'] ?? [];
        foreach ($required as $field) {
            $this->assertArrayHasKey($field, $spec, "Missing required top-level field: {$field}");
        }

        $this->assertSame('1.3.2', $spec['openrpc']);
        $this->assertTrue(in_array($spec['openrpc'], $schema['properties']['openrpc']['enum'], true));

        $this->validateInfoBlock($spec['info'] ?? [], $schema['definitions']['infoObject'] ?? []);
        $this->validateServers($spec['servers'] ?? [], $schema['definitions']['serverObject'] ?? []);

        foreach ($spec['methods'] as $i => $method) {
            $this->validateMethod(
                $method,
                $schema['definitions']['methodObject'] ?? [],
                "methods[{$i}]",
            );
        }
    }

    public function testMinimalOutputConformsToStructuralRules(): void
    {
        if (self::$schemaDef === null) {
            $this->markTestSkipped('OpenRPC pinned schema fixture missing.');
        }

        $generator = new OpenRpcGenerator();
        $output = $generator->generate([], 'Minimal API', '0.1.0');
        $spec = json_decode($output, true);

        $this->assertSame('1.3.2', $spec['openrpc']);
        $this->assertArrayHasKey('info', $spec);
        $this->assertArrayHasKey('methods', $spec);
        $this->assertSame('Minimal API', $spec['info']['title']);
        $this->assertSame('0.1.0', $spec['info']['version']);
        $this->assertSame([], $spec['methods']);
    }

    /**
     * @param array<string, mixed> $info
     * @param array<string, mixed> $infoSchema
     */
    private function validateInfoBlock(array $info, array $infoSchema): void
    {
        $required = $infoSchema['required'] ?? [];
        foreach ($required as $field) {
            $this->assertArrayHasKey($field, $info, "info.{$field} is required by schema");
        }

        $allowedProps = array_keys($infoSchema['properties'] ?? []);
        foreach (array_keys($info) as $key) {
            if (!in_array($key, $allowedProps, true)) {
                $this->fail("info has unexpected property: {$key}");
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $servers
     * @param array<string, mixed> $serverSchema
     */
    private function validateServers(array $servers, array $serverSchema): void
    {
        $required = $serverSchema['required'] ?? [];
        $allowedProps = array_keys($serverSchema['properties'] ?? []);

        foreach ($servers as $i => $server) {
            foreach ($required as $field) {
                $this->assertArrayHasKey($field, $server, "servers[{$i}].{$field} is required");
            }
            foreach (array_keys($server) as $key) {
                if (!in_array($key, $allowedProps, true)) {
                    $this->fail("servers[{$i}] has unexpected property: {$key}");
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $method
     * @param array<string, mixed> $methodSchema
     */
    private function validateMethod(array $method, array $methodSchema, string $path): void
    {
        $required = $methodSchema['required'] ?? [];
        foreach ($required as $field) {
            $this->assertArrayHasKey($field, $method, "{$path}.{$field} is required by schema");
        }

        $allowedProps = array_keys($methodSchema['properties'] ?? []);
        foreach (array_keys($method) as $key) {
            $isAllowed = in_array($key, $allowedProps, true) || str_starts_with($key, 'x-');
            $this->assertTrue($isAllowed, "{$path} has unexpected property: {$key}");
        }

        $this->assertIsString($method['name'] ?? null);
        $this->assertGreaterThan(0, strlen($method['name'] ?? ''), "{$path}.name must have minLength 1");
        $this->assertIsArray($method['params'] ?? null, "{$path}.params must be an array");

        foreach ($method['params'] as $j => $param) {
            $this->assertArrayHasKey('name', $param, "{$path}.params[{$j}].name is required");
            $this->assertArrayHasKey('schema', $param, "{$path}.params[{$j}].schema is required");
        }

        if (isset($method['result'])) {
            $this->assertArrayHasKey('name', $method['result'], "{$path}.result.name is required");
            $this->assertArrayHasKey('schema', $method['result'], "{$path}.result.schema is required");
        }

        if (isset($method['errors'])) {
            $errorSchema = self::$schemaDef['definitions']['errorObject'] ?? [];
            $errorRequired = $errorSchema['required'] ?? [];
            $errorAllowed = array_keys($errorSchema['properties'] ?? []);

            foreach ($method['errors'] as $k => $error) {
                foreach ($errorRequired as $field) {
                    $this->assertArrayHasKey($field, $error, "{$path}.errors[{$k}].{$field} is required");
                }
                foreach (array_keys($error) as $key) {
                    $this->assertTrue(
                        in_array($key, $errorAllowed, true),
                        "{$path}.errors[{$k}] has unexpected property: {$key}",
                    );
                }
                $this->assertIsInt($error['code']);
                $this->assertIsString($error['message']);
            }
        }

        if (isset($method['tags'])) {
            $tagSchema = self::$schemaDef['definitions']['tagObject'] ?? [];
            $tagRequired = $tagSchema['required'] ?? [];

            foreach ($method['tags'] as $t => $tag) {
                $this->assertIsArray($tag, "{$path}.tags[{$t}] must be a tag object");
                foreach ($tagRequired as $field) {
                    $this->assertArrayHasKey($field, $tag, "{$path}.tags[{$t}].{$field} is required");
                }
            }
        }
    }

    /**
     * @return array<int, MethodDoc>
     */
    private function createComprehensiveDocs(): array
    {
        return [
            new MethodDoc(
                name: 'user.create',
                description: 'Create a new user',
                params: [
                    'name' => ['type' => 'string', 'description' => 'User name', 'required' => true, 'default' => null],
                    'email' => ['type' => 'string', 'description' => 'Email', 'required' => true, 'default' => null],
                    'role' => ['type' => 'string', 'description' => 'Role', 'required' => false, 'default' => 'user'],
                ],
                returnType: 'array',
                returnDescription: 'Created user object',
                requiresAuth: true,
                errors: [
                    ['code' => -32602, 'message' => 'Invalid params', 'description' => 'Parameters failed validation'],
                    ['code' => -32001, 'message' => 'Auth required', 'description' => 'Authentication required'],
                ],
            ),
            new MethodDoc(
                name: 'system.health',
                description: 'Health check',
                params: [],
                returnType: 'object',
                returnDescription: 'Health status',
                requiresAuth: false,
            ),
        ];
    }
}
