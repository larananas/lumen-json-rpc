<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Doc;

use Lumen\JsonRpc\Doc\MethodDoc;
use Lumen\JsonRpc\Doc\OpenRpcGenerator;
use PHPUnit\Framework\TestCase;

final class OpenRpcStructuralValidationTest extends TestCase
{
    private OpenRpcGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new OpenRpcGenerator();
    }

    public function testTopLevelRequiredFields(): void
    {
        $json = $this->generator->generate([], 'Test API', '1.0.0', 'Test desc');
        $spec = json_decode($json, true);

        $this->assertArrayHasKey('openrpc', $spec);
        $this->assertArrayHasKey('info', $spec);
        $this->assertArrayHasKey('methods', $spec);
        $this->assertEquals('1.3.2', $spec['openrpc']);
    }

    public function testInfoBlockRequiredFields(): void
    {
        $json = $this->generator->generate([], 'Test API', '2.0.0', 'A description');
        $spec = json_decode($json, true);

        $this->assertArrayHasKey('title', $spec['info']);
        $this->assertArrayHasKey('version', $spec['info']);
        $this->assertEquals('Test API', $spec['info']['title']);
        $this->assertEquals('2.0.0', $spec['info']['version']);
        $this->assertEquals('A description', $spec['info']['description']);
    }

    public function testServersEntryIsValid(): void
    {
        $json = $this->generator->generate([]);
        $spec = json_decode($json, true);

        $this->assertArrayHasKey('servers', $spec);
        $this->assertIsArray($spec['servers']);
        $this->assertNotEmpty($spec['servers']);
        $this->assertArrayHasKey('name', $spec['servers'][0]);
        $this->assertArrayHasKey('url', $spec['servers'][0]);
    }

    public function testMethodHasRequiredNameField(): void
    {
        $docs = [
            new MethodDoc(
                name: 'user.get',
                description: 'Get user',
                params: [],
                returnType: 'array',
                returnDescription: '',
                requiresAuth: false,
                errors: [],
                exampleRequest: null,
                exampleResponse: null,
            ),
        ];
        $json = $this->generator->generate($docs);
        $spec = json_decode($json, true);

        $this->assertCount(1, $spec['methods']);
        $this->assertEquals('user.get', $spec['methods'][0]['name']);
    }

    public function testMethodParamsHaveRequiredFields(): void
    {
        $docs = [
            new MethodDoc(
                name: 'user.create',
                description: 'Create user',
                params: [
                    'name' => ['type' => 'string', 'description' => 'User name', 'required' => true, 'default' => null],
                    'role' => ['type' => 'string', 'description' => 'User role', 'required' => false, 'default' => 'user'],
                ],
                returnType: 'array',
                returnDescription: '',
                requiresAuth: false,
                errors: [],
                exampleRequest: null,
                exampleResponse: null,
            ),
        ];
        $json = $this->generator->generate($docs);
        $spec = json_decode($json, true);

        $params = $spec['methods'][0]['params'];
        $this->assertCount(2, $params);

        foreach ($params as $param) {
            $this->assertArrayHasKey('name', $param);
            $this->assertArrayHasKey('schema', $param);
        }

        $this->assertTrue($params[0]['required']);
        $this->assertFalse($params[1]['required']);
    }

    public function testMethodResultHasRequiredFields(): void
    {
        $docs = [
            new MethodDoc(
                name: 'test.method',
                description: 'Test',
                params: [],
                returnType: 'string',
                returnDescription: 'The result string',
                requiresAuth: false,
                errors: [],
                exampleRequest: null,
                exampleResponse: null,
            ),
        ];
        $json = $this->generator->generate($docs);
        $spec = json_decode($json, true);

        $result = $spec['methods'][0]['result'];
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('schema', $result);
        $this->assertEquals('result', $result['name']);
    }

    public function testErrorObjectsHaveRequiredCodeMessageDescription(): void
    {
        $docs = [
            new MethodDoc(
                name: 'test.method',
                description: 'Test',
                params: [],
                returnType: 'array',
                returnDescription: '',
                requiresAuth: false,
                errors: [
                    ['code' => '32001', 'description' => 'Auth required'],
                    ['code' => '32002', 'message' => 'Rate limited', 'description' => 'Too many requests'],
                ],
                exampleRequest: null,
                exampleResponse: null,
            ),
        ];
        $json = $this->generator->generate($docs);
        $spec = json_decode($json, true);

        $errors = $spec['methods'][0]['errors'];
        $this->assertCount(2, $errors);

        foreach ($errors as $error) {
            $this->assertArrayHasKey('code', $error);
            $this->assertArrayHasKey('message', $error);
            $this->assertIsInt($error['code']);
        }

        $this->assertEquals(32001, $errors[0]['code']);
        $this->assertEquals('Auth required', $errors[0]['message']);
        $this->assertEquals('Rate limited', $errors[1]['message']);
    }

    public function testAuthExtensionIsBoolean(): void
    {
        $docs = [
            new MethodDoc(
                name: 'user.delete',
                description: 'Delete a user',
                params: [],
                returnType: 'bool',
                returnDescription: '',
                requiresAuth: true,
                errors: [],
                exampleRequest: null,
                exampleResponse: null,
            ),
        ];
        $json = $this->generator->generate($docs);
        $spec = json_decode($json, true);

        $this->assertArrayHasKey('x-requiresAuth', $spec['methods'][0]);
        $this->assertTrue($spec['methods'][0]['x-requiresAuth']);
    }

    public function testAuthExtensionAbsentWhenFalse(): void
    {
        $docs = [
            new MethodDoc(
                name: 'system.health',
                description: 'Health check',
                params: [],
                returnType: 'array',
                returnDescription: '',
                requiresAuth: false,
                errors: [],
                exampleRequest: null,
                exampleResponse: null,
            ),
        ];
        $json = $this->generator->generate($docs);
        $spec = json_decode($json, true);

        $this->assertArrayNotHasKey('x-requiresAuth', $spec['methods'][0]);
    }

    public function testOutputIsDeterministicAcrossMultipleCalls(): void
    {
        $docs = [
            new MethodDoc(
                name: 'user.get',
                description: 'Get user',
                params: [
                    'id' => ['type' => 'int', 'description' => 'User ID', 'required' => true, 'default' => null],
                ],
                returnType: 'array',
                returnDescription: 'User object',
                requiresAuth: true,
                errors: [
                    ['code' => '32001', 'description' => 'Not found'],
                ],
                exampleRequest: null,
                exampleResponse: null,
            ),
            new MethodDoc(
                name: 'system.health',
                description: 'Health check',
                params: [],
                returnType: 'array',
                returnDescription: '',
                requiresAuth: false,
                errors: [],
                exampleRequest: null,
                exampleResponse: null,
            ),
        ];

        $first = $this->generator->generate($docs, 'Test', '1.0.0', 'Desc');
        $second = $this->generator->generate($docs, 'Test', '1.0.0', 'Desc');

        $this->assertSame($first, $second);
    }

    public function testSchemaTypesForAllPhpTypes(): void
    {
        $docs = [
            new MethodDoc(
                name: 'test.types',
                description: 'Test all types',
                params: [
                    'a' => ['type' => 'int', 'description' => '', 'required' => true, 'default' => null],
                    'b' => ['type' => 'float', 'description' => '', 'required' => true, 'default' => null],
                    'c' => ['type' => 'bool', 'description' => '', 'required' => true, 'default' => null],
                    'd' => ['type' => 'string', 'description' => '', 'required' => true, 'default' => null],
                    'e' => ['type' => 'array', 'description' => '', 'required' => true, 'default' => null],
                    'f' => ['type' => 'mixed', 'description' => '', 'required' => false, 'default' => null],
                ],
                returnType: 'void',
                returnDescription: '',
                requiresAuth: false,
                errors: [],
                exampleRequest: null,
                exampleResponse: null,
            ),
        ];
        $json = $this->generator->generate($docs);
        $spec = json_decode($json, true);

        $params = $spec['methods'][0]['params'];
        $this->assertEquals('integer', $params[0]['schema']['type']);
        $this->assertEquals('number', $params[1]['schema']['type']);
        $this->assertEquals('boolean', $params[2]['schema']['type']);
        $this->assertEquals('string', $params[3]['schema']['type']);
        $this->assertEquals('array', $params[4]['schema']['type']);
        $this->assertArrayNotHasKey('type', $params[5]['schema']);
        $this->assertArrayHasKey('description', $params[5]['schema']);

        $result = $spec['methods'][0]['result'];
        $this->assertEquals('null', $result['schema']['type']);
    }

    public function testNullableTypeProducesOneOfSchema(): void
    {
        $docs = [
            new MethodDoc(
                name: 'test.nullable',
                description: 'Test nullable',
                params: [
                    'val' => ['type' => '?string', 'description' => '', 'required' => false, 'default' => null],
                ],
                returnType: '?int',
                returnDescription: '',
                requiresAuth: false,
                errors: [],
                exampleRequest: null,
                exampleResponse: null,
            ),
        ];
        $json = $this->generator->generate($docs);
        $spec = json_decode($json, true);

        $param = $spec['methods'][0]['params'][0];
        $this->assertArrayHasKey('oneOf', $param['schema']);
        $this->assertCount(2, $param['schema']['oneOf']);

        $result = $spec['methods'][0]['result'];
        $this->assertArrayHasKey('oneOf', $result['schema']);
    }

    public function testGenerateThrowsOnUnencodableData(): void
    {
        $this->expectException(\JsonException::class);

        $resource = fopen('php://memory', 'r');
        try {
            $spec = ['openrpc' => '1.3.2', 'info' => ['title' => 'Test', 'version' => '1.0.0'], 'methods' => [['bad' => $resource]]];
            json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } finally {
            fclose($resource);
        }
    }
}
