<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Doc;

use Lumen\JsonRpc\Doc\MethodDoc;
use Lumen\JsonRpc\Doc\OpenRpcGenerator;
use PHPUnit\Framework\TestCase;

final class OpenRpcSpecValidationTest extends TestCase
{
    private OpenRpcGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new OpenRpcGenerator();
    }

    public function testOutputIsValidJsonRpcOpenRpcDocument(): void
    {
        $output = $this->generator->generate([], 'Test API', '1.0.0');
        $spec = json_decode($output, true);

        $this->assertNotNull($spec, 'Output must be valid JSON');
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function testOpenRpcVersionField(): void
    {
        $output = $this->generator->generate([]);
        $spec = json_decode($output, true);

        $this->assertArrayHasKey('openrpc', $spec);
        $this->assertSame('1.3.2', $spec['openrpc']);
    }

    public function testInfoBlockSchema(): void
    {
        $output = $this->generator->generate([], 'My API', '2.1.0', 'An API desc');
        $spec = json_decode($output, true);

        $this->assertArrayHasKey('info', $spec);
        $this->assertArrayHasKey('title', $spec['info']);
        $this->assertArrayHasKey('version', $spec['info']);
        $this->assertSame('My API', $spec['info']['title']);
        $this->assertSame('2.1.0', $spec['info']['version']);
        $this->assertSame('An API desc', $spec['info']['description']);
    }

    public function testServersArraySchema(): void
    {
        $output = $this->generator->generate([]);
        $spec = json_decode($output, true);

        $this->assertArrayHasKey('servers', $spec);
        $this->assertIsArray($spec['servers']);
        $this->assertNotEmpty($spec['servers']);

        foreach ($spec['servers'] as $server) {
            $this->assertArrayHasKey('name', $server, 'Each server must have a name');
            $this->assertArrayHasKey('url', $server, 'Each server must have a url');
            $this->assertIsString($server['name']);
            $this->assertIsString($server['url']);
        }
    }

    public function testMethodsArraySchema(): void
    {
        $output = $this->generator->generate([]);
        $spec = json_decode($output, true);

        $this->assertArrayHasKey('methods', $spec);
        $this->assertIsArray($spec['methods']);
    }

    public function testMethodSchemaWithAllFieldTypes(): void
    {
        $docs = [
            new MethodDoc(
                name: 'user.create',
                description: 'Create a new user',
                params: [
                    'name' => ['type' => 'string', 'description' => 'User name', 'required' => true, 'default' => null],
                    'email' => ['type' => 'string', 'description' => 'Email', 'required' => true, 'default' => null],
                    'role' => ['type' => 'string', 'description' => 'Role', 'required' => false, 'default' => 'user'],
                    'age' => ['type' => 'int', 'description' => 'Age', 'required' => false, 'default' => null],
                    'active' => ['type' => 'bool', 'description' => 'Active', 'required' => false, 'default' => true],
                    'tags' => ['type' => 'array', 'description' => 'Tags', 'required' => false, 'default' => []],
                    'metadata' => ['type' => '?string', 'description' => 'Optional metadata', 'required' => false, 'default' => null],
                ],
                returnType: 'array',
                returnDescription: 'Created user object',
                requiresAuth: true,
                errors: [
                    ['code' => -32602, 'message' => 'Invalid params', 'description' => 'Parameters failed validation'],
                    ['code' => -32001, 'message' => 'Auth required', 'description' => 'Authentication required'],
                ],
                exampleRequest: null,
                exampleResponse: null,
            ),
        ];

        $output = $this->generator->generate($docs, 'Test', '1.0.0');
        $spec = json_decode($output, true);
        $method = $spec['methods'][0];

        $this->assertSame('user.create', $method['name']);
        $this->assertSame('Create a new user', $method['description']);

        $this->assertArrayHasKey('params', $method);
        $this->assertCount(7, $method['params']);

        foreach ($method['params'] as $param) {
            $this->assertArrayHasKey('name', $param);
            $this->assertArrayHasKey('schema', $param);
            $this->assertArrayHasKey('required', $param);
            $this->assertIsString($param['name']);
            $this->assertIsBool($param['required']);
        }

        $nameParam = $method['params'][0];
        $this->assertTrue($nameParam['required']);
        $this->assertSame('string', $nameParam['schema']['type']);

        $roleParam = $method['params'][2];
        $this->assertFalse($roleParam['required']);
        $this->assertArrayHasKey('default', $roleParam['schema']);
        $this->assertSame('user', $roleParam['schema']['default']);

        $nullableParam = $method['params'][6];
        $this->assertArrayHasKey('oneOf', $nullableParam['schema']);

        $this->assertArrayHasKey('result', $method);
        $this->assertSame('result', $method['result']['name']);
        $this->assertArrayHasKey('schema', $method['result']);
        $this->assertSame('Created user object', $method['result']['description']);

        $this->assertTrue($method['x-requiresAuth']);

        $this->assertArrayHasKey('errors', $method);
        $this->assertCount(2, $method['errors']);
        foreach ($method['errors'] as $error) {
            $this->assertArrayHasKey('code', $error);
            $this->assertArrayHasKey('message', $error);
            $this->assertIsInt($error['code']);
        }
    }

    public function testMethodWithoutErrorsHasNoErrorsKey(): void
    {
        $docs = [new MethodDoc(name: 'ping')];
        $output = $this->generator->generate($docs);
        $spec = json_decode($output, true);

        $this->assertArrayNotHasKey('errors', $spec['methods'][0]);
    }

    public function testMethodWithoutAuthHasNoExtension(): void
    {
        $docs = [new MethodDoc(name: 'system.health', requiresAuth: false)];
        $output = $this->generator->generate($docs);
        $spec = json_decode($output, true);

        $this->assertArrayNotHasKey('x-requiresAuth', $spec['methods'][0]);
    }

    public function testEmptyDescriptionIsValid(): void
    {
        $docs = [new MethodDoc(name: 'minimal', description: '')];
        $output = $this->generator->generate($docs);
        $spec = json_decode($output, true);

        $this->assertSame('', $spec['methods'][0]['description']);
    }

    public function testNoExtraTopLevelKeys(): void
    {
        $output = $this->generator->generate([]);
        $spec = json_decode($output, true);

        $allowedKeys = ['openrpc', 'info', 'servers', 'methods'];
        foreach (array_keys($spec) as $key) {
            $this->assertContains($key, $allowedKeys, "Unexpected top-level key: {$key}");
        }
    }

    public function testDeterministicOutputIsByteIdentical(): void
    {
        $docs = [
            new MethodDoc(
                name: 'test.method',
                description: 'Test',
                params: ['id' => ['type' => 'int', 'description' => 'ID', 'required' => true, 'default' => null]],
                returnType: 'array',
                requiresAuth: true,
                errors: [['code' => -32001, 'description' => 'Auth required']],
            ),
            new MethodDoc(
                name: 'test.other',
                description: 'Other',
                params: [],
                returnType: 'void',
                requiresAuth: false,
            ),
        ];

        $first = $this->generator->generate($docs, 'API', '1.0.0', 'Desc');
        $second = $this->generator->generate($docs, 'API', '1.0.0', 'Desc');

        $this->assertSame($first, $second, 'OpenRPC output must be deterministic');
    }

    public function testMultipleMethodsPreserveOrder(): void
    {
        $docs = [
            new MethodDoc(name: 'alpha.method'),
            new MethodDoc(name: 'beta.method'),
            new MethodDoc(name: 'gamma.method'),
        ];

        $output = $this->generator->generate($docs);
        $spec = json_decode($output, true);

        $this->assertSame('alpha.method', $spec['methods'][0]['name']);
        $this->assertSame('beta.method', $spec['methods'][1]['name']);
        $this->assertSame('gamma.method', $spec['methods'][2]['name']);
    }

    public function testAllPhpTypesMapToCorrectJsonSchema(): void
    {
        $typeMap = [
            'int' => ['type' => 'integer'],
            'integer' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'double' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'boolean' => ['type' => 'boolean'],
            'string' => ['type' => 'string'],
            'array' => ['type' => 'array', 'items' => ['description' => 'Any value']],
            'void' => ['type' => 'null'],
            'null' => ['type' => 'null'],
            'mixed' => ['description' => 'Any value'],
        ];

        foreach ($typeMap as $phpType => $expectedSchema) {
            $docs = [new MethodDoc(
                name: "test.{$phpType}",
                params: ['val' => ['type' => $phpType, 'description' => '', 'required' => true, 'default' => null]],
                returnType: $phpType,
            )];

            $output = $this->generator->generate($docs);
            $spec = json_decode($output, true);

            $paramSchema = $spec['methods'][0]['params'][0]['schema'];
            $resultSchema = $spec['methods'][0]['result']['schema'];

            $this->assertEquals($expectedSchema, $paramSchema, "PHP type '{$phpType}' param schema mismatch");
            $this->assertEquals($expectedSchema, $resultSchema, "PHP type '{$phpType}' result schema mismatch");
        }
    }

    public function testNullableTypesProduceOneOf(): void
    {
        $nullableTypes = ['?string', '?int', '?float', '?bool', '?array'];

        foreach ($nullableTypes as $type) {
            $docs = [new MethodDoc(
                name: 'test.nullable',
                params: ['val' => ['type' => $type, 'description' => '', 'required' => false, 'default' => null]],
                returnType: $type,
            )];

            $output = $this->generator->generate($docs);
            $spec = json_decode($output, true);

            $paramSchema = $spec['methods'][0]['params'][0]['schema'];
            $resultSchema = $spec['methods'][0]['result']['schema'];

            $this->assertArrayHasKey('oneOf', $paramSchema, "Nullable type '{$type}' must produce oneOf in param");
            $this->assertCount(2, $paramSchema['oneOf']);
            $this->assertArrayHasKey('oneOf', $resultSchema, "Nullable type '{$type}' must produce oneOf in result");
        }
    }

    public function testErrorCodeAlwaysInteger(): void
    {
        $docs = [new MethodDoc(
            name: 'test',
            errors: [
                ['code' => '-32600', 'description' => 'Invalid'],
                ['code' => '-32001', 'message' => 'Auth', 'description' => 'Auth needed'],
                ['code' => 42, 'description' => 'Custom'],
            ],
        )];

        $output = $this->generator->generate($docs);
        $spec = json_decode($output, true);

        $errors = $spec['methods'][0]['errors'];
        $this->assertCount(3, $errors);
        foreach ($errors as $error) {
            $this->assertIsInt($error['code']);
        }

        $this->assertSame(-32600, $errors[0]['code']);
        $this->assertSame(-32001, $errors[1]['code']);
        $this->assertSame(42, $errors[2]['code']);
    }
}
